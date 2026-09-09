import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'

/**
 * Règles du client.
 *
 * Le formatage appartient à Prettier : ESLint ne se prononce que sur ce qui
 * peut casser (variable inutilisée, composant mal déclaré, clé manquante
 * dans un v-for). Faire arbitrer les deux au même endroit produit des
 * conflits sans fin.
 */
export default [
  {
    ignores: ['dist/**', 'node_modules/**', '.vite/**'],
  },

  js.configs.recommended,
  ...pluginVue.configs['flat/recommended'],

  {
    languageOptions: {
      ecmaVersion: 2023,
      sourceType: 'module',
      globals: {
        // Navigateur
        window: 'readonly',
        document: 'readonly',
        navigator: 'readonly',
        localStorage: 'readonly',
        performance: 'readonly',
        console: 'readonly',
        setTimeout: 'readonly',
        clearTimeout: 'readonly',
        setInterval: 'readonly',
        clearInterval: 'readonly',
        requestAnimationFrame: 'readonly',
        cancelAnimationFrame: 'readonly',
        IntersectionObserver: 'readonly',
        ResizeObserver: 'readonly',
        AbortController: 'readonly',
        Event: 'readonly',
        // Vite
        import: 'readonly',
      },
    },

    rules: {
      // Le formatage est du ressort de Prettier.
      'vue/max-attributes-per-line': 'off',
      'vue/singleline-html-element-content-newline': 'off',
      'vue/html-self-closing': 'off',
      'vue/html-indent': 'off',
      'vue/html-closing-bracket-newline': 'off',
      'vue/attributes-order': 'off',

      // Vue 3 autorise plusieurs racines : la règle vise Vue 2.
      'vue/no-multiple-template-root': 'off',

      // Un composant à un seul mot est acceptable pour les vues et layouts.
      'vue/multi-word-component-names': 'off',

      // Une variable inutilisée signale presque toujours un oubli.
      'no-unused-vars': ['error', { argsIgnorePattern: '^_', caughtErrors: 'none' }],

      'no-console': ['warn', { allow: ['warn', 'error'] }],
      eqeqeq: ['error', 'smart'],
      'prefer-const': 'error',
    },
  },

  {
    // Les outils de compilation tournent sous Node, pas dans le navigateur :
    // ils ont le droit à « process » et à la sortie standard, qui sont
    // justement leur moyen de rendre un verdict.
    files: ['scripts/**/*.mjs'],
    languageOptions: {
      globals: {
        process: 'readonly',
      },
    },
    rules: {
      'no-console': 'off',
    },
  },

  {
    // Les tests tournent sous Vitest, avec ses globales.
    files: ['tests/**/*.js'],
    languageOptions: {
      globals: {
        describe: 'readonly',
        it: 'readonly',
        expect: 'readonly',
        vi: 'readonly',
        beforeEach: 'readonly',
        afterEach: 'readonly',
      },
    },
    rules: {
      /**
       * Un fichier de test déclare souvent DEUX harnais : le cas nominal et
       * sa variante — un composant sans racine, un composant sans le contexte
       * attendu. Ce sont des montages jetables de trois lignes, pas des
       * composants d'application.
       *
       * La règle vise les fichiers « .vue », où un fichier est un composant.
       * Elle n'a pas de sens ici, et la contourner en éclatant les harnais en
       * fichiers séparés rendrait les tests moins lisibles pour satisfaire une
       * règle qui ne les concerne pas.
       */
      'vue/one-component-per-file': 'off',
    },
  },
]
