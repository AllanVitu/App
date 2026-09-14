import { expect, login, test } from './support.js'

/**
 * Module Disponibilité.
 *
 * Aucun de ces parcours n'attend un appel réel : le worker passe toutes les
 * minutes, et un test qui dépendrait d'Internet échouerait pour une raison
 * sans rapport avec l'écran. Ce qui se vérifie ici est ce que l'écran promet
 * immédiatement — le refus d'une adresse interne, et le cycle de vie d'une
 * sonde. Les appels eux-mêmes sont couverts côté serveur, avec une sonde de
 * laboratoire (tests\Integration\ProbeRunnerTest).
 */
test.describe('disponibilité', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
    await page.goto('/modules/disponibilite')
    await expect(page.getByRole('button', { name: /nouvelle sonde/i })).toBeVisible()
  })

  test('une adresse du réseau interne est refusée avant d’être enregistrée', async ({ page }) => {
    await page.getByRole('button', { name: /nouvelle sonde/i }).click()

    const fenetre = page.getByRole('dialog')
    await fenetre.getByRole('textbox', { name: 'Nom', exact: true }).fill('Métadonnées du cloud')
    await fenetre.getByRole('textbox', { name: 'Adresse', exact: true }).fill('http://169.254.169.254/latest/meta-data/')
    await fenetre.getByRole('button', { name: /créer la sonde/i }).click()

    // Le message ne dit pas QUEL réseau a été reconnu : le dire, ce serait
    // cartographier le réseau interne une question à la fois.
    await expect(fenetre.getByText(/réseau privé ou réservé/)).toBeVisible()
  })

  test('une sonde se crée, se met en pause, et sa suppression s’annule', async ({ page }) => {
    const nom = `Sonde de parcours ${Date.now()}`
    const ligne = () => page.getByRole('listitem').filter({ hasText: nom })

    await page.getByRole('button', { name: /nouvelle sonde/i }).click()

    const fenetre = page.getByRole('dialog')
    await fenetre.getByRole('textbox', { name: 'Nom', exact: true }).fill(nom)
    await fenetre.getByRole('textbox', { name: 'Adresse', exact: true }).fill('https://statut.relais-demo.fr/sante')
    await fenetre.getByRole('button', { name: /créer la sonde/i }).click()

    await expect(ligne()).toBeVisible()

    try {
      await ligne().getByRole('button', { name: 'pause', exact: true }).click()
      await expect(ligne()).toContainText('en pause')
      await expect(ligne().getByRole('button', { name: 'reprendre', exact: true })).toBeVisible()

      await ligne().getByRole('button', { name: /supprimer la sonde/i }).click()
      await expect(ligne()).toHaveCount(0)

      await page.getByRole('button', { name: 'Annuler', exact: true }).click()
      await expect(ligne()).toBeVisible()
    } finally {
      // Ménage, même en échec : une sonde laissée derrière serait appelée par
      // le worker à chaque passage, pour rien.
      if (await ligne().count()) {
        await ligne().getByRole('button', { name: /supprimer la sonde/i }).click()
        await expect(ligne()).toHaveCount(0)
      }
    }
  })
})
