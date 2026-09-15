import { expect, test as base } from '@playwright/test'

/** Compte créé par le jeu de données de développement. */
export const DEMO = {
  email: 'demo@saas.local',
  password: 'Password123!',
}

/**
 * Test outillé pour l'application.
 *
 * Chaque test démarre avec un profil vierge : l'écran d'entrée sonore
 * s'afficherait et masquerait toute l'interface. On répond « sans le son »
 * avant le premier rendu — un test n'a rien à faire d'un fond sonore, et
 * l'écran lui-même est couvert par son propre test.
 */
export const test = base.extend({
  page: async ({ page }, use) => {
    await page.addInitScript(() => {
      try {
        window.localStorage.setItem('sound', 'off')
      } catch {
        /* stockage indisponible : l'écran s'affichera, le test le dira */
      }
    })

    await use(page)
  },
})

export { expect }

/** Une seule tentative supplémentaire — voir le commentaire de login(). */
const LOGIN_ATTEMPTS = 2

/**
 * Ouvre une session via l'interface, pas par un raccourci API : le parcours
 * de connexion est lui-même ce que l'on veut voir fonctionner.
 *
 * UNE seule nouvelle tentative est accordée, et uniquement sur un DÉLAI
 * DÉPASSÉ côté client — jamais sur un refus d'identifiants, qui reste une
 * erreur franche.
 *
 * La raison n'est pas de masquer une lenteur de l'application : mesurée, la
 * connexion tient en 0,5 à 1,1 s (bcrypt au coût 12, volontairement). Mais la
 * suite complète enchaîne près de trente sessions sur un poste où le serveur
 * de développement recompile en même temps, et le client abandonne à 15 s.
 * Ouvrir la session est un PRÉALABLE à chaque test, jamais son objet : la
 * rejouer une fois ne rend donc aucun test complaisant, là où « retries » au
 * niveau de la suite masquerait de vraies régressions (cf. playwright.config).
 */
export async function login(page, credentials = DEMO) {
  for (let attempt = 1; attempt <= LOGIN_ATTEMPTS; attempt += 1) {
    await page.goto('/connexion')

    await page.getByLabel(/adresse e-mail/i).fill(credentials.email)
    await page.getByLabel(/mot de passe/i).fill(credentials.password)
    await page.getByRole('button', { name: /se connecter/i }).click()

    // Course entre l'arrivée sur le tableau de bord et le message d'erreur :
    // attendre l'un puis l'autre ferait patienter tout le délai d'expiration
    // avant de découvrir que la page affichait l'erreur depuis le début.
    const arrive = await Promise.race([
      page
        .waitForURL('/', { timeout: 20_000 })
        .then(() => true)
        .catch(() => false),
      page
        .getByRole('alert')
        .waitFor({ state: 'visible', timeout: 20_000 })
        .then(() => false)
        .catch(() => false),
    ])

    if (arrive) {
      await expect(page.getByRole('heading', { name: /bonjour/i })).toBeVisible()

      return
    }

    const message = (await page.getByRole('alert').textContent()) ?? ''

    // Un refus d'identifiants ne se rejoue pas : il dit quelque chose de vrai.
    if (!/trop de temps/i.test(message) || attempt === LOGIN_ATTEMPTS) {
      throw new Error(`Connexion impossible : ${message.trim() || 'aucun message'}`)
    }
  }
}

/** Mot de passe des comptes fabriqués par les tests — exigences de l'écran. */
export const MOTDEPASSE = 'Password123!'

const MAILPIT = process.env.MAILPIT_URL ?? 'http://localhost:8025'

/**
 * Récupère le lien d'invitation depuis la boîte de réception de développement.
 *
 * L'envoi passe par la file de tâches : le worker le remet au serveur SMTP
 * dans la seconde. On interroge donc en boucle courte plutôt que d'attendre
 * une durée fixe, qui serait soit trop longue, soit trop juste selon la
 * charge de la machine.
 */
export async function lienDInvitation(request, adresse) {
  for (let essai = 0; essai < 40; essai += 1) {
    const boite = await request.get(`${MAILPIT}/api/v1/search?query=to:${adresse}`)

    if (boite.ok()) {
      const { messages = [] } = await boite.json()

      if (messages.length > 0) {
        const message = await request.get(`${MAILPIT}/api/v1/message/${messages[0].ID}`)
        const corps = await message.json()

        const trouve = /https?:\/\/[^\s"'<>]*\/invitation\?token=[a-f0-9]{64}/.exec(
          `${corps.Text ?? ''} ${corps.HTML ?? ''}`,
        )

        if (trouve) return trouve[0]
      }
    }

    await new Promise((resolve) => setTimeout(resolve, 250))
  }

  throw new Error(`Aucun e-mail d'invitation reçu pour ${adresse}.`)
}

/**
 * Un VRAI coéquipier : un second compte, dans le même espace, par le parcours
 * complet d'invitation.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  DEUX FENÊTRES D'UN MÊME COMPTE NE SUFFISENT PAS                        │
 * │                                                                         │
 * │  La présence ne se montre pas à soi-même, et l'arbitrage de conflit ne  │
 * │  s'applique pas à ses propres écritures : les deux sont des décisions   │
 * │  délibérées. Un test qui se contenterait de deux onglets verrait donc   │
 * │  passer les deux mécanismes sans jamais les déclencher — et passerait   │
 * │  au vert en ne prouvant rien.                                           │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Rend { contexte, page, nom, effacer } — « effacer » supprime le compte,
 * faute de quoi chaque exécution laisserait un membre de plus dans l'espace
 * de démonstration.
 */
export async function coequipier(hote, browser, request, { creerContexte }) {
  const marque = Date.now() + Math.floor(Math.random() * 1000)
  const adresse = `equipier-${marque}@test.local`
  const nom = `Équipier ${marque}`

  await hote.goto('/equipe')
  await hote.getByLabel(/adresse e-mail/i).fill(adresse)
  await hote.getByRole('button', { name: /^inviter$/i }).click()
  await expect(hote.locator('li', { hasText: adresse })).toBeVisible()

  const lien = await lienDInvitation(request, adresse)

  const contexte = await creerContexte()
  const page = await contexte.newPage()

  await page.goto(lien)
  await page.getByRole('link', { name: /créer un compte/i }).click()
  await page.getByLabel(/nom complet/i).fill(nom)
  await page.getByLabel(/mot de passe/i).first().fill(MOTDEPASSE)
  await page.getByLabel(/confirmation/i).fill(MOTDEPASSE)
  await page.getByRole('checkbox').check()
  await page.getByRole('button', { name: /créer mon compte/i }).click()
  await page.waitForURL('/', { timeout: 20_000 })

  return {
    contexte,
    page,
    nom,
    async effacer() {
      await page.goto('/profil')
      await page.getByRole('button', { name: /supprimer mon compte/i }).click()
      await page.getByPlaceholder(/votre mot de passe/i).fill(MOTDEPASSE)
      await page.getByRole('button', { name: /supprimer définitivement/i }).click()
      await page.waitForURL(/connexion/, { timeout: 20_000 })
      await contexte.close()
    },
  }
}
