import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
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

app.use(router)

app.mount('#app')
