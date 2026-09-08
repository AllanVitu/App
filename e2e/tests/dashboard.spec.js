import { expect, login, test } from './support.js'

/**
 * Tableau de bord.
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

  test('les cinq modules sont en grille, chacun lien vers le sien', async ({ page }) => {
    const grille = page.locator('section', {
      has: page.getByRole('heading', { name: 'état des modules' }),
    })
    const tuiles = grille.getByRole('link')

    await expect(tuiles).toHaveCount(5)

    // La grille a remplacé une spirale avec bascule spirale/liste : la
    // position d'une tuile y est stable d'une visite à l'autre, il n'y a donc
    // plus de préférence de disposition à retenir. L'ordre reste celui du
    // catalogue.
    await expect(tuiles.first()).toHaveAttribute('href', /backend/)
  })

  test('chaque tuile porte l état de son module, pas seulement son nom', async ({ page }) => {
    const grille = page.locator('section', {
      has: page.getByRole('heading', { name: 'état des modules' }),
    })

    // Le tableau de bord n'est pas un second menu : chaque tuile dit où en
    // est son module, avec l'unité qui a un sens chez lui.
    //
    // Motifs INSENSIBLES À LA CASSE : le nom vient de la base (« Backend »),
    // et la mise en minuscules est purement visuelle — le nom accessible,
    // lui, garde la capitale.
    await expect(grille.getByRole('link', { name: /backend/i })).toContainText('tables')
    await expect(grille.getByRole('link', { name: /tickets/i })).toContainText('ouverts')
    await expect(grille.getByRole('link', { name: /supervision/i })).toContainText('non résolue')
    await expect(grille.getByRole('link', { name: /design/i })).toContainText('fichiers')
  })

  /**
   * Les quatre chiffres de tête.
   *
   * Le point qui compte n'est pas qu'ils s'affichent, c'est que « tickets
   * ouverts » N'AFFICHE PAS de comparaison : c'est un état, pas un flux.
   * « 11 tickets ouverts, +3 » laisserait croire qu'il s'en est créé trois,
   * alors que le nombre peut avoir monté parce qu'on en a fermé moins.
   */
  test('les chiffres de tête ne comparent que ce qui est comparable', async ({ page }) => {
    const entete = page.locator('section', { has: page.getByText('déploiements', { exact: true }) })

    const flux = entete.locator('div', { hasText: /^erreurs/ }).first()
    await expect(flux).toContainText('7 j préc.')

    const etat = entete.locator('div', { hasText: /^tickets ouverts/ }).first()
    await expect(etat).not.toContainText('7 j préc.')
  })

  /**
   * Les deux séries ont des ordres de grandeur incompatibles — quelques
   * déploiements par jour contre plusieurs dizaines d'erreurs. Les réunir
   * dans un seul cadre écraserait la première contre l'axe.
   */
  test('les tendances sont tracées dans deux cadres distincts', async ({ page }) => {
    const cadres = page.locator('figure')

    await expect(cadres).toHaveCount(2)

    // Chaque cadre porte l'équivalent textuel de sa courbe : une ligne
    // brisée n'est pas lisible au lecteur d'écran.
    await expect(cadres.first().locator('table caption')).toContainText('par jour')
    await expect(cadres.nth(1).locator('table tbody tr')).toHaveCount(14)
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
