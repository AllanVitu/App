/**
 * Signalement des erreurs du navigateur à la supervision de l'instance.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  LES FILETS DE main.js ATTRAPAIENT, ET GARDAIENT POUR EUX               │
 * │                                                                         │
 * │  Un bandeau pour l'utilisateur, une trace dans une console que personne │
 * │  n'ouvre, et rien pour l'équipe. Une panne du client n'existait que     │
 * │  pour celui qui la subissait.                                           │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Ce qui ne part PAS compte autant que ce qui part :
 *
 *   — une erreur HTTP déjà normalisée. L'API l'a produite : si c'est une
 *     panne, elle l'a déjà rangée, avec sa référence ; si c'est un refus
 *     (422, 409, 403), ce n'est pas une panne du tout ;
 *   — la même erreur deux fois sur le même écran. Un rendu qui rejette à
 *     chaque image en produirait soixante par seconde ;
 *   — quoi que ce soit au-delà d'un budget par page. Le serveur plafonne
 *     aussi, mais autant ne pas lui envoyer ce qu'il refusera ;
 *   — une valeur qui n'est ni une Error ni un texte. « [object Object] » ne
 *     dit rien, et un objet rejeté peut contenir n'importe quoi.
 *
 * LE SIGNALEMENT NE DOIT JAMAIS ÉCHOUER À SON TOUR. Un envoi raté est avalé :
 * sinon le filet des promesses rejetées l'attraperait, le signalerait,
 * échouerait encore — et la boucle ne s'arrêterait qu'avec l'onglet.
 */

const LIMITES = { message: 1000, stack: 8000, route: 120, component: 120, release: 40 }

/** Erreur normalisée par le client HTTP : elle porte un statut, pas une pile. */
function estErreurHttp(erreur) {
  return (
    erreur !== null &&
    typeof erreur === 'object' &&
    !(erreur instanceof Error) &&
    'status' in erreur
  )
}

function tronquer(valeur, limite) {
  return valeur === null || valeur === undefined || valeur === ''
    ? null
    : String(valeur).slice(0, limite)
}

/**
 * @param {object} options
 * @param {(rapport: object) => Promise<unknown>} options.send   l'envoi réel
 * @param {() => boolean} [options.isEnabled]  faux sans session : la route l'exige
 * @param {number} [options.budget]            envois au plus par chargement de page
 * @param {string|null} [options.release]      version du client, pour dater une régression
 * @returns {(kind: string, erreur: unknown, contexte?: { route?: string|null, component?: string|null }) => boolean}
 *          vrai si un envoi est parti
 */
export function createErrorReporter({ send, isEnabled = () => true, budget = 10, release = null }) {
  const dejaVues = new Set()
  let envoyes = 0

  return function signaler(kind, erreur, { route = null, component = null } = {}) {
    if (!isEnabled() || envoyes >= budget) return false
    if (erreur?.canceled || estErreurHttp(erreur)) return false
    if (!(erreur instanceof Error) && typeof erreur !== 'string') return false

    const message = tronquer(
      erreur instanceof Error ? `${erreur.name}: ${erreur.message}` : erreur,
      LIMITES.message,
    )

    if (message === null) return false

    const empreinte = `${kind}|${route ?? ''}|${message}`

    if (dejaVues.has(empreinte)) return false

    dejaVues.add(empreinte)
    envoyes += 1

    const rapport = {
      kind,
      message,
      stack: erreur instanceof Error ? tronquer(erreur.stack, LIMITES.stack) : null,
      route: tronquer(route, LIMITES.route),
      component: tronquer(component, LIMITES.component),
      release: tronquer(release, LIMITES.release),
    }

    try {
      Promise.resolve(send(rapport)).catch(() => {})
    } catch {
      // Un envoi qui lève avant même de rendre sa promesse : même traitement.
    }

    return true
  }
}
