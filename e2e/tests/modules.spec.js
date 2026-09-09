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

    // La ligne est cherchée par son BOUTON DE SUPPRESSION, dont le nom
    // accessible désigne la table sans ambiguïté.
    //
    // Le nom de la table apparaît désormais dans plusieurs éléments de liste :
    // la ligne elle-même, et les trois adresses REST affichées sous son schéma
    // (GET, POST, DELETE /backend/data/<table>). Un locateur fondé sur le seul
    // texte en retiendrait quatre.
    const ligne = page
      .getByRole('listitem')
      .filter({ has: page.getByRole('button', { name: `Supprimer la table ${nom}` }) })

    await expect(ligne).toBeVisible()

    // La table naît avec sa colonne « id » — et déjà dépliée, puisqu'on vient
    // de la créer pour la remplir.
    await expect(ligne).toContainText('1 col.')

    await ligne.getByRole('button', { name: /^colonne$/i }).click()
    await expect(ligne).toContainText('2 col.')

    // La suppression détruit maintenant une VRAIE table et ses lignes : elle
    // demande confirmation, et c'est le point de ce passage.
    await ligne.getByRole('button', { name: `Supprimer la table ${nom}` }).click()

    const confirmation = page.getByRole('dialog', { name: /supprimer la table/i })
    await expect(confirmation).toContainText(nom)
    await confirmation.getByRole('button', { name: /supprimer définitivement/i }).click()

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

  /**
   * UN DÉPLOIEMENT NE SE GLISSE PAS.
   *
   * C'est une règle de domaine, pas un oubli : un déploiement est un
   * ÉVÉNEMENT constaté. Le tirer de « en échec » vers « en ligne »
   * réécrirait l'histoire au lieu de la corriger — pour repartir, il y a
   * « relancer », qui crée un vrai nouveau passage en file d'attente.
   *
   * Rien d'autre ne protège cette règle : `draggable` est un booléen que
   * l'uniformisation avec les deux autres tableaux ferait passer à vrai sans
   * y penser, et l'écran continuerait de fonctionner — en mentant.
   *
   * (L'effacement de la durée par le trigger est couvert côté serveur, sur
   * des données jetables : back/tests/Integration/ModulesTest. Le vérifier
   * ici obligeait à relancer un déploiement du jeu de démonstration, sans
   * moyen de l'y remettre — l'écran n'offre AUCUNE action pour cela, et c'est
   * exactement le point de ce test.)
   */
  test('un déploiement ne se glisse pas d une colonne à l autre', async ({ page }) => {
    await page.goto('/modules/deploiement')
    await expect(page.getByRole('heading', { name: 'déploiement' })).toBeVisible()

    const enEchec = page.locator('[data-column="error"]')
    const enLigne = page.locator('[data-column="ready"]')

    const carte = enEchec.getByRole('option').first()
    await expect(carte).toBeVisible()
    // Voir tickets.spec.js : « hover » attend la stabilité, « boundingBox » non.
    await carte.hover()

    const avant = await enLigne.getByRole('option').count()

    const depart = await carte.boundingBox()
    const cible = await enLigne.boundingBox()

    await page.mouse.move(depart.x + depart.width / 2, depart.y + depart.height / 2)
    await page.mouse.down()
    await page.mouse.move(depart.x + 40, depart.y + 20, { steps: 5 })
    await page.mouse.move(cible.x + cible.width / 2, cible.y + 60, { steps: 10 })
    await page.mouse.up()

    // Rien n'a bougé, ni à l'écran ni en base.
    await expect(enEchec.getByRole('option')).toHaveCount(1)
    await expect(enLigne.getByRole('option')).toHaveCount(avant)

    await page.reload()
    await expect(page.locator('[data-column="error"]').getByRole('option')).toHaveCount(1)
  })

  test('le journal d un déploiement s ouvre en fenêtre', async ({ page }) => {
    await page.goto('/modules/deploiement')

    // Une trace de compilation est large par nature : la déplier dans une
    // colonne de 13 rem la réduirait à une bouillie de retours à la ligne.
    await page.locator('[data-column="error"]').getByRole('option').first().click()

    const fenetre = page.getByRole('dialog')
    await expect(fenetre).toBeVisible()
    await expect(fenetre.getByText(/Échec : 2 tests en échec/)).toBeVisible()
    await expect(fenetre.getByRole('button', { name: /^relancer$/i })).toBeVisible()
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

  test('les erreurs sont groupées, et changer de statut change de colonne', async ({ page }) => {
    await page.goto('/modules/supervision')
    await expect(page.getByRole('heading', { name: 'supervision' })).toBeVisible()

    // Le tableau n'a plus de filtre par défaut : les trois colonnes sont
    // visibles d'emblée, et c'est le tableau lui-même qui sépare les statuts.
    const nonResolues = page.locator('[data-column="unresolved"]')
    const ignorees = page.locator('[data-column="ignored"]')

    // La carte est cherchée SUR TOUT LE TABLEAU, sans présumer de sa colonne
    // de départ : ce test déplace une erreur, et une exécution interrompue en
    // son milieu la laisserait ailleurs. Il doit alors se rejouer, pas
    // échouer sur l'état que lui-même a laissé.
    const fatale = page.getByRole('option').filter({ hasText: 'fatale' }).first()

    // Une exception vue 23 fois est UN problème, pas 23 lignes.
    await expect(fatale).toContainText('23')

    await fatale.click()

    // Le détail s'ouvre en fenêtre : une pile d'appels est trop large pour
    // une colonne. Les boutons de statut y sont cherchés — les pastilles de
    // filtre en haut d'écran portent exactement les mêmes libellés.
    const fenetre = page.getByRole('dialog')
    await expect(fenetre).toBeVisible()

    // ┌─────────────────────────────────────────────────────────────────────┐
    // │  LA REMISE EN ÉTAT NE DOIT PAS DÉPENDRE DE LA RÉUSSITE DU TEST      │
    // │                                                                     │
    // │  Ce test déplace une erreur du jeu de démonstration, puis la        │
    // │  remet. Tant que la remise vivait dans le corps du test, un échec   │
    // │  d'assertion au milieu la laissait déplacée — et c'est             │
    // │  « dashboard.spec » qui tombait à l'exécution SUIVANTE, en          │
    // │  affirmant qu'aucune alerte de supervision ne remontait. Le test    │
    // │  qui échouait n'était pas celui qui avait fauté.                    │
    // │                                                                     │
    // │  Observé pour de vrai, sur une exécution interrompue à la main.     │
    // │  Le « finally » couvre l'échec d'assertion, cas courant ; il ne     │
    // │  peut évidemment rien contre un processus tué.                      │
    // └─────────────────────────────────────────────────────────────────────┘
    try {
      await fenetre.getByRole('button', { name: 'ignorées', exact: true }).click()
      await expect(fenetre.getByRole('button', { name: 'ignorées', exact: true })).toHaveAttribute(
        'aria-pressed',
        'true',
      )

      // Ce qui compte vraiment sur un tableau : la carte a CHANGÉ DE COLONNE.
      await expect(ignorees.getByRole('option').filter({ hasText: 'fatale' })).toHaveCount(1)
    } finally {
      await fenetre
        .getByRole('button', { name: 'non résolues', exact: true })
        .click()
        .catch(() => {
          /* la remise a échoué ; les assertions qui suivent le diront */
        })
    }

    await expect(
      fenetre.getByRole('button', { name: 'non résolues', exact: true }),
    ).toHaveAttribute('aria-pressed', 'true')

    await page.keyboard.press('Escape')
    await expect(nonResolues.getByRole('option').filter({ hasText: 'fatale' })).toHaveCount(1)
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

    // Le fichier naît « maquette » : c'est le type par défaut du formulaire,
    // donc la colonne où il doit apparaître.
    const carte = page.locator('[data-column="maquette"]').getByRole('option').filter({
      hasText: nom,
    })
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
