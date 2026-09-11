import { expect, login, test } from './support.js'

/**
 * L'historique, et le flux hors des tickets.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  UN MODULE SUR CINQ ÉTAIT LE PIRE DES ÉTATS                             │
 * │                                                                         │
 * │  Le tableau des tickets bougeait tout seul quand un coéquipier          │
 * │  travaillait ; l'écran des déploiements restait figé. Rien à l'écran    │
 * │  n'expliquait la différence.                                            │
 * │                                                                         │
 * │  Les tests d'API vérifient que les cinq modules CONSIGNENT. Ce fichier  │
 * │  vérifie que ça se VOIT — deux fenêtres, un module qui n'est pas celui  │
 * │  des tickets, et un écran qui répond enfin à « qui a fait ça ? ».       │
 * └─────────────────────────────────────────────────────────────────────────┘
 */

/** Le flux bat toutes les trois secondes ; on lui laisse deux battements. */
const PROPAGATION = 8000

test.describe('historique', () => {
  test("le fil répond à « qui a fait ça », et pas seulement « qu'est-ce qui existe »", async ({
    page,
  }) => {
    const titre = `Tracé ${Date.now()}`

    await login(page)

    // Un ticket créé PUIS modifié : c'est la modification qui compte ici.
    // L'ancien fil, assemblé par UNION sur les tables métier, ne pouvait
    // montrer que des créations — une table de données ne garde aucune trace
    // de ce qui l'a modifiée, ni de qui.
    await page.goto('/modules/tickets')
    await expect(page.getByRole('heading', { name: 'tickets' })).toBeVisible()

    await page.keyboard.press('c')
    await page.getByPlaceholder(/Entrée pour créer/i).fill(titre)
    await page.keyboard.press('Enter')
    await page.keyboard.press('Escape')

    await expect(page.getByRole('option').filter({ hasText: titre })).toBeVisible()
    await page.keyboard.press('1')

    await page.goto('/historique')
    await expect(page.getByRole('heading', { name: 'historique' })).toBeVisible()

    const premiere = page.getByRole('listitem').first()

    await expect(premiere).toContainText('Utilisateur Démo')
    await expect(premiere).toContainText('a modifié')
    await expect(premiere).toContainText('priorité')

    // Ménage.
    await page.goto('/modules/tickets')
    await page.getByRole('option').filter({ hasText: titre }).click()
    await page.keyboard.press('Backspace')
  })

  test('le filtre par module vit dans l’adresse', async ({ page }) => {
    await login(page)
    await page.goto('/historique')
    await expect(page.getByRole('heading', { name: 'historique' })).toBeVisible()

    await page.getByRole('button', { name: 'déploiement', exact: true }).click()
    await expect(page).toHaveURL(/module=deploiement/)

    // Rechargé — ou envoyé à quelqu'un — le lien rouvre le même écran filtré.
    await page.reload()
    await expect(page.getByRole('button', { name: 'déploiement', exact: true })).toHaveAttribute(
      'aria-pressed',
      'true',
    )

    // Et ce qui reste appartient bien au module demandé : le filtre n'est pas
    // qu'une pastille allumée.
    const lignes = page.getByRole('listitem')

    if (await lignes.count()) {
      await expect(lignes.first()).not.toContainText('TICK-')
    }
  })

  test('un déploiement créé ailleurs apparaît sans rechargement', async ({ page, browser }) => {
    const marque = `flux-${Date.now()}`

    await login(page)

    const contexte = await browser.newContext()

    await contexte.addInitScript(() => {
      try {
        window.localStorage.setItem('sound', 'off')
      } catch {
        /* stockage indisponible : l'écran s'affichera, le test le dira */
      }
    })

    const autre = await contexte.newPage()

    await login(autre)
    await autre.goto('/modules/deploiement')
    await expect(autre.getByRole('heading', { name: 'déploiement' })).toBeVisible()

    // La première fenêtre déclenche un déploiement.
    await page.goto('/modules/deploiement')
    await expect(page.getByRole('heading', { name: 'déploiement' })).toBeVisible()

    await page.getByRole('button', { name: 'déployer', exact: true }).click()
    await page.getByLabel('branche', { exact: true }).fill(marque)
    await page.getByLabel(/empreinte du commit/i).fill('abc1234')
    await page.getByRole('button', { name: 'lancer', exact: true }).click()

    await expect(page.getByText(marque).first()).toBeVisible()

    // CE QUI CHANGE : la seconde fenêtre le voit arriver, seule. Avant ce
    // jalon, seul le tableau des tickets se comportait ainsi.
    await expect(autre.getByText(marque).first()).toBeVisible({ timeout: PROPAGATION })

    await contexte.close()
  })
})
