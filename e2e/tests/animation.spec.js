import { expect, login, test } from './support.js'

/**
 * Le comportement du moteur d'animation dans un vrai navigateur.
 *
 * CE QUI N'EST PAS ICI, ET POURQUOI. Le découpage en lots — le fait que
 * l'écran de connexion ne télécharge ni la mesure de mise en page ni le
 * morphing SVG — ne se vérifie PAS depuis cette suite : elle tourne contre le
 * serveur de développement, où Vite sert les modules un par un, sans lot.
 * L'invariant n'existe tout simplement pas là-bas, et un test qui l'y
 * chercherait passerait ou échouerait pour de mauvaises raisons.
 *
 * Il est contrôlé sur la compilation de production, par
 * `front/scripts/check-chunks.mjs` (lancé par « npm run check »).
 *
 * Reste ici ce qui a besoin d'un navigateur : le mouvement réduit.
 */
test.describe('moteur d’animation', () => {
  /** Les écrans publics, tous servis par AuthLayout. */
  const PUBLICS = [
    ['/connexion', 'connexion'],
    ['/inscription', 'créer un compte'],
    ['/mot-de-passe-oublie', 'mot de passe oublié'],
  ]

  /** Relève les scripts demandés par la page, par nom de fichier. */
  function traceScripts(page) {
    const scripts = new Set()

    page.on('request', (request) => {
      const url = request.url()

      if (url.endsWith('.js') || url.includes('.js?')) {
        scripts.add(url.split('/').pop().split('?')[0])
      }
    })

    return scripts
  }

  /**
   * GSAP a été retiré du projet. S'il revenait — une dépendance qui le tire,
   * un import restauré par mégarde — ce sont 81 Ko compressés qui rentreraient
   * sans bruit. Ce contrôle-ci vaut dans les deux modes : en développement
   * comme en production, le fichier porterait son nom.
   */
  for (const [chemin, titre] of PUBLICS) {
    test(`${chemin} ne charge aucune trace de GSAP`, async ({ page }) => {
      const scripts = traceScripts(page)

      await page.goto(chemin)
      await expect(page.getByRole('heading', { name: new RegExp(titre, 'i') })).toBeVisible()

      const lots = [...scripts]

      expect(
        lots.filter((file) => file.toLowerCase().startsWith('gsap')),
        `GSAP ne doit plus exister nulle part (${chemin})`,
      ).toEqual([])

      // Le moteur, lui, DOIT être là : sans cette ligne le test passerait
      // aussi sur une page qui n'anime plus rien du tout.
      expect(
        lots.some((file) => /anime/i.test(file)),
        `${chemin} : le moteur d'animation n'a pas été chargé`,
      ).toBe(true)
    })
  }

  /**
   * Le piège propre à anime.js.
   *
   * Ses animations partent d'un état explicite [départ, arrivée] : ne rien
   * jouer laisserait les éléments INVISIBLES, à leur état de départ. C'est la
   * régression la plus grave possible ici — un écran de connexion vide pour
   * quiconque a demandé la réduction des animations.
   */
  test('en mouvement réduit, les écrans publics restent visibles', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' })

    for (const [chemin, titre] of PUBLICS) {
      await page.goto(chemin)
      await expect(page.getByRole('heading', { name: new RegExp(titre, 'i') })).toBeVisible()

      const opacites = await page.evaluate(() =>
        [...document.querySelectorAll('[data-anim]')].map((el) => getComputedStyle(el).opacity),
      )

      expect(opacites.length, `${chemin} : aucun élément animé trouvé`).toBeGreaterThan(0)
      expect(opacites.every((o) => o === '1'), `${chemin} : un élément est resté invisible`).toBe(
        true,
      )
    }
  })

  /** Le même piège, côté application : le tableau de bord anime ses blocs. */
  test('en mouvement réduit, le tableau de bord reste visible', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' })
    await login(page)

    await expect(page.getByRole('heading', { name: /bonjour/i })).toBeVisible()
    await expect(page.getByText('état des modules')).toBeVisible()

    const opacites = await page.evaluate(() =>
      [...document.querySelectorAll('[data-anim="block"]')].map((el) => getComputedStyle(el).opacity),
    )

    expect(opacites.length, 'aucun bloc animé trouvé').toBeGreaterThan(0)
    expect(opacites.every((o) => o === '1'), 'un bloc est resté invisible').toBe(true)
  })

  test('le mouvement normal aboutit au même état visible', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'no-preference' })
    await page.goto('/connexion')

    // Le logotype est découpé en caractères animables ; le texte entier est
    // reporté sur le conteneur pour qu'un lecteur d'écran ne l'épelle pas.
    const marque = page.locator('[data-anim="brand-name"]')
    await expect(marque).toHaveAttribute('aria-label', 'saas os')

    await expect
      .poll(async () =>
        page.evaluate(() =>
          [...document.querySelectorAll('[data-anim]')].every(
            (el) => getComputedStyle(el).opacity === '1',
          ),
        ),
      )
      .toBe(true)
  })
})
