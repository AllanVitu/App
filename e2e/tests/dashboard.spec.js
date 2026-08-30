import { expect, login, test } from './support.js'

/**
 * Tableau de bord et galerie des modules.
 *
 * Ce fichier remplace les anciens parcours de CRUD générique : les cinq
 * modules du catalogue ont désormais chacun leur écran, et la table générique
 * n'est plus qu'un repli pour un module ajouté en base sans code. Son API
 * reste couverte côté serveur (tests\Integration\ItemsTest).
 */
test.describe('tableau de bord', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
  })

  test('la galerie bascule entre spirale et liste, et retient le choix', async ({ page }) => {
    const gallery = page.locator('section', {
      has: page.getByRole('group', { name: /disposition/i }),
    })
    const tiles = gallery.locator('[data-tile]')

    // Les cinq modules sont présents dans les deux dispositions : seule leur
    // mise en page change, jamais leur nombre.
    await expect(tiles).toHaveCount(5)

    await gallery.getByRole('button', { name: 'liste', exact: true }).click()
    await expect(gallery.getByRole('button', { name: 'liste', exact: true })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
    await expect(tiles).toHaveCount(5)

    // Le choix est une préférence, pas un état de page : il survit au
    // rechargement.
    await page.reload()
    await expect(gallery.getByRole('button', { name: 'liste', exact: true })).toHaveAttribute(
      'aria-pressed',
      'true',
    )

    await gallery.getByRole('button', { name: 'spirale', exact: true }).click()
    await expect(gallery.getByRole('button', { name: 'spirale', exact: true })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
    await expect(tiles).toHaveCount(5)

    // La spirale n'est qu'un arrangement visuel : les tuiles restent des
    // liens, dans l'ordre du catalogue.
    await expect(tiles.first()).toHaveAttribute('href', /backend/)
  })

  test('chaque tuile porte l état de son module, pas seulement son nom', async ({ page }) => {
    const gallery = page.locator('section', {
      has: page.getByRole('group', { name: /disposition/i }),
    })

    await gallery.getByRole('button', { name: 'liste', exact: true }).click()

    // Le tableau de bord n'est pas un second menu : chaque tuile dit où en
    // est son module, avec l'unité qui a un sens chez lui.
    //
    // Motifs INSENSIBLES À LA CASSE : le nom vient de la base (« Backend »),
    // et la mise en minuscules est purement visuelle — le nom accessible,
    // lui, garde la capitale.
    await expect(gallery.getByRole('link', { name: /backend/i })).toContainText('tables')
    await expect(gallery.getByRole('link', { name: /tickets/i })).toContainText('ouverts')
    await expect(gallery.getByRole('link', { name: /supervision/i })).toContainText('non résolue')
    await expect(gallery.getByRole('link', { name: /design/i })).toContainText('fichiers')
  })

  test('les alertes de tous les modules remontent, hiérarchisées', async ({ page }) => {
    const section = page.locator('section', {
      has: page.getByRole('heading', { name: 'demande attention' }),
    })

    const lignes = section.getByRole('listitem')
    await expect(lignes.first()).toBeVisible()

    // Le jeu de démonstration contient une production en échec, une erreur
    // fatale et un ticket en retard. Les FAITS passent avant l'intention
    // qu'est une priorité déclarée : la production cassée vient en tête.
    await expect(lignes.first()).toContainText('déploiement en échec')

    const textes = await lignes.allTextContents()
    const modules = textes.join(' | ')

    expect(modules).toContain('supervision')
    expect(modules).toContain('tickets')
  })

  test("l'activité récente traverse les modules", async ({ page }) => {
    const section = page.locator('section', {
      has: page.getByRole('heading', { name: 'activité récente' }),
    })

    // allTextContents() ne PATIENTE PAS : il renvoie ce qui existe à
    // l'instant où on l'appelle. Sans attendre d'abord une ligne visible, il
    // lit une liste encore vide et le test échoue sur un écran qui, lui, est
    // correct.
    const lignes = section.getByRole('listitem')
    await expect(lignes.first()).toBeVisible()

    const textes = (await lignes.allTextContents()).join(' | ')

    // Le fil lisait la table générique, que plus aucun écran n'affiche : il
    // aurait mené vers des modules où ces lignes n'existent pas.
    expect(textes).toMatch(/backend|déploiement|design|tickets|supervision/)
  })

  test('un module inexistant affiche un état vide explicite', async ({ page }) => {
    // Le repli générique reste en place pour un module ajouté en base sans
    // écran dédié ; un slug inconnu, lui, doit le dire franchement.
    await page.goto('/modules/module-99')

    await expect(page.getByText(/module introuvable/i)).toBeVisible()
  })
})
