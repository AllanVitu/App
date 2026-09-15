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

  test('« m » prend le ticket, puis le rend', async ({ page }) => {
    const titre = nouveauTitre()

    await page.keyboard.press('c')
    await page.getByPlaceholder(/Entrée pour créer/i).fill(titre)
    await page.keyboard.press('Enter')
    await page.keyboard.press('Escape')

    const ligne = page.getByRole('option').filter({ hasText: titre })
    await expect(ligne).toBeVisible()

    // Un ticket neuf n'est à personne : la pastille « à moi » n'existe pas
    // encore, ou ne compte pas celui-ci.
    const pastille = page.getByRole('button', { name: /à moi/i })
    const avant = (await pastille.count()) ? await pastille.innerText() : 'à moi 0'

    // --- « m » : je le prends ------------------------------------------------
    await page.keyboard.press('m')

    // Le repère de l'assigné apparaît SUR LA LIGNE : c'est ce qu'on balaye du
    // regard en descendant une colonne, sans ouvrir quoi que ce soit.
    await expect(ligne.getByTitle('Utilisateur Démo')).toBeVisible()

    // Et le compteur suit : il vient du serveur, il compte au-delà de ce qui
    // est affiché.
    await expect(pastille).not.toHaveText(avant)

    // --- « m » encore : je le rends ------------------------------------------
    //
    // LA MÊME TOUCHE DÉFAIT CE QU'ELLE VIENT DE FAIRE. Sans la bascule,
    // reprendre un ticket pris par erreur demanderait d'ouvrir le panneau et
    // de chercher « personne » dans une liste — exactement ce que le clavier
    // évite ici.
    await page.keyboard.press('m')
    await expect(ligne.getByTitle('Utilisateur Démo')).toBeHidden()

    await page.keyboard.press('Backspace')
  })

  test('le filtre « à moi » vit dans l’adresse et survit au rechargement', async ({ page }) => {
    const titre = nouveauTitre()

    await page.keyboard.press('c')
    await page.getByPlaceholder(/Entrée pour créer/i).fill(titre)
    await page.keyboard.press('Enter')
    await page.keyboard.press('Escape')

    // La ligne AVANT la frappe : la création est un aller-retour réseau, et
    // « m » sans curseur posé ne fait rien du tout — le test aurait échoué
    // plus bas en laissant croire à un défaut d'assignation.
    const ligne = page.getByRole('option').filter({ hasText: titre })
    await expect(ligne).toBeVisible()

    await page.keyboard.press('m')
    await expect(ligne.getByTitle('Utilisateur Démo')).toBeVisible()

    await page.getByRole('button', { name: /à moi/i }).click()
    await expect(page).toHaveURL(/assigne=moi/)

    // Rechargé — ou envoyé à quelqu'un — le lien rouvre le même écran filtré,
    // et « moi » y désigne toujours celui qui lit.
    await page.reload()
    await expect(page.getByRole('button', { name: /à moi/i })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
    await expect(page.getByRole('option').filter({ hasText: titre })).toBeVisible()

    // Ménage : le filtre est retiré avant de supprimer, sinon la ligne
    // disparaît de la vue filtrée avant qu'on l'ait sous le curseur.
    await page.getByRole('button', { name: /à moi/i }).click()
    await page.getByRole('option').filter({ hasText: titre }).click()
    await page.keyboard.press('Backspace')
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

    // On ATTEND que le ticket créé porte le curseur avant d'ouvrir son
    // détail. Sans cette attente, les touches suivantes partent pendant que
    // la création est encore en vol : le panneau s'ouvre sur le ticket
    // précédemment sélectionné, puis saute sur le nouveau quand la réponse
    // arrive — et la saisie en cours repart à zéro, ce qui est le bon
    // comportement pour un AUTRE ticket, mais pas ce que le test voulait
    // vérifier.
    const nouvelle = page.getByRole('option').filter({ hasText: titre })
    await expect(nouvelle).toHaveAttribute('aria-selected', 'true')

    await page.keyboard.press('Escape')

    // Entrée ouvre le détail du ticket sous le curseur.
    await page.keyboard.press('Enter')

    const panneau = page.getByRole('complementary', { name: /détail du ticket/i })
    await expect(panneau.locator('input').first()).toHaveValue(titre)

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

  /**
   * Le glissement d'une colonne à l'autre.
   *
   * Il est écrit en événements pointeur natifs, sans bibliothèque : ce test
   * est donc le seul filet. Il rejoue le geste réel — appuyer, franchir le
   * seuil de reconnaissance, se déplacer, relâcher — et non un raccourci.
   *
   * L'assertion porte sur le SERVEUR, pas sur l'écran : un rechargement de
   * page vérifie que le déplacement a bien été enregistré, là où une simple
   * vérification visuelle passerait aussi sur une mise à jour optimiste que
   * l'API aurait refusée.
   */
  test('glisser une carte dans une autre colonne change son statut', async ({ page }) => {
    const titre = nouveauTitre()

    await page.keyboard.press('c')
    await page.getByPlaceholder(/Entrée pour créer/i).fill(titre)

    // ATTENDRE QUE L'APPLICATION AIT FINI DE RÉAGIR À LA CRÉATION.
    //
    // « createTicket » lance un rafraîchissement des compteurs SANS
    // l'attendre — c'est voulu : la carte ne doit pas attendre le réseau pour
    // apparaître. Mais la réponse redessine l'en-tête quand elle arrive, et si
    // cela tombe entre la mesure de la carte et l'appui, le pointeur atterrit
    // à côté. Le glissement n'a alors tout simplement pas lieu, et le test
    // échoue sur une interface pourtant correcte.
    //
    // Le guetteur est posé AVANT la frappe qui déclenche l'appel : posé après,
    // la réponse pourrait être déjà revenue et l'attente ne finirait jamais.
    const compteursRelus = page.waitForResponse(
      (reponse) =>
        reponse.url().includes('/api/tickets') && reponse.request().method() === 'GET',
    )

    await page.keyboard.press('Enter')
    await page.keyboard.press('Escape')
    await compteursRelus

    const carte = page.getByRole('option').filter({ hasText: titre })
    await expect(carte).toBeVisible()

    // LE GESTE EST JOUÉ AVEC « hover », PAS AVEC DES COORDONNÉES CALCULÉES.
    //
    // « hover » remesure la cible au moment où il agit et attend qu'elle soit
    // STABLE — deux images consécutives au même endroit. Des coordonnées
    // relevées à l'avance, elles, vieillissent : la moindre recomposition
    // entre le relevé et l'appui fait tomber le pointeur à côté, et le
    // glissement n'a pas lieu du tout.
    await carte.hover()

    const depart = await carte.boundingBox()

    await page.mouse.down()

    // Franchir le seuil qui distingue un glissement d'un clic. Relatif à la
    // position courante du pointeur, donc insensible à un décalage de la page.
    await page.mouse.move(depart.x + depart.width / 2 - 45, depart.y + depart.height / 2 - 10, {
      steps: 5,
    })

    // L'ÉCRITURE EST GUETTÉE AVANT LE GESTE.
    //
    // Le module met à jour la carte OPTIMISTEMENT, sans attendre le serveur —
    // c'est ce qui rend le tableau réactif. L'assertion visuelle ci-dessous
    // passe donc pendant que la requête est encore en vol, et un rechargement
    // immédiat l'abandonnerait : le statut vérifié en base serait alors celui
    // d'avant, sur une application qui n'a pourtant rien fait de mal.
    const ecriturePartie = page.waitForResponse(
      (reponse) =>
        /\/api\/tickets\/[0-9a-f-]{36}$/.test(reponse.url()) &&
        reponse.request().method() === 'PUT',
    )

    // Puis la cible, remesurée par Playwright à cet instant précis.
    await page.locator('[data-column="in_progress"]').hover()
    await page.mouse.up()

    await expect(carte.getByRole('img', { name: 'en cours' })).toBeVisible()

    // La preuve : après rechargement, le statut vient de la base. Le
    // rechargement n'a lieu qu'une fois l'écriture réellement aboutie.
    await ecriturePartie
    await page.reload()
    const rechargee = page.getByRole('option').filter({ hasText: titre })
    await expect(rechargee.getByRole('img', { name: 'en cours' })).toBeVisible()

    // Ménage
    await rechargee.click()
    await page.keyboard.press('Backspace')
    await expect(page.getByText(titre, { exact: true })).toBeHidden()
  })
})
