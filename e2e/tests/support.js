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

/**
 * Ouvre une session via l'interface, pas par un raccourci API : le parcours
 * de connexion est lui-même ce que l'on veut voir fonctionner.
 */
export async function login(page, credentials = DEMO) {
  await page.goto('/connexion')

  await page.getByLabel(/adresse e-mail/i).fill(credentials.email)
  await page.getByLabel(/mot de passe/i).fill(credentials.password)
  await page.getByRole('button', { name: /se connecter/i }).click()

  await expect(page).toHaveURL('/')
  await expect(page.getByRole('heading', { name: /bonjour/i })).toBeVisible()
}

/**
 * Crée un élément dans un module et renvoie son titre.
 * Le titre porte un suffixe aléatoire : deux exécutions successives ne
 * peuvent pas se confondre si un nettoyage a échoué.
 */
export async function createItem(page, slug, { title, status = 'active' } = {}) {
  const unique = title ?? `Recette ${Math.random().toString(36).slice(2, 8)}`

  await page.goto(`/modules/${slug}`)
  await page.getByRole('button', { name: /nouvel élément/i }).click()

  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible()

  await dialog.getByLabel(/titre/i).fill(unique)
  await dialog.getByLabel(/statut/i).selectOption(status)
  await dialog.getByRole('button', { name: /^créer$/i }).click()

  await expect(dialog).toBeHidden()
  await expect(page.getByText(unique, { exact: true })).toBeVisible()

  return unique
}

/** Supprime un élément par son titre, en confirmant la boîte de dialogue. */
export async function deleteItem(page, title) {
  const row = page.locator('li').filter({ hasText: title })

  await row.getByRole('button', { name: /^supprimer/i }).click()
  await page.getByRole('dialog').getByRole('button', { name: /^supprimer$/i }).click()

  await expect(page.getByText(title, { exact: true })).toBeHidden()
}
