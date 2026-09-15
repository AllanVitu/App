import { readFile } from 'node:fs/promises'

import { expect, login, test } from './support.js'

/**
 * Conformité : ce que la loi demande à l'écran, vérifié dans un navigateur.
 *
 * Les textes légaux se lisent sans compte et se répondent ; une nouvelle
 * version des conditions s'accepte ; le profil remet une archive de ses
 * données, dont le contenu détaillé est vérifié côté serveur.
 */
test.describe('conformité', () => {
  test('les textes légaux se lisent sans compte, et mènent les uns aux autres', async ({ page }) => {
    await page.goto('/connexion')

    await page.getByRole('link', { name: 'Confidentialité', exact: true }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Politique de confidentialité' })).toBeVisible()

    // Les durées sont publiées en tableau, une ligne par catégorie.
    await expect(page.getByRole('row').filter({ hasText: 'Tentatives de connexion' })).toContainText('30 jours')

    const textes = page.getByRole('navigation', { name: 'Textes légaux' })

    await textes.getByRole('link', { name: 'Mentions légales' }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Mentions légales' })).toBeVisible()

    await textes.getByRole('link', { name: 'Conditions générales' }).click()
    await expect(page.getByRole('heading', { level: 1, name: /conditions générales/i })).toBeVisible()
  })

  test('les nouvelles conditions s’acceptent, et le profil remet toutes ses données', async ({ page }) => {
    await login(page)

    // Rejouable : sur une base où la version en vigueur est déjà acceptée, le
    // bandeau n'existe pas, et il n'y a rien à accepter.
    const bandeau = page.getByRole('status').filter({ hasText: 'Les conditions générales ont changé' })

    if (await bandeau.count()) {
      await bandeau.getByRole('button', { name: /j.accepte/i }).click()
      await expect(bandeau).toHaveCount(0)
    }

    await page.goto('/profil')

    const [telechargement] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: /télécharger mes données/i }).click(),
    ])

    expect(telechargement.suggestedFilename()).toMatch(/^relais-mes-donnees-[0-9-]+[.]zip$/)

    // Une archive ZIP, qui contient les données en JSON. Son contenu détaillé
    // — le compte, les fichiers déposés octet pour octet, aucune empreinte de
    // secret — est vérifié côté serveur (tests/Integration/DataExportTest.php).
    const octets = await readFile(await telechargement.path())

    expect(octets.subarray(0, 4).toString('latin1')).toBe('PK' + String.fromCharCode(3, 4))
    expect(octets.includes(Buffer.from('donnees.json'))).toBe(true)
  })
})
