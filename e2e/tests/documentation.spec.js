import { expect, login, test } from './support.js'

/**
 * Module Documentation.
 *
 * Le parcours qui compte le plus ici est celui qu'on ne voit pas : du HTML et
 * un lien « javascript: » écrits dans une page doivent s'afficher comme du
 * texte, sans rien exécuter. Les tests unitaires du découpage le vérifient sur
 * les données ; celui-ci le vérifie dans un vrai navigateur, là où une
 * injection s'exécuterait.
 */
test.describe('documentation', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
    await page.goto('/modules/documentation')
    await expect(page.getByRole('button', { name: /nouvelle page/i })).toBeVisible()
  })

  test('une page s’écrit et se lit, sans interpréter aucune balise', async ({ page }) => {
    const titre = `Procédure de parcours ${Date.now()}`
    const pages = page.getByRole('navigation', { name: 'Pages de documentation' })

    await page.getByRole('button', { name: /nouvelle page/i }).click()
    await page.getByRole('textbox', { name: 'Titre', exact: true }).fill(titre)
    await page
      .getByRole('textbox', { name: 'Texte', exact: true })
      .fill(
        [
          '# Étapes',
          '',
          '1. Arrêter le worker',
          '2. **Restaurer** la base',
          '',
          '<img src=x onerror="window.__injection = true">',
          '',
          '[piège](javascript:window.__injection=true)',
        ].join('\n'),
      )
    await page.getByRole('button', { name: /créer la page/i }).click()

    const article = page.getByRole('article')

    try {
      await expect(article.getByRole('heading', { name: titre })).toBeVisible()
      await expect(article.getByRole('heading', { name: 'Étapes' })).toBeVisible()
      await expect(article.getByRole('listitem')).toHaveCount(2)

      // La balise est du TEXTE : visible telle quelle, aucune image créée,
      // aucun gestionnaire exécuté.
      await expect(article.getByText('<img src=x onerror="window.__injection = true">')).toBeVisible()
      await expect(article.locator('img')).toHaveCount(0)

      // Le lien refusé reste lisible, sans adresse.
      await expect(article.getByRole('link', { name: 'piège' })).toHaveCount(0)
      await expect(article.getByText('[piège](javascript:window.__injection=true)')).toBeVisible()

      expect(await page.evaluate(() => window.__injection)).toBeUndefined()

      // La page ouverte vit dans l'adresse : un lien vers elle mène à elle.
      await expect(page).toHaveURL(/[?&]page=/)
    } finally {
      await pages.getByRole('button', { name: titre }).click()
      await article.getByRole('button', { name: /supprimer la page/i }).click()
      await expect(pages.getByRole('button', { name: titre })).toHaveCount(0)
    }
  })

  test('la suppression d’une page s’annule', async ({ page }) => {
    const titre = `Page à restaurer ${Date.now()}`
    const pages = page.getByRole('navigation', { name: 'Pages de documentation' })

    await page.getByRole('button', { name: /nouvelle page/i }).click()
    await page.getByRole('textbox', { name: 'Titre', exact: true }).fill(titre)
    await page.getByRole('button', { name: /créer la page/i }).click()
    await expect(pages.getByRole('button', { name: titre })).toBeVisible()

    await page.getByRole('article').getByRole('button', { name: /supprimer la page/i }).click()
    await expect(pages.getByRole('button', { name: titre })).toHaveCount(0)

    await page.getByRole('button', { name: 'Annuler', exact: true }).click()
    await expect(pages.getByRole('button', { name: titre })).toBeVisible()

    // Ménage.
    await pages.getByRole('button', { name: titre }).click()
    await page.getByRole('article').getByRole('button', { name: /supprimer la page/i }).click()
    await expect(pages.getByRole('button', { name: titre })).toHaveCount(0)
  })
})
