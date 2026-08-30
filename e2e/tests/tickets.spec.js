import { expect, login, test } from './support.js'

/**
 * Module « Tickets ».
 *
 * Ces parcours n'utilisent la souris que pour arriver sur l'écran. Tout le
 * reste passe par le clavier, parce que c'est la promesse du module : si les
 * raccourcis cessent de fonctionner, le module a perdu sa raison d'être,
 * même si l'interface reste utilisable à la souris.
 */
test.describe('tickets', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
    await page.goto('/modules/tickets')
    await expect(page.getByRole('heading', { name: 'tickets' })).toBeVisible()
  })

  /** Titre unique : deux exécutions successives ne peuvent pas se confondre. */
  const nouveauTitre = () => `Recette ${Math.random().toString(36).slice(2, 8)}`

  test('le clavier seul crée, priorise, termine et supprime un ticket', async ({ page }) => {
    const titre = nouveauTitre()

    // --- Création : « c » ouvre la saisie, Entrée valide -------------------
    await page.keyboard.press('c')
    await page.getByPlaceholder(/Entrée pour créer/i).fill(titre)
    await page.keyboard.press('Enter')

    const ligne = page.getByRole('option').filter({ hasText: titre })
    await expect(ligne).toBeVisible()

    // Le curseur se pose sur le ticket créé : on enchaîne sans le désigner.
    await expect(ligne).toHaveAttribute('aria-selected', 'true')

    // La saisie reste ouverte pour enchaîner : on en sort avant les raccourcis.
    await page.keyboard.press('Escape')

    // --- Priorité : une touche ---------------------------------------------
    await page.keyboard.press('1')
    await expect(ligne.getByRole('img', { name: 'priorité urgente' })).toBeVisible()

    // --- Statut : « d » termine --------------------------------------------
    await page.keyboard.press('d')
    await expect(ligne.getByRole('img', { name: 'terminé' })).toBeVisible()

    // --- Suppression --------------------------------------------------------
    await page.keyboard.press('Backspace')
    await expect(page.getByText(titre, { exact: true })).toBeHidden()
  })

  test('la recherche filtre sans attendre le réseau', async ({ page }) => {
    // « / » amène au champ de recherche depuis n'importe où sur l'écran.
    await page.keyboard.press('/')
    await page.keyboard.type('refresh')

    await expect(page.getByText("Détecter la réutilisation d'un refresh token")).toBeVisible()
    await expect(page.getByText('Sauvegardes automatiques de PostgreSQL')).toBeHidden()

    // Échap vide la recherche et rétablit la liste complète : la sortie de
    // secours doit fonctionner depuis le champ lui-même, sans le quitter.
    await page.keyboard.press('Escape')
    await expect(page.getByText('Sauvegardes automatiques de PostgreSQL')).toBeVisible()
  })

  test('le curseur clavier descend dans l ordre de lecture', async ({ page }) => {
    const lignes = page.getByRole('option')

    // Le curseur est POSÉ dès l'arrivée, sans qu'on ait à le placer : un outil
    // au clavier dans lequel la première touche ne sert qu'à entrer dans la
    // liste fait perdre une frappe à chaque visite.
    await expect(lignes.first()).toHaveAttribute('aria-selected', 'true')

    await page.keyboard.press('j')
    await expect(lignes.nth(1)).toHaveAttribute('aria-selected', 'true')

    // Le curseur remonte par où il est descendu.
    await page.keyboard.press('k')
    await expect(lignes.first()).toHaveAttribute('aria-selected', 'true')
  })

  test('le panneau de détail enregistre champ par champ', async ({ page }) => {
    const titre = nouveauTitre()

    await page.keyboard.press('c')
    await page.getByPlaceholder(/Entrée pour créer/i).fill(titre)
    await page.keyboard.press('Enter')
    await page.keyboard.press('Escape')

    // Entrée ouvre le détail du ticket sous le curseur.
    await page.keyboard.press('Enter')

    const panneau = page.getByRole('complementary', { name: /détail du ticket/i })
    await expect(panneau).toBeVisible()

    // Le projet part à la sortie du champ : il n'y a pas de bouton
    // « enregistrer » à oublier.
    await panneau.getByLabel('projet').fill('Recette clavier')
    await panneau.getByLabel('étiquettes').click()

    // La normalisation est faite par le serveur ; on vérifie qu'elle revient.
    await panneau.getByLabel('étiquettes').fill('URGENT, urgent, Bug')
    await page.keyboard.press('Enter')

    await expect(panneau.getByLabel('étiquettes')).toHaveValue('urgent, bug')

    // Le rechargement prouve que l'enregistrement a bien atteint la base.
    await page.reload()
    const ligne = page.getByRole('option').filter({ hasText: titre })
    await expect(ligne).toBeVisible()
    await expect(ligne).toContainText('urgent')

    // Ménage
    await ligne.click()
    await page.keyboard.press('Backspace')
    await expect(page.getByText(titre, { exact: true })).toBeHidden()
  })

  test('les tickets terminés ne sont jamais annoncés en retard', async ({ page }) => {
    // Le jeu de données contient des tickets terminés dont l'échéance est
    // passée. Un ticket terminé après son échéance n'est pas « en retard » :
    // il est terminé. Le dire mettrait une alerte sur du travail fait.
    const termines = page
      .getByRole('option')
      .filter({ has: page.getByRole('img', { name: 'terminé' }) })

    await expect(termines.first()).toBeVisible()
    await expect(termines.getByText(/de retard/)).toHaveCount(0)
  })
})
