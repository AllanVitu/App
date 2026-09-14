import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

import { authApi, organizationsApi } from '@/services/api'
import { configureSession, setAccessToken } from '@/services/http'
import { setTimeZone } from '@/utils/format'
// Importé pour ce seul appel : les préférences d'affichage arrivent ici, et
// c'est le store d'interface qui sait les poser sur le document.
import { useUiStore } from '@/stores/ui'

/**
 * Session de l'utilisateur courant.
 *
 * Le jeton d'accès est gardé EN MÉMOIRE uniquement, jamais dans localStorage :
 * une faille XSS ne peut donc pas l'exfiltrer. La persistance entre deux
 * rechargements de page est assurée par le cookie HttpOnly de
 * rafraîchissement, inaccessible au JavaScript — c'est le rôle de initialize().
 */
/**
 * « Une session a existé sur ce navigateur. »
 *
 * Ce n'est PAS un secret : il n'ouvre rien, le cookie de rafraîchissement reste
 * HttpOnly. Il évite seulement de demander au serveur une session qu'aucune
 * connexion n'a jamais ouverte — un 401 par page publique, rouge dans la
 * console de chaque visiteur.
 *
 * Stockage indisponible (navigation privée stricte) : on tente, comme avant.
 */
const INDICE = 'relais.session'

function indiceDeSession() {
  try {
    return window.localStorage.getItem(INDICE) !== null
  } catch {
    return true
  }
}

function marquerSession(ouverte) {
  try {
    if (ouverte) window.localStorage.setItem(INDICE, '1')
    else window.localStorage.removeItem(INDICE)
  } catch {
    // Sans stockage, la restauration sera simplement tentée à chaque démarrage.
  }
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const settings = ref(null)
  const accessToken = ref(null)

  /**
   * Espace de travail courant, et la liste de ceux auxquels le compte
   * appartient.
   *
   * ┌───────────────────────────────────────────────────────────────────────┐
   * │  LE CLIENT NE CHOISIT PAS LE CLOISONNEMENT, IL LE CONSTATE            │
   * │                                                                       │
   * │  Aucune requête ne porte d'identifiant d'espace : le serveur le résout │
   * │  à chaque appel depuis l'appartenance en base. Ce qui est gardé ici    │
   * │  sert à AFFICHER — un nom dans le sélecteur, un rôle qui décide des    │
   * │  commandes montrées — jamais à filtrer.                               │
   * │                                                                       │
   * │  Conséquence : masquer un bouton selon « role » est un confort, pas    │
   * │  une sécurité. Les routes le vérifient de leur côté.                  │
   * └───────────────────────────────────────────────────────────────────────┘
   */
  const organization = ref(null)
  const organizations = ref([])

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
    marquerSession(true)

    // Présents sur /login, /register et /refresh : la session est complète dès
    // le premier appel, sans aller-retour supplémentaire pour savoir où l'on
    // se trouve.
    if (payload.organization) organization.value = payload.organization
    if (payload.organizations) organizations.value = payload.organizations
  }

  function clearSession() {
    user.value = null
    settings.value = null
    organization.value = null
    organizations.value = []
    setTimeZone(null)
    accessToken.value = null
    setAccessToken(null)
    marquerSession(false)
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
    organization.value = payload.organization ?? null
    organizations.value = payload.organizations ?? []
    applySettings(payload.settings)
  }

  /**
   * Bascule d'espace de travail.
   *
   * TOUT ce qui est affiché change : les cinq modules, le tableau de bord, la
   * recherche. Plutôt que d'essayer de rafraîchir chaque écran ouvert — et
   * d'en oublier un — l'appelant recharge la page. C'est le geste franc, et il
   * est rare.
   */
  async function switchOrganization(id) {
    if (id === organization.value?.id) return organization.value

    organization.value = await organizationsApi.activate(id)

    return organization.value
  }

  /**
   * Recharge la liste des espaces, après en avoir créé, renommé ou quitté un.
   */
  async function reloadOrganizations() {
    organizations.value = await organizationsApi.list()
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

    // Aucune connexion n'a jamais eu lieu ici : il n'y a rien à restaurer.
    if (!indiceDeSession()) {
      ready.value = true
      return
    }

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
    applySettings(updated)
  }

  /**
   * Enregistre les paramètres ET les APPLIQUE.
   *
   * Le fuseau horaire ne sert à rien tant qu'il reste une valeur en base :
   * c'est ici, au seul endroit où les paramètres arrivent, qu'il est poussé
   * dans les formateurs de dates. Le faire dans l'écran Paramètres aurait
   * laissé le reste de l'application au fuseau du navigateur tant qu'on n'y
   * serait pas passé.
   */
  function applySettings(updated) {
    settings.value = updated
    setTimeZone(updated?.timezone ?? null)

    // La densité et la réduction de mouvement suivent le même chemin, et pour
    // la même raison : posées ici, au seul endroit où les préférences
    // arrivent, elles valent pour toute l'application dès la connexion. Les
    // appliquer depuis l'écran Paramètres aurait laissé le reste de
    // l'interface au réglage par défaut tant qu'on n'y serait pas passé.
    useUiStore().applyDisplay(updated ?? {})
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
    organization,
    organizations,
    setUser,
    setSettings,
    switchOrganization,
    reloadOrganizations,
  }
})
