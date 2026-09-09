import { expect, login, test } from './support.js'

/**
 * Sessions ouvertes, vues du profil.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  DEUX CONTEXTES DE NAVIGATEUR, ET C'EST INDISPENSABLE               │
 * │                                                                     │
 * │  Toute la fonctionnalité tient dans une distinction : la session    │
 * │  COURANTE et les AUTRES. Avec un seul navigateur il n'y a jamais    │
 * │  d'autre session — on vérifierait qu'une liste s'affiche, et rien   │
 * │  de ce qui compte.                                                  │
 * │                                                                     │
 * │  Un second contexte a ses propres cookies : c'est exactement un     │
 * │  second appareil.                                                   │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  AUCUNE ASSERTION SUR UN NOMBRE ABSOLU DE SESSIONS                  │
 * │                                                                     │
 * │  Les trente tests de la suite se connectent tous au même compte de  │
 * │  démonstration, et chaque connexion ouvre une session. Compter      │
 * │  « deux » reviendrait à faire dépendre ce fichier de tout ce qui    │
 * │  tourne avant lui — et à échouer selon l'ordre d'exécution.         │
 * │                                                                     │
 * │  On mesure donc des ÉCARTS, et la liste est ordonnée du plus récent │
 * │  au plus ancien : la session ouverte à l'instant est la première.   │
 * └─────────────────────────────────────────────────────────────────────┘
 */
test.describe('sessions ouvertes', () => {
  /** La section, cherchée par son titre plutôt que par une classe. */
  const section = (page) =>
    page
      .locator('section')
      .filter({ has: page.getByRole('heading', { name: /sessions ouvertes/i }) })

  test('l’appareil courant est désigné, et ne peut pas se fermer lui-même', async ({ page }) => {
    await login(page)
    await page.goto('/profil')

    const bloc = section(page)
    const courante = bloc.getByRole('listitem').filter({ hasText: 'cet appareil' })

    await expect(courante).toHaveCount(1)

    // Pas de bouton « Fermer » sur sa propre ligne : se déconnecter est un
    // geste qui a déjà son bouton ailleurs, et le confondre avec « fermer une
    // session à distance » ferait sortir l'utilisateur par surprise.
    await expect(courante.getByRole('button', { name: /fermer/i })).toHaveCount(0)
  })

  test('un second appareil apparaît, et se ferme à distance', async ({ page, browser }) => {
    await login(page)
    await page.goto('/profil')

    const bloc = section(page)
    const lignes = bloc.getByRole('listitem')
    await expect(lignes.first()).toBeVisible()

    // ┌───────────────────────────────────────────────────────────────────┐
    // │  NI NOMBRE ABSOLU, NI ÉCART : ON VÉRIFIE UNE PRÉSENCE             │
    // │                                                                   │
    // │  Une première version comptait les lignes avant et après, en      │
    // │  attendant « +1 ». Elle passait seule et tombait dans la suite     │
    // │  complète : trente connexions plus tôt, le compte de démonstration │
    // │  a atteint le PLAFOND de dix sessions actives. La onzième en ferme │
    // │  alors une, et le total ne bouge pas.                             │
    // │                                                                   │
    // │  Le plafond faisait son travail ; c'est le test qui comptait.      │
    // └───────────────────────────────────────────────────────────────────┘

    // ┌───────────────────────────────────────────────────────────────────┐
    // │  UN AGENT DISTINCT, POUR POUVOIR DÉSIGNER CETTE SESSION-LÀ        │
    // │                                                                   │
    // │  Toutes les sessions du compte de démonstration viennent du même  │
    // │  navigateur et de la même adresse : rien ne les distingue à       │
    // │  l'écran. On avait d'abord visé « la plus récente », en la        │
    // │  croyant en tête — mais recharger le profil fait TOURNER le jeton │
    // │  courant, qui redevient donc le plus récent. Le tri était juste,  │
    // │  l'hypothèse ne l'était pas.                                      │
    // │                                                                   │
    // │  Un agent propre au second contexte lève l'ambiguïté, et éprouve  │
    // │  au passage le libellé lisible jusqu'à l'écran.                   │
    // └───────────────────────────────────────────────────────────────────┘
    const autre = await browser.newContext({
      userAgent:
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile Safari/604.1',
    })
    const autrePage = await autre.newPage()

    try {
      await login(autrePage)
      await page.reload()

      const distante = lignes.filter({ hasText: 'Safari sur iPhone' })

      await expect(distante).toHaveCount(1)
      await expect(distante).not.toContainText('cet appareil')

      await distante.getByRole('button', { name: /fermer/i }).click()
      await expect(distante).toHaveCount(0)

      // ┌─────────────────────────────────────────────────────────────────┐
      // │  CE QUI COMPTE VRAIMENT                                         │
      // │                                                                 │
      // │  Fermer une session doit COUPER l'appareil, pas seulement le    │
      // │  faire disparaître d'une liste. Le second navigateur a encore   │
      // │  son jeton d'accès en mémoire ; un rechargement le force à      │
      // │  rafraîchir, ce qui doit échouer et le ramener à la connexion.  │
      // └─────────────────────────────────────────────────────────────────┘
      await autrePage.goto('/')
      await expect(autrePage).toHaveURL(/\/connexion/)
    } finally {
      await autre.close()
    }
  })

  test('« fermer les autres » épargne l’appareil qui le demande', async ({ page, browser }) => {
    await login(page)

    const deuxieme = await browser.newContext()

    try {
      await login(await deuxieme.newPage())

      await page.goto('/profil')
      const bloc = section(page)

      await expect(bloc.getByRole('listitem').first()).toBeVisible()
      await bloc.getByRole('button', { name: /fermer les autres/i }).click()

      // UNE seule reste, et c'est celle-ci — quel que soit le nombre de
      // départ. Le geste ne doit pas se retourner contre celui qui le fait.
      await expect(bloc.getByRole('listitem')).toHaveCount(1)
      await expect(bloc.getByRole('listitem')).toContainText('cet appareil')

      // Et l'écran répond encore : la session courante est bien vivante.
      await page.reload()
      await expect(page.getByRole('heading', { name: /informations personnelles/i })).toBeVisible()
    } finally {
      await deuxieme.close()
    }
  })
})
