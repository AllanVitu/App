import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

/**
 * Configuration Vite.
 *
 * Le serveur de développement tourne DANS le conteneur "node" : il doit
 * écouter sur 0.0.0.0 et exposer un port HMR fixe pour être joignable
 * depuis Windows.
 */
export default defineConfig({
  plugins: [vue(), tailwindcss()],

  resolve: {
    alias: {
      // « @/components/... » plutôt que « ../../components/... »
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },

  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    // Bind mount Windows -> Linux : les événements inotify ne remontent pas,
    // le watcher doit interroger le système de fichiers.
    //
    // C'EST LA PRINCIPALE SOURCE DE LENTEUR du poste de développement, et elle
    // n'a rien à voir avec le code de l'application. Mesuré : le conteneur
    // node brûlait 9,6 % de processeur AU REPOS, navigateur fermé, quand tous
    // les autres étaient à 0 %.
    //
    // Deux réglages y remédient sans casser le rechargement à chaud :
    //
    //  - « ignored » : sans lui, la scrutation balaie aussi node_modules —
    //    des dizaines de milliers de fichiers qui ne changent jamais.
    //  - « interval » : 300 ms signifiait plus de trois balayages complets par
    //    seconde. Une seconde de délai pour détecter une frappe est
    //    imperceptible à l'usage, et divise le travail par trois.
    watch: {
      usePolling: true,
      interval: 1000,
      ignored: ['**/node_modules/**', '**/dist/**', '**/.git/**', '**/.playwright/**'],
    },
    // Le websocket HMR passe par le port du serveur (5173), déjà publié par
    // Docker. Lui donner un port dédié le ferait écouter sur ::1 dans le
    // conteneur, donc injoignable depuis Windows : le rechargement à chaud
    // serait silencieusement cassé.
    hmr: { clientPort: 5173 },
  },

  build: {
    outDir: 'dist',
    sourcemap: false,
    // Les dépendances stables sont isolées : elles restent en cache navigateur
    // entre deux déploiements applicatifs.
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (id.includes('node_modules/animejs')) {
            // Le module de MISE EN PAGE (l'équivalent de Flip) pèse à lui
            // seul près de la moitié d'anime.js, et ne sert qu'à deux vues de
            // l'application. Dans le même lot que le reste, il était chargé
            // par l'écran de connexion, qui n'anime aucune liste.
            //
            // Ce découpage ne suffit pas seul : il faut aussi qu'aucun module
            // du tronc commun ne le référence, sans quoi le lot serait tiré
            // malgré tout (cf. animations/layout.js).
            if (id.includes('/layout/')) return 'anime-layout'

            // Même raisonnement pour le SVG : le morphing d'icône ne sert
            // qu'à la bascule de thème, et le tracé progressif qu'aux deux
            // écrans de confirmation. Aucun des deux n'a sa place dans le
            // premier chargement.
            if (id.includes('/svg/')) return 'anime-svg'

            return 'anime'
          }

          if (id.includes('node_modules')) return 'vendor'

          return undefined
        },
      },
    },
  },
})
