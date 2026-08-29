import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

/**
 * Tests unitaires du client.
 *
 * Périmètre volontairement restreint à la logique : formatage, stores,
 * intercepteur HTTP. Le rendu et les parcours sont couverts par Playwright,
 * dans un vrai navigateur — monter des composants dans jsdom pour vérifier
 * qu'ils affichent un texte n'apporterait pas grand-chose de plus.
 */
export default defineConfig({
  plugins: [vue()],

  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },

  test: {
    environment: 'jsdom',
    include: ['tests/**/*.spec.js'],
    globals: true,
    restoreMocks: true,
  },
})
