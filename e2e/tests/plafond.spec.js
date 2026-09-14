import { expect, login, MOTDEPASSE, test } from './support.js'

/**
 * Au-delà du plafond de chargement, la recherche atteint le reste.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  LE SEUL NIVEAU OÙ CETTE PROMESSE SE VÉRIFIE ENTIÈRE                    │
 * │                                                                         │
 * │  PHPUnit prouve que le serveur trouve un ticket au-delà de 500 ;        │
 * │  Vitest, que le composable interroge le serveur au bon moment. Aucun    │
 * │  des deux ne prouve que l'écran, avec sa liste plafonnée, son           │
 * │  avertissement et son filtrage local, affiche au bout du compte le      │
 * │  ticket qu'il n'avait pas chargé. C'est ce que fait ce parcours.        │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Un compte NEUF, et non le compte de démonstration : 505 tickets versés dans
 * l'espace partagé par toute la suite rendraient les autres parcours
 * méconnaissables.
 */

const API = process.env.E2E_API_URL ?? 'http://localhost:8080/api'

/** Le premier ticket créé est le plus ancien : trié du plus récent au plus ancien, il est hors des 500. */
const CIBLE = 'Au-delà du plafond'
const NOMBRE = 505
const PAR_LOT = 20

test.describe('plafond de chargement', () => {
  test('un ticket que l’écran n’a pas chargé se trouve par la recherche', async ({
    page,
    request,
  }) => {
    // Cinq cents créations par l'API, par lots : bien plus que le délai
    // ordinaire d'un parcours, sans rien dire de l'écran lui-même.
    test.setTimeout(240_000)

    const marque = Date.now()
    const compte = { email: `plafond-${marque}@test.local`, password: MOTDEPASSE }

    const inscription = await request.post(`${API}/auth/register`, {
      data: {
        full_name: `Plafond ${marque}`,
        email: compte.email,
        password: MOTDEPASSE,
        password_confirmation: MOTDEPASSE,
        terms_accepted: true,
      },
    })
    expect(inscription.status()).toBe(201)

    const { access_token: jeton, organization } = (await inscription.json()).data
    const autorisation = { Authorization: `Bearer ${jeton}` }

    const creer = async (titre) => {
      const reponse = await request.post(`${API}/tickets`, {
        data: { title: titre },
        headers: autorisation,
      })
      expect(reponse.status()).toBe(201)
    }

    try {
      await creer(CIBLE)

      for (let debut = 2; debut <= NOMBRE; debut += PAR_LOT) {
        const lot = []

        for (let n = debut; n < debut + PAR_LOT && n <= NOMBRE; n += 1) {
          lot.push(creer(`Ticket ordinaire ${n}`))
        }

        await Promise.all(lot)
      }

      await login(page, compte)
      await page.goto('/modules/tickets')
      await expect(page.getByRole('heading', { name: 'tickets' })).toBeVisible()

      // La prémisse, sans laquelle le reste ne prouverait rien : l'écran
      // annonce ce qu'il n'a pas chargé, et la cible en fait partie.
      await expect(
        page.getByRole('status').filter({ hasText: /tickets ne sont pas affichés/ }),
      ).toBeVisible()
      await expect(page.getByRole('option').filter({ hasText: CIBLE })).toHaveCount(0)

      await page.keyboard.press('/')
      await page.keyboard.type('plafond')

      // Trouvée par le SERVEUR : elle n'était pas dans la liste chargée.
      await expect(page.getByRole('option').filter({ hasText: CIBLE })).toBeVisible()
      await expect(page.getByRole('option')).toHaveCount(1)

      // Échap vide la recherche : la liste de départ revient, et la cible avec
      // elle repasse au-delà du plafond.
      await page.keyboard.press('Escape')
      await expect(page.getByRole('option').filter({ hasText: CIBLE })).toHaveCount(0)
    } finally {
      // Ménage. L'espace qui porte les 505 tickets ne peut être supprimé que
      // s'il en reste un autre : on en ouvre un, on revient sur le premier, et
      // on le supprime — ses tickets partent avec lui.
      //
      // L'espace de repli, lui, reste orphelin après la suppression du compte.
      // C'est le défaut L1 du plan (station C1) : supprimer un compte ne gère
      // pas encore les espaces qu'il laisse derrière lui. Le dire plutôt que
      // le contourner ici.
      await request.post(`${API}/organizations`, { data: { name: 'Repli' }, headers: autorisation })
      await request.post(`${API}/organizations/${organization.id}/activate`, { headers: autorisation })
      await request.delete(`${API}/organizations/${organization.id}`, { headers: autorisation })
      await request.delete(`${API}/profile`, { data: { password: MOTDEPASSE }, headers: autorisation })
    }
  })
})
