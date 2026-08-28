import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

import { authApi } from '@/services/api'
import { configureSession, setAccessToken } from '@/services/http'

/**
 * Session de l'utilisateur courant.
 *
 * Le jeton d'accès est gardé EN MÉMOIRE uniquement, jamais dans localStorage :
 * une faille XSS ne peut donc pas l'exfiltrer. La persistance entre deux
 * rechargements de page est assurée par le cookie HttpOnly de
 * rafraîchissement, inaccessible au JavaScript — c'est le rôle de initialize().
 */
export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const settings = ref(null)
  const accessToken = ref(null)

  /** Passe à true une fois la session restaurée (ou son absence confirmée). */
  const ready = ref(false)

  const isAuthenticated = computed(() => user.value !== null && accessToken.value !== null)

  const initials = computed(() => {
    if (!user.value?.full_name) return '?'

    return user.value.full_name
      .split(' ')
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0].toUpperCase())
      .join('')
  })

  /**
   * Enregistre la session issue de /auth/login, /auth/register ou /auth/refresh.
   */
  function applySession(payload) {
    user.value = payload.user
    accessToken.value = payload.access_token
    setAccessToken(payload.access_token)
  }

  function clearSession() {
    user.value = null
    settings.value = null
    accessToken.value = null
    setAccessToken(null)
  }

  async function login(credentials) {
    applySession(await authApi.login(credentials))
    await loadProfile()
  }

  async function register(payload) {
    applySession(await authApi.register(payload))
    await loadProfile()
  }

  /**
   * Rafraîchit le jeton d'accès à partir du cookie HttpOnly.
   * Appelée au démarrage et par l'intercepteur HTTP sur un 401.
   */
  async function refresh() {
    applySession(await authApi.refresh())
  }

  async function loadProfile() {
    const payload = await authApi.me()
    user.value = payload.user
    settings.value = payload.settings
  }

  async function logout() {
    try {
      await authApi.logout()
    } finally {
      // Même si l'appel échoue (réseau coupé), la session locale est vidée :
      // l'utilisateur ne doit jamais rester bloqué en état « connecté ».
      clearSession()
    }
  }

  /**
   * Restaure la session au démarrage de l'application.
   * L'absence de cookie valide est un cas NORMAL (visiteur non connecté) :
   * elle ne doit pas remonter comme une erreur.
   */
  async function initialize() {
    if (ready.value) return

    try {
      await refresh()
      await loadProfile()
    } catch {
      clearSession()
    } finally {
      ready.value = true
    }
  }

  function setUser(updated) {
    user.value = updated
  }

  function setSettings(updated) {
    settings.value = updated
  }

  // Branche le client HTTP : il sait désormais rafraîchir la session et
  // signaler son expiration définitive.
  configureSession({ refresh, onExpired: clearSession })

  return {
    user,
    settings,
    accessToken,
    ready,
    isAuthenticated,
    initials,
    login,
    register,
    logout,
    refresh,
    initialize,
    loadProfile,
    setUser,
    setSettings,
  }
})
