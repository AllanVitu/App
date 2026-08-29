import { DEMO, expect, login, test } from './support.js'

/**
 * Session et protection des routes.
 *
 * Remplace les scripts jetables pilotés à la main par le protocole DevTools :
 * même couverture, mais versionnée et exécutable par la CI.
 */
test.describe('authentification', () => {
  test('la page de connexion s’affiche', async ({ page }) => {
    await page.goto('/connexion')

    await expect(page.getByRole('heading', { name: 'Connexion' })).toBeVisible()
    await expect(page.getByLabel(/adresse e-mail/i)).toBeVisible()
  })

  test('des identifiants erronés sont refusés sans révéler pourquoi', async ({ page }) => {
    await page.goto('/connexion')

    await page.getByLabel(/adresse e-mail/i).fill(DEMO.email)
    await page.getByLabel(/mot de passe/i).fill('MauvaisMotDePasse1')
    await page.getByRole('button', { name: /se connecter/i }).click()

    await expect(page.getByRole('alert')).toContainText('Identifiants incorrects')
    await expect(page).toHaveURL(/connexion/)
  })

  test('la connexion mène au tableau de bord', async ({ page }) => {
    await login(page)

    await expect(page.getByText('Vos modules')).toBeVisible()
    await expect(page.getByText('Activité récente')).toBeVisible()
  })

  test('le menu latéral est alimenté par l’API', async ({ page }) => {
    await login(page)

    const nav = page.getByRole('navigation', { name: /navigation principale/i })

    // Les modules viennent de la base : le module 4 n'existe pas, et le menu
    // ne doit donc pas l'inventer.
    await expect(nav.getByRole('link', { name: /backend/i })).toBeVisible()
    await expect(nav.getByRole('link', { name: /design/i })).toBeVisible()
    await expect(nav.getByRole('link', { name: /inexistant/i })).toHaveCount(0)
  })

  test('les dates relatives sont calculées', async ({ page }) => {
    await login(page)

    // Un « — » signalerait que le format d'horodatage de l'API n'est plus
    // parsable par le navigateur : le défaut s'était déjà produit.
    const activite = page.locator('section', { hasText: 'Activité récente' })
    await expect(activite).not.toContainText('· —')
  })

  test('la session survit à un rechargement', async ({ page }) => {
    await login(page)

    // Le jeton d'accès vit en mémoire : c'est le cookie HttpOnly de
    // rafraîchissement qui doit rouvrir la session.
    await page.reload()

    await expect(page).toHaveURL('/')
    await expect(page.getByRole('heading', { name: /bonjour/i })).toBeVisible()
  })

  test('une route protégée redirige un visiteur non connecté', async ({ page }) => {
    await page.goto('/parametres')

    await expect(page).toHaveURL(/connexion/)
  })

  test('la déconnexion ferme la session', async ({ page }) => {
    await login(page)

    await page.getByRole('button', { name: /menu du compte/i }).click()
    await page.getByRole('menuitem', { name: /se déconnecter/i }).click()

    await expect(page).toHaveURL(/connexion/)

    // Et la session est réellement fermée côté serveur.
    await page.goto('/parametres')
    await expect(page).toHaveURL(/connexion/)
  })

  test('une adresse inconnue affiche la page 404', async ({ page }) => {
    await page.goto('/nimporte-quoi')

    await expect(page.getByRole('heading', { name: /page introuvable/i })).toBeVisible()
  })
})

test.describe('préférences', () => {
  test('le thème sombre s’applique et persiste', async ({ page }) => {
    await login(page)
    await page.goto('/parametres')

    await page.getByRole('button', { name: 'Sombre', exact: true }).click()
    await expect(page.locator('html')).not.toHaveClass(/light/)

    // Appliqué avant le premier rendu au rechargement : pas de flash clair.
    await page.reload()
    await expect(page.locator('html')).not.toHaveClass(/light/)

    await page.getByRole('button', { name: 'Clair', exact: true }).click()
    await expect(page.locator('html')).toHaveClass(/light/)
  })
})
