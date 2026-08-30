import { expect, test as base } from '@playwright/test'

/** Compte créé par le jeu de données de développement. */
export const DEMO = {
  email: 'demo@saas.local',
  password: 'Password123!',
}

/**
 * Test outillé pour l'application.
 *
 * Chaque test démarre avec un profil vierge : l'écran d'entrée sonore
 * s'afficherait et masquerait toute l'interface. On répond « sans le son »
 * avant le premier rendu — un test n'a rien à faire d'un fond sonore, et
 * l'écran lui-même est couvert par son propre test.
 */
export const test = base.extend({
  page: async ({ page }, use) => {
    await page.addInitScript(() => {
      try {
        window.localStorage.setItem('sound', 'off')
      } catch {
        /* stockage indisponible : l'écran s'affichera, le test le dira */
      }
    })

    await use(page)
  },
})

export { expect }

/** Une seule tentative supplémentaire — voir le commentaire de login(). */
const LOGIN_ATTEMPTS = 2

/**
 * Ouvre une session via l'interface, pas par un raccourci API : le parcours
 * de connexion est lui-même ce que l'on veut voir fonctionner.
 *
 * UNE seule nouvelle tentative est accordée, et uniquement sur un DÉLAI
 * DÉPASSÉ côté client — jamais sur un refus d'identifiants, qui reste une
 * erreur franche.
 *
 * La raison n'est pas de masquer une lenteur de l'application : mesurée, la
 * connexion tient en 0,5 à 1,1 s (bcrypt au coût 12, volontairement). Mais la
 * suite complète enchaîne près de trente sessions sur un poste où le serveur
 * de développement recompile en même temps, et le client abandonne à 15 s.
 * Ouvrir la session est un PRÉALABLE à chaque test, jamais son objet : la
 * rejouer une fois ne rend donc aucun test complaisant, là où « retries » au
 * niveau de la suite masquerait de vraies régressions (cf. playwright.config).
 */
export async function login(page, credentials = DEMO) {
  for (let attempt = 1; attempt <= LOGIN_ATTEMPTS; attempt += 1) {
    await page.goto('/connexion')

    await page.getByLabel(/adresse e-mail/i).fill(credentials.email)
    await page.getByLabel(/mot de passe/i).fill(credentials.password)
    await page.getByRole('button', { name: /se connecter/i }).click()

    // Course entre l'arrivée sur le tableau de bord et le message d'erreur :
    // attendre l'un puis l'autre ferait patienter tout le délai d'expiration
    // avant de découvrir que la page affichait l'erreur depuis le début.
    const arrive = await Promise.race([
      page
        .waitForURL('/', { timeout: 20_000 })
        .then(() => true)
        .catch(() => false),
      page
        .getByRole('alert')
        .waitFor({ state: 'visible', timeout: 20_000 })
        .then(() => false)
        .catch(() => false),
    ])

    if (arrive) {
      await expect(page.getByRole('heading', { name: /bonjour/i })).toBeVisible()

      return
    }

    const message = (await page.getByRole('alert').textContent()) ?? ''

    // Un refus d'identifiants ne se rejoue pas : il dit quelque chose de vrai.
    if (!/trop de temps/i.test(message) || attempt === LOGIN_ATTEMPTS) {
      throw new Error(`Connexion impossible : ${message.trim() || 'aucun message'}`)
    }
  }
}
