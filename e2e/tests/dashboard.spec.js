import { expect, login, test } from './support.js'

/**
 * Tableau de bord.
 *
 * Il répond à quatre questions : où en est-on, que s'est-il passé en
 * production, qu'est-ce qui m'attend, où en est chaque module. Ces parcours
 * vérifient qu'il y répond avec les données du serveur — pas qu'il les
 * dessine au pixel près, ce qu'un test navigateur dirait mal.
 *
 * Les sections sont trouvées par leur NOM ACCESSIBLE (« région »), celui
 * qu'annonce un lecteur d'écran : un parcours qui les chercherait par classe
 * CSS resterait vert le jour où elles perdraient leur titre.
 */
test.describe('tableau de bord', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
  })

  test('chaque module a sa tuile, qui dit son état et mène à lui', async ({ page }) => {
    const lignes = page.getByRole('region', { name: 'Vos lignes' })
    const tuiles = lignes.getByRole('link')

    await expect(tuiles).toHaveCount(6)
    await expect(tuiles.first()).toHaveAttribute('href', /^\/modules\//)

    // Une tuile dit où en est son module, avec l'unité qui a un sens chez
    // lui — ou son alerte, s'il en a une.
    await expect(lignes.getByRole('link', { name: /backend/i })).toContainText(/\d+ \S+/)
    await expect(lignes.getByRole('link', { name: /design/i })).toContainText('fichier')
  })

  /**
   * « Tickets ouverts » est un état, pas un flux : lui adjoindre « +3 vs 7 j
   * préc. » laisserait croire qu'il s'en est créé trois.
   */
  test('les chiffres de tête ne comparent que ce qui est comparable', async ({ page }) => {
    const chiffres = page.getByRole('region', { name: 'Chiffres clés' })

    const erreurs = chiffres.locator('div', { hasText: /^erreurs · 7 j/i })
    await expect(erreurs).toContainText(/vs 7 j préc\.|stable sur 7 j/)

    const tickets = chiffres.locator('div', { hasText: /^tickets ouverts/i })
    await expect(tickets).not.toContainText('7 j')
  })

  test('la ligne de production dit ses faits en toutes lettres', async ({ page }) => {
    const ligne = page.getByRole('region', { name: /ligne de production/i })

    // Le dessin est muet pour un lecteur d'écran : la table porte les mêmes
    // faits, en phrases.
    await expect(ligne.locator('table caption')).toContainText('24 heures')
    await expect(ligne.locator('table tbody tr').last()).toContainText('erreurs')
  })

  test('les alertes remontent, la production cassée en tête', async ({ page }) => {
    const attention = page.getByRole('region', { name: 'Demande attention' })
    const alertes = attention.getByRole('listitem')

    // Le jeu de démonstration contient une production en échec, une erreur
    // fatale et un ticket en retard. Les FAITS passent avant l'intention
    // qu'est une priorité déclarée : la production cassée vient en tête.
    await expect(alertes.first()).toContainText('Mise en production en échec')

    // Et la phrase d'accueil nomme ce plus grave.
    await expect(page.getByText(/dont une mise en production en échec/)).toBeVisible()
  })

  test('ma journée dit ce qui revient à la personne connectée', async ({ page }) => {
    const journee = page.getByRole('region', { name: 'Ma journée' })

    await expect(journee).toContainText(/\d+ assignés?/)
  })

  test('la barre du haut ouvre la palette de commandes', async ({ page }) => {
    await page.getByRole('button', { name: /rechercher ou lancer une commande/i }).click()

    await expect(page.getByRole('dialog')).toBeVisible()
  })

  test('un module inexistant affiche un état vide explicite', async ({ page }) => {
    // Le repli générique reste en place pour un module ajouté en base sans
    // écran dédié ; un slug inconnu, lui, doit le dire franchement.
    await page.goto('/modules/module-99')

    await expect(page.getByText(/module introuvable/i)).toBeVisible()
  })
})
