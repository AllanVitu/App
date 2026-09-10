import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

import { streamApi } from '@/services/api'

/**
 * Ce qui a changé chez les autres, et qui est là.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  UN SONDAGE, ET C'EST UN CHOIX                                          │
 * │                                                                         │
 * │  Sous PHP-FPM, un flux SSE ouvert immobilise un processus enfant POUR    │
 * │  TOUTE SA DURÉE. Le nombre d'enfants est fini : dix coéquipiers avec     │
 * │  l'application ouverte, et il ne reste plus personne pour répondre aux   │
 * │  requêtes ordinaires. L'API se bloquerait elle-même, et la panne         │
 * │  ressemblerait à une lenteur réseau.                                    │
 * │                                                                         │
 * │  Trois secondes, et seulement pendant que l'onglet est VISIBLE. Une      │
 * │  fenêtre en arrière-plan ne sert personne : la couper divise le trafic   │
 * │  par le nombre d'onglets oubliés, qui est le grand nombre.               │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * COMPLÉMENTAIRE DE useRevalidate, PAS SON REMPLAÇANT. Celui-ci suit les
 * changements pendant qu'on regarde ; l'autre relit tout au retour d'une
 * absence, quand le flux a été coupé et qu'un rattrapage événement par
 * événement n'aurait plus de sens.
 */

/** Battement, en millisecondes. */
const INTERVALLE = 3000

/**
 * Après un échec, on espace au lieu d'insister.
 *
 * Un serveur qui tombe verrait sinon arriver une requête toutes les trois
 * secondes multipliée par chaque onglet ouvert — exactement au moment où il
 * en a le moins besoin.
 */
const REPOS_MAX = 30000

/**
 * @param {object}   options
 * @param {string}   options.screen    Écran courant, pour la présence.
 * @param {Function} [options.subject] Sujet ouvert, s'il y en a un.
 * @param {Function} [options.onEvents] Reçoit les événements des AUTRES.
 * @param {Function} [options.onDistanced] Trop de retard : l'appelant recharge.
 */
export function useLiveStream({ screen, subject, onEvents, onDistanced } = {}) {
  /** Null tant qu'on n'a rien reçu : c'est ainsi que se dit « premier appel ». */
  const cursor = ref(null)
  const presence = ref([])
  const connected = ref(false)

  let minuteur = null
  let controleur = null
  let repos = INTERVALLE
  let arrete = false

  function visible() {
    return typeof document === 'undefined' || document.visibilityState === 'visible'
  }

  async function battre() {
    if (arrete || !visible()) return

    // Un sondage encore en vol est abandonné : c'est toujours le dernier qui
    // compte, et deux réponses en désordre feraient reculer le curseur.
    controleur?.abort()
    controleur = new AbortController()

    try {
      const reponse = await streamApi.poll(
        {
          cursor: cursor.value,
          screen,
          subject: typeof subject === 'function' ? subject() : subject,
        },
        controleur.signal,
      )

      cursor.value = reponse.cursor
      presence.value = reponse.presence
      connected.value = true
      repos = INTERVALLE

      if (reponse.distanced) {
        // Rattraper deux cents changements un par un ferait clignoter l'écran
        // plus longtemps qu'un rechargement franc.
        onDistanced?.()
      } else if (reponse.events.length) {
        onEvents?.(reponse.events)
      }
    } catch (erreur) {
      if (erreur?.canceled) return

      connected.value = false

      // Recul progressif, plafonné. Le flux est un CONFORT : son échec ne doit
      // jamais produire de message. L'écran reste juste, simplement figé, et
      // useRevalidate le rattrapera au retour sur l'onglet.
      repos = Math.min(repos * 2, REPOS_MAX)
    }
  }

  function planifier() {
    clearTimeout(minuteur)

    if (arrete) return

    minuteur = setTimeout(async () => {
      await battre()
      planifier()
    }, repos)
  }

  /**
   * Le retour sur l'onglet ne se contente pas de reprendre le rythme : il bat
   * tout de suite. Attendre trois secondes de plus après une absence donnerait
   * l'impression d'un écran qui met du temps à se réveiller.
   */
  function surVisibilite() {
    if (visible()) {
      battre()
      planifier()
    } else {
      clearTimeout(minuteur)
      controleur?.abort()
    }
  }

  /**
   * Le départ, envoyé à la fermeture.
   *
   * « keepalive » plutôt qu'une requête ordinaire : le navigateur annule tout
   * ce qui est en vol quand la page se ferme, et le marqueur resterait affiché
   * chez les autres jusqu'à expiration.
   */
  function partir() {
    streamApi.leave().catch(() => {
      // La présence expire d'elle-même au bout de quinze secondes : cet appel
      // ne fait qu'accélérer, son échec ne coûte rien.
    })
  }

  onMounted(() => {
    battre()
    planifier()

    document.addEventListener('visibilitychange', surVisibilite)
    window.addEventListener('pagehide', partir)
  })

  onBeforeUnmount(() => {
    arrete = true
    clearTimeout(minuteur)
    controleur?.abort()

    document.removeEventListener('visibilitychange', surVisibilite)
    window.removeEventListener('pagehide', partir)

    partir()
  })

  // Changer de sujet — ouvrir un autre ticket — doit se voir tout de suite
  // chez les autres, pas au battement suivant.
  if (typeof subject === 'function') {
    watch(subject, () => battre())
  }

  return { cursor, presence, connected }
}
