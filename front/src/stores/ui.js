import { ref } from 'vue'
import { defineStore } from 'pinia'

/**
 * État d'interface transverse : thème, menu latéral et notifications.
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
   * avant le rendu, pour éviter un flash clair au chargement.
   */
  function applyTheme(next = theme.value) {
    theme.value = THEMES.includes(next) ? next : 'system'

    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches
    const dark = theme.value === 'dark' || (theme.value === 'system' && prefersDark)

    document.documentElement.classList.toggle('dark', dark)

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
    window
      .matchMedia('(prefers-color-scheme: dark)')
      .addEventListener('change', () => {
        if (theme.value === 'system') applyTheme('system')
      })
  }

  function toggleSidebar(value) {
    sidebarOpen.value = value ?? !sidebarOpen.value
  }

  /**
   * Affiche une notification temporaire.
   *
   * @param {'success'|'error'|'info'} type
   */
  function notify(message, type = 'success', duration = 4000) {
    const id = ++toastId
    toasts.value.push({ id, message, type })

    setTimeout(() => dismiss(id), duration)

    return id
  }

  function dismiss(id) {
    toasts.value = toasts.value.filter((toast) => toast.id !== id)
  }

  return {
    theme,
    themes: THEMES,
    sidebarOpen,
    toasts,
    applyTheme,
    watchSystemTheme,
    toggleSidebar,
    notify,
    dismiss,
  }
})
