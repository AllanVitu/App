import { expect, login, test } from './support.js'

/**
 * Annuler une suppression, et être prévenu avant d'abandonner une saisie.
 *
 * Les deux endroits où l'application pouvait faire perdre du travail. Ni l'un
 * ni l'autre ne se vérifie ailleurs : le premier tient à un bandeau qui
 * n'existe que quelques secondes, le second à une question du navigateur.
 */
test.describe('ne pas perdre son travail', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
  })

  const unique = () => `Recette ${Math.random().toString(36).slice(2, 7)}`

  test('un ticket supprimé revient par « Annuler »', async ({ page }) => {
    await page.goto('/modules/tickets')
    await expect(page.getByRole('heading', { name: 'tickets' })).toBeVisible()

    const titre = unique()

    // Création au clavier, comme le module y invite.
    await page.getByRole('button', { name: /nouveau/i }).click()
    await page.getByPlaceholder(/titre du ticket/i).fill(titre)
    await page.keyboard.press('Enter')

    const carte = page.getByRole('option').filter({ hasText: titre })
    await expect(carte).toHaveCount(1)

    // Suppression par le raccourci — c'est le geste le plus facile à faire
    // par erreur, puisqu'il tient en une touche.
    await carte.click()
    await page.keyboard.press('Backspace')

    await expect(carte).toHaveCount(0)

    // ┌───────────────────────────────────────────────────────────────────┐
    // │  LA DONNÉE N'AVAIT JAMAIS QUITTÉ LA BASE                          │
    // │                                                                   │
    // │  Toutes les suppressions sont logiques : seul un « deleted_at »   │
    // │  est posé. Il ne manquait que ce chemin de retour.                │
    // └───────────────────────────────────────────────────────────────────┘
    await page.getByRole('button', { name: 'Annuler', exact: true }).click()

    await expect(carte).toHaveCount(1)

    // Et il survit à un rechargement : ce n'est pas un retour en arrière
    // d'affichage, c'est la ligne qui est bien revenue en base.
    await page.reload()
    await expect(page.getByRole('option').filter({ hasText: titre })).toHaveCount(1)

    // Ménage : cette fois sans annuler.
    await page.getByRole('option').filter({ hasText: titre }).click()
    await page.keyboard.press('Backspace')
    await expect(page.getByRole('option').filter({ hasText: titre })).toHaveCount(0)
  })

  test('le bandeau d’annulation disparaît de lui-même', async ({ page }) => {
    await page.goto('/modules/design')
    await expect(page.getByRole('heading', { name: 'design' })).toBeVisible()

    const nom = unique()

    await page.getByRole('button', { name: /nouveau fichier/i }).click()
    await page.getByLabel('nom', { exact: true }).fill(nom)
    await page.getByRole('button', { name: /^créer$/i }).click()

    const carte = page.locator('[data-column="maquette"]').getByRole('option').filter({
      hasText: nom,
    })
    await expect(carte).toBeVisible()

    await carte.click()

    const panneau = page.getByRole('complementary', { name: /historique/i })
    await expect(panneau.getByText(nom, { exact: true })).toBeVisible()
    await panneau.getByRole('button', { name: /supprimer le fichier/i }).click()

    const annuler = page.getByRole('button', { name: 'Annuler', exact: true })
    await expect(annuler).toBeVisible()

    // Huit secondes, pas quatre : le temps de lire, de comprendre l'erreur et
    // d'atteindre le bouton. Passé ce délai il s'efface — l'API, elle,
    // accepterait encore la restauration.
    await expect(annuler).toBeHidden({ timeout: 12_000 })
    await expect(page.getByText(nom, { exact: true })).toBeHidden()
  })

  test('quitter une saisie en cours demande confirmation', async ({ page }) => {
    await page.goto('/modules/deploiement')
    await expect(page.getByRole('heading', { name: 'déploiement' })).toBeVisible()

    await page.getByRole('button', { name: /^déployer$/i }).click()
    await page.getByLabel(/branche/i).fill('feat/quelque-chose-de-long')

    // La question du navigateur est refusée : on reste sur place.
    page.once('dialog', (dialog) => dialog.dismiss())
    await page.getByRole('link', { name: /tickets/i }).first().click()

    await expect(page).toHaveURL(/deploiement/)
    await expect(page.getByLabel(/branche/i)).toHaveValue('feat/quelque-chose-de-long')

    // Acceptée : on part, et la saisie est abandonnée en connaissance de cause.
    page.once('dialog', (dialog) => dialog.accept())
    await page.getByRole('link', { name: /tickets/i }).first().click()

    await expect(page).toHaveURL(/tickets/)
  })

  test('un formulaire vierge ne pose aucune question', async ({ page }) => {
    await page.goto('/modules/deploiement')
    await expect(page.getByRole('heading', { name: 'déploiement' })).toBeVisible()

    await page.getByRole('button', { name: /^déployer$/i }).click()

    // Ouvrir un formulaire n'est pas une saisie. Demander confirmation ici
    // apprendrait à répondre oui sans lire — et la question ne protégerait
    // plus rien le jour où elle compte.
    let questionne = false
    page.on('dialog', (dialog) => {
      questionne = true
      dialog.accept()
    })

    await page.getByRole('link', { name: /tickets/i }).first().click()

    await expect(page).toHaveURL(/tickets/)
    expect(questionne).toBe(false)
  })
})
