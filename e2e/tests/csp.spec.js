import { expect, login, test } from './support.js'

/**
 * La politique de sécurité du contenu, sous un vrai navigateur.
 *
 * Elle n'existe qu'en déploiement : le serveur de développement de Vite n'en
 * pose aucune — il écrit même ses styles dans la page. Ce parcours se lance
 * donc contre l'image web de PRODUCTION, branchée sur l'API de développement :
 *
 *   docker build -f docker/front/Dockerfile -t saas-web-csp .
 *   docker run -d --rm --name saas_web_csp --network saas_net -p 8090:80 saas-web-csp
 *   E2E_BASE_URL=http://localhost:8090 npx playwright test csp
 *
 * Contre le serveur de développement, il se déclare ignoré, et dit pourquoi.
 * front/scripts/check-csp.mjs vérifie la compilation ; ceci vérifie ce que le
 * navigateur en fait — les styles posés par Vue et anime.js, les lots chargés
 * à la demande, les polices.
 */

const ECRANS = [
  '/',
  '/modules/tickets',
  '/modules/deploiement',
  '/modules/supervision',
  '/modules/design',
  '/modules/backend',
  '/equipe',
  '/historique',
  '/profil',
  '/parametres',
]

/** Écoute les violations avant que la page ait exécuté une seule ligne. */
async function ecouter(page, baseURL) {
  const tiers = []
  const origine = new URL(baseURL).origin

  await page.addInitScript(() => {
    window.__violations = []
    document.addEventListener('securitypolicyviolation', (event) => {
      window.__violations.push(`${event.violatedDirective} : ${event.blockedURI || 'en ligne'}`)
    })
  })

  page.on('request', (requete) => {
    const url = new URL(requete.url())
    if (url.protocol.startsWith('http') && url.origin !== origine) tiers.push(url.href)
  })

  return tiers
}

const violations = (page) => page.evaluate(() => window.__violations)

test.describe('politique de sécurité du contenu', () => {
  test.beforeEach(async ({ request }) => {
    const reponse = await request.get('/connexion')

    test.skip(
      !reponse.headers()['content-security-policy'],
      'aucune CSP sur ce serveur : à lancer contre l’image de production (cf. en-tête du fichier)',
    )
  })

  test('la politique refuse tout ce qui est écrit dans la page', async ({ request }) => {
    const politique = (await request.get('/connexion')).headers()['content-security-policy']

    expect(politique).toContain("script-src 'self';")
    expect(politique).toContain("style-src 'self';")
    expect(politique).not.toContain('unsafe-inline')
    expect(politique).not.toMatch(/https?:/)
  })

  test('le thème et les polices arrivent sans rien demander à un tiers', async ({
    browser,
    baseURL,
  }) => {
    // Un contexte sans session : rien, ici, ne doit toucher au compte de
    // démonstration, que les autres parcours partagent.
    const contexte = await browser.newContext()
    const page = await contexte.newPage()
    const tiers = await ecouter(page, baseURL)

    await page.addInitScript(() => {
      try {
        localStorage.setItem('densite', 'compact')
      } catch {
        /* about:blank n'a pas de stockage */
      }
    })

    await page.goto('/connexion')

    // Posé par public/theme-boot.js : si la politique refusait ce script,
    // l'attribut manquerait.
    await expect(page.locator('html')).toHaveAttribute('data-densite', 'compact')

    // Par sondage : « document.fonts.ready » se résout dès qu'aucun
    // chargement n'est EN COURS — y compris avant que l'application ait rendu
    // le premier mot qui en demande un.
    await expect
      .poll(() =>
        page.evaluate(() =>
          [...document.fonts]
            .filter((police) => police.status === 'loaded')
            .map((police) => police.family.replaceAll('"', '')),
        ),
      )
      .toContain('Archivo Variable')
    expect(tiers).toEqual([])
    expect(await violations(page)).toEqual([])

    await contexte.close()
  })

  test('aucun écran de l’application ne déclenche de violation', async ({ page, baseURL }) => {
    const tiers = await ecouter(page, baseURL)
    const refus = []

    page.on('console', (message) => {
      if (/Content Security Policy/i.test(message.text())) {
        refus.push(`${new URL(page.url()).pathname} → ${message.text()}`)
      }
    })

    await login(page)

    for (const ecran of ECRANS) {
      await page.goto(ecran)
      await expect(page.locator('main').first()).toBeVisible()
      await page.evaluate(async () => {
        await document.fonts.ready
      })

      for (const violation of await violations(page)) refus.push(`${ecran} → ${violation}`)
    }

    expect(refus).toEqual([])
    expect(tiers).toEqual([])
  })
})
