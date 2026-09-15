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
 * Des composants sont tout de même montés, dans deux cas — et jamais pour
 * constater qu'ils affichent un texte :
 *
 *   — un composable à cycle de vie (`onMounted`, `onScopeDispose`) n'existe
 *     que dans un composant ; son test en monte un vide, réduit à l'appeler ;
 *   — un composant partagé porte un CONTRAT envers ses appelants (ce qu'il
 *     émet, ce qu'il expose, ce qu'il laisse passer). C'est de la logique,
 *     elle se vérifie ici plutôt qu'en pilotant six écrans.
 *
 * D'où `@vue/test-utils`, sans que le périmètre change pour autant.
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
    // Vitest 3 a séparé les deux gestes : « restore » ne remet plus à zéro
    // l'historique d'appels d'un vi.fn(). Sans « clear », un appel fait dans
    // un test déborderait sur le suivant, qui le verrait comme le sien.
    clearMocks: true,
  },
})
