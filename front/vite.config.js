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
    watch: { usePolling: true, interval: 300 },
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
    //
    // Les DEUX moteurs d'animation ont chacun leur lot, et c'est structurant :
    // le front est coupé en deux moitiés qui n'en chargent qu'un chacune —
    // anime.js pour les écrans publics, GSAP pour l'application. Laissés dans
    // « vendor », ils seraient chargés par tout le monde et la séparation
    // n'existerait que sur le papier. C'est aussi ce qui rend leur coût
    // lisible dans le rapport de compilation plutôt que noyé dans un bloc.
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (id.includes('node_modules/gsap')) return 'gsap'
          if (id.includes('node_modules/animejs')) return 'anime'
          if (id.includes('node_modules')) return 'vendor'

          return undefined
        },
      },
    },
  },
})
