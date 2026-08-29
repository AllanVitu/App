import { createItem, deleteItem, expect, login, test } from './support.js'

/**
 * Cycle complet d'un élément de module, depuis l'interface.
 *
 * Chaque test nettoie ce qu'il crée : la base de développement doit se
 * retrouver dans l'état où elle était.
 */
test.describe('éléments de module', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
  })

  test('création, modification puis suppression', async ({ page }) => {
    const titre = await createItem(page, 'deploiement', { status: 'active' })

    const ligne = page.locator('li').filter({ hasText: titre })
    await expect(ligne.getByText('actif')).toBeVisible()

    // --- Modification -------------------------------------------------------
    await ligne.getByRole('button', { name: /^modifier/i }).click()

    const dialog = page.getByRole('dialog')
    const modifie = `${titre} (modifié)`
    await dialog.getByLabel(/titre/i).fill(modifie)
    await dialog.getByRole('button', { name: /^enregistrer$/i }).click()

    await expect(dialog).toBeHidden()
    await expect(page.getByText(modifie, { exact: true })).toBeVisible()

    // --- Suppression --------------------------------------------------------
    await deleteItem(page, modifie)
  })

  test('un titre vidé est refusé par le serveur', async ({ page }) => {
    const titre = await createItem(page, 'tickets')

    await page.locator('li').filter({ hasText: titre }).getByRole('button', { name: /^modifier/i }).click()

    const dialog = page.getByRole('dialog')
    await dialog.getByLabel(/titre/i).fill('')
    await dialog.getByRole('button', { name: /^enregistrer$/i }).click()

    // Un champ présent mais vide est une intention : il doit être rejeté,
    // pas ignoré en silence.
    await expect(dialog.getByText(/obligatoire/i)).toBeVisible()

    await page.keyboard.press('Escape')
    await deleteItem(page, titre)
  })

  test('la recherche filtre la liste', async ({ page }) => {
    const titre = await createItem(page, 'backend')

    await page.getByPlaceholder(/rechercher/i).fill(titre.slice(0, 12))
    await expect(page.getByText(titre, { exact: true })).toBeVisible()
    await expect(page.getByText('Schéma des utilisateurs', { exact: true })).toBeHidden()

    await page.getByPlaceholder(/rechercher/i).fill('zzzzzzzz')
    await expect(page.getByText(/aucun résultat/i)).toBeVisible()

    await page.getByRole('button', { name: 'Réinitialiser', exact: true }).click()
    await expect(page.getByText('Schéma des utilisateurs', { exact: true })).toBeVisible()

    await deleteItem(page, titre)
  })

  test('le filtre par statut restreint la liste', async ({ page }) => {
    const titre = await createItem(page, 'backend', { status: 'active' })

    await page.getByLabel(/filtrer par statut/i).selectOption('draft')
    await expect(page.getByText(titre, { exact: true })).toBeHidden()

    await page.getByLabel(/filtrer par statut/i).selectOption('')
    await expect(page.getByText(titre, { exact: true })).toBeVisible()

    await deleteItem(page, titre)
  })

  test('le compteur du menu suit les créations', async ({ page }) => {
    const nav = page.getByRole('navigation', { name: /navigation principale/i })
    const lien = nav.getByRole('link', { name: /design/i })

    const avant = Number((await lien.textContent())?.match(/(\d+)\s*$/)?.[1] ?? 0)

    const titre = await createItem(page, 'design')
    await expect(lien).toContainText(String(avant + 1))

    await deleteItem(page, titre)
    await expect(lien).toContainText(String(avant))
  })

  test('un module inexistant affiche un état vide explicite', async ({ page }) => {
    await page.goto('/modules/module-99')

    await expect(page.getByText(/module introuvable/i)).toBeVisible()
  })
})
