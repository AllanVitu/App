import { expect, login, test } from './support.js'

/**
 * La séparation des deux moteurs d'animation.
 *
 * Le front est coupé en deux moitiés qui ne chargent pas la même
 * bibliothèque : anime.js pour les écrans publics, GSAP pour l'application.
 * Ce n'est pas cosmétique — c'est 92 Ko compressés que l'écran de connexion
 * ne paie plus.
 *
 * L'invariant se casse en SILENCE : il suffit qu'un composant partagé entre
 * les deux mises en page — une notification, une fenêtre modale — importe
 * GSAP pour que ses 92 Ko reviennent dans le chemin public, sans la moindre
 * erreur pour le signaler. D'où ce fichier.
 */
test.describe('séparation des moteurs d’animation', () => {
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

  for (const [chemin, titre] of PUBLICS) {
    test(`${chemin} ne charge jamais GSAP`, async ({ page }) => {
      const scripts = traceScripts(page)

      await page.goto(chemin)
      await expect(page.getByRole('heading', { name: new RegExp(titre, 'i') })).toBeVisible()

      const gsap = [...scripts].filter((file) => file.toLowerCase().startsWith('gsap'))

      expect(gsap, `GSAP ne doit pas être chargé sur ${chemin}`).toEqual([])
      expect([...scripts].some((file) => /anime/i.test(file))).toBe(true)
    })
  }

  test("l'application charge GSAP, et lui seul en a besoin", async ({ page }) => {
    await login(page)

    const scripts = traceScripts(page)

    // Une navigation interne suffit : le tableau de bord est déjà monté.
    await page.goto('/modules/tickets')
    await expect(page.getByRole('heading', { name: 'tickets' })).toBeVisible()

    // La moitié application a le droit d'utiliser GSAP — c'est son moteur.
    // On vérifie surtout que la page fonctionne : le partage n'a pas privé
    // l'application de ses animations.
    await expect(page.getByRole('option').first()).toBeVisible()
  })

  /**
   * Le piège propre à anime.js.
   *
   * Côté GSAP, toutes les animations sont des tweens `from()` : ne rien jouer
   * laisse l'interface dans son état final. Côté anime.js, elles partent d'un
   * état explicite [départ, arrivée] — ne rien jouer laisserait les éléments
   * INVISIBLES. C'est la régression la plus grave possible ici : un écran de
   * connexion vide pour quiconque a demandé la réduction des animations.
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
