import { defineConfig, devices } from '@playwright/test'

/**
 * Parcours de bout en bout.
 *
 * S'exécutent contre la stack Docker déjà démarrée (`docker compose up -d`),
 * depuis l'hôte : Playwright ne publie pas de binaires pour Alpine, et le
 * conteneur node en est un.
 *
 *   cd e2e && npm install && npx playwright install chromium && npm test
 */
export default defineConfig({
  testDir: './tests',
  outputDir: './.playwright',

  // Un seul worker : les tests partagent le compte de démonstration et
  // écrivent dans la même base. En parallèle, ils se marcheraient dessus.
  workers: 1,
  fullyParallel: false,

  // En intégration continue, un test qui ne passe qu'une fois sur deux est
  // un test qui ment : `retries: 0` force à corriger la cause.
  retries: 0,
  timeout: 30_000,
  expect: { timeout: 10_000 },

  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : [['list']],

  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5173',
    locale: 'fr-FR',
    timezoneId: 'Europe/Paris',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
})
