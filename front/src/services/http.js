import axios from 'axios'

/**
 * Client HTTP unique de l'application.
 *
 * Deux responsabilités transverses y sont centralisées :
 *  1. l'ajout du jeton d'accès à chaque requête ;
 *  2. le rafraîchissement automatique de la session sur une réponse 401,
 *     puis le rejeu de la requête d'origine.
 *
 * Le jeton et la fonction de rafraîchissement sont injectés par le store
 * d'authentification : ce module n'importe aucun store, ce qui évite une
 * dépendance circulaire (store -> http -> store).
 */
const http = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || 'http://localhost:8080/api',
  // Indispensable pour que le cookie HttpOnly de rafraîchissement circule.
  withCredentials: true,
  timeout: 15000,
  headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
})

let accessToken = null
let refreshHandler = null
let onSessionExpired = null

/** Rafraîchissement en cours — évite N appels concurrents à /auth/refresh. */
let refreshPromise = null

export function setAccessToken(token) {
  accessToken = token
}

export function configureSession({ refresh, onExpired }) {
  refreshHandler = refresh
  onSessionExpired = onExpired
}

/**
 * Endpoints publics du parcours d'authentification : un 401/400 y est une
 * réponse métier légitime, il ne faut ni rafraîchir la session ni rejouer.
 * `/auth/me` et `/auth/email/resend` en sont volontairement absents : ce sont
 * des routes protégées, dont un 401 doit bien déclencher un rafraîchissement.
 */
function isAuthEndpoint(url = '') {
  return [
    '/auth/login',
    '/auth/register',
    '/auth/refresh',
    '/auth/logout',
    '/auth/email/verify',
    '/auth/password/forgot',
    '/auth/password/reset',
  ].some((path) => url.includes(path))
}

/**
 * Convertit une erreur axios en objet exploitable par les vues.
 * Les composants n'ont ainsi jamais à connaître la forme des réponses axios.
 */
function normalizeError(error) {
  if (axios.isCancel(error)) {
    return { canceled: true, status: 0, message: 'Requête annulée.', errors: {} }
  }

  if (!error.response) {
    return {
      status: 0,
      message:
        error.code === 'ECONNABORTED'
          ? 'Le serveur met trop de temps à répondre.'
          : "Impossible de joindre l'API. Vérifiez que les conteneurs Docker sont démarrés.",
      errors: {},
    }
  }

  const { status, data } = error.response

  return {
    status,
    message: data?.message || 'Une erreur est survenue.',
    // Erreurs de validation champ par champ, renvoyées par l'API en 422.
    errors: data?.errors || {},
  }
}

http.interceptors.request.use((config) => {
  if (accessToken) {
    config.headers.Authorization = `Bearer ${accessToken}`
  }

  return config
})

http.interceptors.response.use(
  (response) => response,
  async (error) => {
    const original = error.config

    const canRetry =
      error.response?.status === 401 &&
      original &&
      !original._retried &&
      !isAuthEndpoint(original.url) &&
      refreshHandler

    if (!canRetry) {
      return Promise.reject(normalizeError(error))
    }

    original._retried = true

    // Un seul appel à /auth/refresh, même si plusieurs requêtes ont échoué
    // simultanément : toutes attendent la même promesse. Elle est remise à
    // zéro par son propre finally, jamais depuis les appelants — sinon un
    // appelant pourrait l'annuler pendant qu'un autre l'attend encore.
    if (!refreshPromise) {
      refreshPromise = refreshHandler().finally(() => {
        refreshPromise = null
      })
    }

    try {
      await refreshPromise

      return http(original)
    } catch (refreshError) {
      onSessionExpired?.()

      return Promise.reject(normalizeError(refreshError))
    }
  },
)

export default http
