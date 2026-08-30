import { expect, login, test } from './support.js'

/**
 * Parcours des quatre modules dotés d'un modèle propre.
 *
 * Chaque test vise ce que l'écran PROMET, pas seulement qu'il s'affiche :
 * une clé d'API qui ne réapparaît jamais, une relance qui efface la durée
 * précédente, un regroupement d'erreurs, une version qui s'ajoute sans
 * écraser la précédente.
 */
test.describe('modules', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
  })

  const unique = (prefix) => `${prefix}_${Math.random().toString(36).slice(2, 7)}`

  // ---------------------------------------------------------------- backend

  test('une table se crée, gagne une colonne, puis se supprime', async ({ page }) => {
    await page.goto('/modules/backend')
    await expect(page.getByRole('heading', { name: 'backend' })).toBeVisible()

    const nom = unique('recette')

    await page.getByRole('button', { name: /nouvelle table/i }).click()
    await page.getByPlaceholder('commandes').fill(nom)
    await page.getByRole('button', { name: /^créer$/i }).click()

    // On cible la LIGNE de liste, pas un bouton de la page : les pastilles de
    // filtre en haut d'écran portent les mêmes mots que les lignes.
    const ligne = page.getByRole('listitem').filter({ hasText: nom })
    await expect(ligne).toBeVisible()

    // La table naît avec sa colonne « id » — et déjà dépliée, puisqu'on vient
    // de la créer pour la remplir.
    await expect(ligne).toContainText('1 col.')

    await ligne.getByRole('button', { name: /^colonne$/i }).click()
    await expect(ligne).toContainText('2 col.')

    await ligne.getByRole('button', { name: new RegExp(`Supprimer la table ${nom}`) }).click()
    await expect(page.getByText(nom, { exact: true })).toBeHidden()
  })

  test("une clé d'API n'est montrée qu'une fois", async ({ page }) => {
    await page.goto('/modules/backend')
    await page.getByRole('button', { name: /^clés/i }).click()

    const label = unique('Recette')

    await page.getByPlaceholder(/Client web/i).fill(label)
    await page.getByRole('button', { name: /créer la clé/i }).click()

    // Le jeton en clair, et l'avertissement qui va avec.
    const encart = page.locator('div').filter({ hasText: /Copiez cette clé maintenant/ }).last()
    await expect(encart).toBeVisible()

    const jeton = (await encart.locator('code').textContent())?.trim() ?? ''
    expect(jeton).not.toBe('')

    await page.getByRole('button', { name: /j'ai noté la clé/i }).click()

    // Le serveur n'en garde que l'empreinte : après rechargement, la clé
    // n'est plus nulle part — seul son préfixe demeure.
    await page.reload()
    await page.getByRole('button', { name: /^clés/i }).click()

    await expect(page.getByText(label, { exact: true })).toBeVisible()
    await expect(page.getByText(jeton, { exact: true })).toHaveCount(0)

    // Ménage
    const ligne = page.getByRole('listitem').filter({ hasText: label })
    await ligne.getByRole('button', { name: /révoquer/i }).click()
    await expect(ligne.getByRole('button', { name: /révoquer/i })).toHaveCount(0)
  })

  // ------------------------------------------------------------ déploiement

  test('relancer un déploiement efface sa durée précédente', async ({ page }) => {
    await page.goto('/modules/deploiement')
    await expect(page.getByRole('heading', { name: 'déploiement' })).toBeVisible()

    // Le jeu de démonstration contient un déploiement terminé, donc chiffré.
    // On cible une LIGNE de liste : les pastilles de filtre portent les mêmes
    // libellés de statut et seraient sinon retenues en premier.
    const enLigne = page.getByRole('listitem').filter({ hasText: 'en ligne' }).first()
    await expect(enLigne).toContainText(/\d+ (s|min)/)

    // La ligne est ensuite désignée par son MESSAGE, pas par son statut : un
    // locateur fondé sur « en ligne » se rattacherait à une autre ligne dès
    // que celle-ci change de statut, et le test vérifierait le mauvais objet.
    const message = (await enLigne.locator('span').filter({ hasText: /\S/ }).nth(2).textContent()) ?? ''
    const ligne = page.getByRole('listitem').filter({ hasText: message.trim() }).first()

    await ligne.getByRole('button').first().click()
    await ligne.getByRole('button', { name: /^relancer$/i }).click()

    // Sans effacement, le déploiement relancé afficherait la durée de sa
    // tentative précédente — pire qu'une durée absente.
    await expect(ligne).toContainText('en file')
    await expect(ligne).toContainText('—')
  })

  test('un déploiement refuse une empreinte de commit invalide', async ({ page }) => {
    await page.goto('/modules/deploiement')
    await page.getByRole('button', { name: /^déployer$/i }).click()

    await page.getByLabel(/branche/i).fill('main')
    await page.getByPlaceholder('a3f9c1d').fill('pas-du-hexa')
    await page.getByRole('button', { name: /^lancer$/i }).click()

    // La règle est vérifiée par le SERVEUR : le front affiche son message,
    // il ne duplique pas la règle.
    await expect(page.getByText(/hexadécimale/i)).toBeVisible()
  })

  // ------------------------------------------------------------ supervision

  test('les erreurs sont groupées et leur statut se change', async ({ page }) => {
    await page.goto('/modules/supervision')
    await expect(page.getByRole('heading', { name: 'supervision' })).toBeVisible()

    // « toutes » plutôt que le filtre par défaut : sans cela, changer le
    // statut ferait sortir la ligne de la liste en cours de test, et le
    // panneau ouvert disparaîtrait sous le curseur.
    await page.getByRole('button', { name: 'toutes', exact: true }).click()

    // Une exception vue 23 fois est UN problème, pas 23 lignes.
    const fatale = page.getByRole('listitem').filter({ hasText: 'fatale' }).first()
    await expect(fatale).toContainText('23')

    await fatale.getByRole('button').first().click()

    // Les boutons de statut sont cherchés DANS la ligne : les pastilles de
    // filtre en haut d'écran portent exactement les mêmes libellés.
    await fatale.getByRole('button', { name: 'ignorées', exact: true }).click()
    await expect(fatale.getByRole('button', { name: 'ignorées', exact: true })).toHaveAttribute(
      'aria-pressed',
      'true',
    )

    // Remise en état : la base de développement doit se retrouver comme avant.
    await fatale.getByRole('button', { name: 'non résolues', exact: true }).click()
    await expect(fatale.getByRole('button', { name: 'non résolues', exact: true })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
  })

  test('la courbe des occurrences reste lisible par un lecteur d écran', async ({ page }) => {
    await page.goto('/modules/supervision')

    // La courbe est décorative pour un lecteur d'écran ; les chiffres, eux,
    // restent atteignables par un tableau.
    const tableau = page.getByRole('table', { name: /occurrences par jour/i })
    await expect(tableau.getByRole('row')).toHaveCount(15) // 14 jours + en-tête
  })

  // ----------------------------------------------------------------- design

  test('une version s ajoute sans écraser la précédente', async ({ page }) => {
    await page.goto('/modules/design')
    await expect(page.getByRole('heading', { name: 'design' })).toBeVisible()

    const nom = unique('Recette')

    await page.getByRole('button', { name: /nouveau fichier/i }).click()
    await page.getByLabel('nom', { exact: true }).fill(nom)
    await page.getByRole('button', { name: /^créer$/i }).click()

    const carte = page.getByRole('button').filter({ hasText: nom })
    await expect(carte).toBeVisible()

    // Un fichier naît avec sa v1 : sans version, il ne documenterait rien.
    await expect(carte).toContainText('v1')

    await carte.click()

    const panneau = page.getByRole('complementary', { name: /historique/i })

    // On attend le CONTENU, pas seulement le panneau : celui-ci s'affiche dès
    // le clic, avec un indicateur de chargement, et le nom du fichier n'y
    // apparaît qu'une fois l'historique reçu.
    await expect(panneau.getByText(nom, { exact: true })).toBeVisible()

    await panneau.getByPlaceholder('Intitulé').fill('Passe typographique')
    await panneau.getByRole('button', { name: /enregistrer la version/i }).click()

    // L'historique conserve les DEUX : une version s'ajoute, elle ne remplace
    // pas.
    await expect(panneau.getByText('Version initiale')).toBeVisible()
    await expect(panneau.getByText('Passe typographique')).toBeVisible()

    // Ménage
    await panneau.getByRole('button', { name: /supprimer le fichier/i }).click()
    await expect(page.getByText(nom, { exact: true })).toBeHidden()
  })
})
