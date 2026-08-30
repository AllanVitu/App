import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { bindInterfaceSounds, play, setSuspended } from './services/sound'
import { useUiStore } from './stores/ui'

// Aucune bibliothèque d'animation n'est importée ici — et c'est délibéré.
//
// « @/animations/gsap » l'était, pour enregistrer les plugins une fois pour
// toutes. L'effet de bord était que GSAP se retrouvait dans le morceau
// d'entrée, donc chargé par TOUTES les pages : l'écran de connexion payait
// 92 Ko compressés pour animer un logotype.
//
// Le module s'auto-enregistre à son premier import, quel qu'il soit : les
// composants qui en ont besoin l'importent, et le compilateur le place dans
// un morceau à part, chargé avec eux. La moitié publique, elle, passe par
// « @/animations/anime » et ne voit jamais GSAP.
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

// Les écrans publics sont muets : on n'accueille pas quelqu'un avec du son,
// et la préférence de l'utilisateur est conservée pendant la traversée.
router.afterEach((to) => {
  setSuspended(Boolean(to.meta.silent))

  // Changement de page : une brève impulsion, comme un cran.
  play('tick')
})

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
