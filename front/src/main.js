import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { bindInterfaceSounds, play } from './services/sound'
import { useUiStore } from './stores/ui'

// Enregistre les plugins GSAP et définit les courbes partagées. Importé une
// seule fois, au démarrage : les composants importent ensuite « @/animations/gsap ».
import './animations/gsap'

import './assets/css/main.css'

const app = createApp(App)

app.use(createPinia())

// Le thème est appliqué avant le montage du routeur : les gardes de
// navigation peuvent déclencher un rendu, qui doit déjà être dans la
// bonne palette.
const ui = useUiStore()
ui.applyTheme()
ui.watchSystemTheme()

// Choix sonore d'une visite précédente. Le contexte audio, lui, ne démarrera
// qu'au premier geste — la politique des navigateurs l'impose.
ui.restoreSound()

// Survols et clics sonores, par délégation sur le document : un bouton
// ajouté demain sonnera sans qu'on ait à le déclarer.
bindInterfaceSounds()

// Changement de page : une brève impulsion, comme un cran.
router.afterEach(() => play('tick'))

/**
 * Dernier filet : une exception dans un composant démonterait tout l'arbre
 * et laisserait une page blanche, sans message ni moyen de revenir. On la
 * journalise et on prévient l'utilisateur.
 */
app.config.errorHandler = (error, instance, info) => {
  console.error('[app]', info, error)
  ui.notify("Une erreur inattendue est survenue. L'action n'a pas abouti.", 'error')
}

app.use(router)

app.mount('#app')
