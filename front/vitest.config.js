import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

/**
 * Tests unitaires du client.
 *
 * Périmètre volontairement restreint à la LOGIQUE : formatage, intercepteur
 * HTTP, composables. Le rendu et les parcours sont couverts par Playwright,
 * dans un vrai navigateur — monter des composants dans jsdom pour vérifier
 * qu'ils affichent un texte n'apporterait pas grand-chose de plus.
 *
 * Un composable à cycle de vie (`onMounted`, `onScopeDispose`) fait exception :
 * il n'existe que dans un composant, donc son test en monte un — vide, réduit
 * à appeler le composable. Ce qui est vérifié reste la logique, jamais le
 * rendu ; c'est pourquoi `@vue/test-utils` est là sans que le périmètre change.
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
