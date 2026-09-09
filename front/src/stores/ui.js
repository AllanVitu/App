import { ref } from 'vue'
import { defineStore } from 'pinia'

import * as sound from '@/services/sound'

/**
 * État d'interface transverse : thème, son, menu latéral et notifications.
 * Volontairement séparé du store d'authentification, qui ne concerne que
 * la session.
 */
export const useUiStore = defineStore('ui', () => {
  const THEMES = ['light', 'dark', 'system']

  const theme = ref(readStoredTheme())
  const sidebarOpen = ref(false)
  const toasts = ref([])

  let toastId = 0

  function readStoredTheme() {
    try {
      const stored = localStorage.getItem('theme')

      return THEMES.includes(stored) ? stored : 'system'
    } catch {
      // Navigation privée ou stockage bloqué : on retombe sur le système.
      return 'system'
    }
  }

  /**
   * Applique le thème à <html>. Le même calcul est fait dans index.html
   * avant le rendu, pour éviter un flash au chargement.
   *
   * C'est la classe « light » qui bascule, pas « dark » : le sombre est le
   * thème de référence de cette direction visuelle, donc l'état par défaut.
   */
  function applyTheme(next = theme.value) {
    theme.value = THEMES.includes(next) ? next : 'system'

    const prefersLight = window.matchMedia('(prefers-color-scheme: light)').matches
    const light = theme.value === 'light' || (theme.value === 'system' && prefersLight)

    document.documentElement.classList.toggle('light', light)

    try {
      localStorage.setItem('theme', theme.value)
    } catch {
      /* stockage indisponible : le thème reste actif pour la session */
    }
  }

  /**
   * Suit les changements de préférence système tant que le thème est « system ».
   */
  function watchSystemTheme() {
    window.matchMedia('(prefers-color-scheme: light)').addEventListener('change', () => {
      if (theme.value === 'system') applyTheme('system')
    })
  }

  // ---------------------------------------------------------------------------
  // Son
  //
  // L'état vit ici pour que l'interface puisse y réagir ; la synthèse et la
  // lecture restent dans services/sound.js. Le store ne fait que le miroir.
  // ---------------------------------------------------------------------------
  const soundOn = ref(false)

  /** true tant que l'utilisateur n'a jamais répondu : déclenche l'écran d'entrée. */
  const soundUndecided = ref(sound.storedPreference() === null)

  function setSound(value) {
    soundOn.value = sound.setEnabled(value)
    soundUndecided.value = false

    // La nappe d'ambiance suit le réglage, sans autre déclencheur.
    if (soundOn.value) {
      sound.startAmbient()
    } else {
      sound.stopAmbient()
    }

    return soundOn.value
  }

  function toggleSound() {
    return setSound(!soundOn.value)
  }

  /** Applique le choix déjà mémorisé, sans rien demander. */
  function restoreSound() {
    const stored = sound.storedPreference()

    if (stored === true) {
      // Le navigateur refusera de démarrer l'audio avant un geste : la nappe
      // se lancera au premier clic, via bindInterfaceSounds.
      soundOn.value = sound.setEnabled(true, { persist: false })
    }
  }

  function toggleSidebar(value) {
    sidebarOpen.value = value ?? !sidebarOpen.value
  }

  /**
   * Affiche une notification temporaire.
   *
   * @param {string} message
   * @param {'success'|'error'|'info'} type
   * @param {{ duration?: number, action?: { label: string, run: () => unknown } }} [options]
   */
  function notify(message, type = 'success', options = {}) {
    const { duration = 4000, action = null } = options

    const id = ++toastId
    toasts.value.push({ id, message, type, action })

    // Le son double le message, il ne le remplace pas : couper le son ne
    // fait rien perdre de l'information.
    sound.play(type === 'error' ? 'error' : type === 'info' ? 'notify' : 'success')

    setTimeout(() => dismiss(id), duration)

    return id
  }

  /**
   * Annonce une suppression, en offrant de la défaire.
   *
   * ┌─────────────────────────────────────────────────────────────────────┐
   * │  LA DONNÉE ÉTAIT DÉJÀ LÀ ; C'EST LE CHEMIN DE RETOUR QUI MANQUAIT   │
   * │                                                                     │
   * │  Toutes les suppressions de l'application sont logiques : la ligne  │
   * │  reste en base, marquée. Rien n'était perdu — et pourtant, du point │
   * │  de vue de celui qui venait de cliquer, c'était définitif.          │
   * └─────────────────────────────────────────────────────────────────────┘
   *
   * Le bandeau reste DEUX FOIS PLUS LONGTEMPS qu'un message ordinaire : le
   * temps de lire, de comprendre l'erreur, et d'atteindre le bouton. Quatre
   * secondes suffisent à confirmer ; elles ne suffisent pas à se rattraper.
   *
   * @param {string} message      ce qui vient d'être supprimé
   * @param {() => Promise<unknown>} restore  ce qu'il faut faire pour le rendre
   */
  function notifyUndo(message, restore) {
    return notify(message, 'info', {
      duration: 8000,
      action: { label: 'Annuler', run: restore },
    })
  }

  function dismiss(id) {
    toasts.value = toasts.value.filter((toast) => toast.id !== id)
  }

  return {
    theme,
    themes: THEMES,
    sidebarOpen,
    toasts,
    soundOn,
    soundUndecided,
    applyTheme,
    watchSystemTheme,
    setSound,
    toggleSound,
    restoreSound,
    toggleSidebar,
    notify,
    notifyUndo,
    dismiss,
  }
})
