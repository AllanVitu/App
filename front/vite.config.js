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
    // GSAP a son propre lot : avec l'ensemble des plugins enregistrés, il pèse
    // plus que tout le reste réuni. L'isoler évite qu'une modification du code
    // applicatif n'invalide son cache — et rend son coût visible dans le
    // rapport de build plutôt que noyé dans le bundle principal.
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (id.includes('node_modules/gsap')) return 'gsap'
          if (id.includes('node_modules')) return 'vendor'

          return undefined
        },
      },
    },
  },
})
