import { expect, login, test } from './support.js'

/**
 * Palette de recherche — Ctrl/⌘ + K.
 *
 * Elle comble le seul manque que l'application ne pouvait pas contourner :
 * chaque module ne cherchait que dans sa propre table, donc retrouver un mot
 * exigeait de DÉJÀ savoir dans quel module il vivait.
 */
test.describe('recherche transverse', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
  })

  const palette = (page) => page.getByRole('dialog', { name: /recherche/i })

  test('un seul terme trouve dans plusieurs modules à la fois', async ({ page }) => {
    await page.keyboard.press('Control+k')
    await expect(palette(page)).toBeVisible()

    await page.getByLabel('Chercher', { exact: true }).fill('refresh')

    // « refresh » existe en ticket ET en déploiement dans le jeu de
    // démonstration : c'est exactement le cas qu'aucune recherche de module ne
    // savait traiter.
    const resultats = palette(page).locator('button', { hasText: /refresh|réutilisation/i })
    await expect(resultats.first()).toBeVisible()

    const textes = (await resultats.allTextContents()).join(' | ')
    expect(textes).toContain('tickets')
    expect(textes).toContain('déploiement')
  })

  test('les accents ne changent rien au résultat', async ({ page }) => {
    await page.keyboard.press('Control+k')

    const champ = page.getByLabel('Chercher', { exact: true })

    await champ.fill('systeme')
    await expect(palette(page).getByText(/Système/i).first()).toBeVisible()

    // Le repli est fait des DEUX côtés, colonne et motif : la présence des
    // accents dans la frappe ne doit rien changer.
    await champ.fill('système')
    await expect(palette(page).getByText(/Système/i).first()).toBeVisible()
  })

  test('la navigation au clavier mène au module', async ({ page }) => {
    await page.keyboard.press('Control+k')

    // Sans terme, seules les destinations sont proposées ; la première est le
    // premier module du catalogue.
    await expect(palette(page).getByText('aller à')).toBeVisible()

    await page.getByLabel('Chercher', { exact: true }).fill('supervision')

    // ATTENDRE QUE LE FILTRE SOIT APPLIQUÉ avant d'appuyer sur Entrée.
    // « fill » rend la main dès la frappe ; sans cette attente, Entrée part
    // pendant que la liste est encore l'entière, et ouvre le premier module du
    // catalogue au lieu de celui qu'on vient de taper.
    const cible = palette(page).locator('button', { hasText: /^supervision$/ })
    await expect(cible).toHaveCount(1)

    await page.keyboard.press('Enter')

    await expect(page).toHaveURL(/modules\/supervision/)
    await expect(palette(page)).toBeHidden()
  })

  test('échap referme sans naviguer', async ({ page }) => {
    const depart = page.url()

    await page.keyboard.press('Control+k')
    await expect(palette(page)).toBeVisible()

    await page.keyboard.press('Escape')
    await expect(palette(page)).toBeHidden()
    expect(page.url()).toBe(depart)
  })

  test('un terme trop court ne lance pas de recherche', async ({ page }) => {
    await page.keyboard.press('Control+k')
    await page.getByLabel('Chercher', { exact: true }).fill('r')

    // Le dire vaut mieux que de laisser croire à une absence de résultat.
    await expect(palette(page).getByText(/un caractère de plus/i)).toBeVisible()
  })
})
