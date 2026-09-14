/**
 * Ce que le module Disponibilité calcule, séparé de ce qu'il affiche.
 *
 * Fonctions pures : l'écran les appelle, les tests les vérifient sans monter
 * un seul composant.
 */

/** Les paliers que le serveur accepte (cf. ProbeController::INTERVALS). */
export const INTERVALS = [
  { value: 60, label: 'Toutes les minutes' },
  { value: 300, label: 'Toutes les 5 minutes' },
  { value: 900, label: 'Toutes les 15 minutes' },
  { value: 3600, label: 'Toutes les heures' },
]

export const intervalLabel = (seconds) =>
  INTERVALS.find((interval) => interval.value === seconds)?.label ?? `Toutes les ${seconds} s`

/**
 * L'état d'une sonde, en mots et en couleur d'ÉTAT — texte et point
 * seulement, jamais un aplat (cf. la règle de forme de main.css).
 *
 * « Jamais vérifiée » n'est ni une panne ni un succès : c'est une absence de
 * fait, et elle se dit comme telle. La pause l'emporte sur le dernier
 * résultat : une sonde arrêtée exprès ne dit plus rien de la production.
 */
export function outcomeInfo(probe) {
  if (probe.is_paused) return { label: 'en pause', tone: 'text-ink-3', dot: 'bg-ink-3' }

  switch (probe.last_outcome) {
    case 'up':
      return { label: 'répond', tone: 'text-moss', dot: 'bg-moss' }
    case 'slow':
      return { label: 'lente', tone: 'text-ochre', dot: 'bg-ochre' }
    case 'down':
      return { label: 'en panne', tone: 'text-brick', dot: 'bg-brick' }
    default:
      return { label: 'jamais vérifiée', tone: 'text-ink-3', dot: 'bg-line-2' }
  }
}

/** « 99,98 % », « 100 % », et « — » tant qu'aucun appel n'a eu lieu. */
export function uptimeLabel(value) {
  if (value === null || value === undefined) return '—'

  // Espace insécable : « 99,98 » et « % » ne se séparent jamais d'une ligne.
  return `${value.toLocaleString('fr-FR', { maximumFractionDigits: 2 })}\u00a0%`
}

/** « 45 s », « 4 min », « 2 h 05 », « 3 j 4 h ». */
export function durationLabel(seconds) {
  if (seconds < 60) return `${Math.max(0, Math.round(seconds))} s`

  const minutes = Math.round(seconds / 60)

  if (minutes < 60) return `${minutes} min`

  const heures = Math.floor(minutes / 60)

  if (heures < 24) return `${heures} h ${String(minutes % 60).padStart(2, '0')}`

  return `${Math.floor(heures / 24)} j ${heures % 24} h`
}

/** « 128 ms », « 1,8 s ». */
export function responseLabel(ms) {
  if (ms === null || ms === undefined) return '—'
  if (ms < 1000) return `${ms} ms`

  return `${(ms / 1000).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} s`
}

/**
 * Les barres des derniers appels, les plus anciens à gauche.
 *
 * La HAUTEUR dit le temps de réponse, dans la couleur de ligne du module. Une
 * panne n'a pas de temps de réponse : pas de barre, un point brique à la
 * place — c'est précisément le trou qu'on doit voir. Une lenteur garde sa
 * barre et reçoit un point ocre.
 *
 * Les créneaux vides d'une sonde récente restent vides, à gauche : trois
 * appels ne s'étirent pas sur toute la largeur comme s'ils en valaient trente.
 *
 * @param {Array<{outcome: string, response_ms: number|null}>} recent
 * @param {number} slots
 * @returns {Array<{height: number, mark: string|null}>} hauteur entre 0 et 1
 */
export function recentBars(recent, slots = 30) {
  const derniers = recent.slice(-slots)
  const plus = Math.max(
    1,
    ...derniers.filter((appel) => appel.outcome !== 'down').map((appel) => appel.response_ms ?? 0),
  )

  const barres = derniers.map((appel) => {
    if (appel.outcome === 'down') return { height: 0, mark: 'bg-brick' }

    return {
      height: Math.max(0.12, (appel.response_ms ?? 0) / plus),
      mark: appel.outcome === 'slow' ? 'bg-ochre' : null,
    }
  })

  return [
    ...Array.from({ length: slots - barres.length }, () => ({ height: 0, mark: null })),
    ...barres,
  ]
}

/**
 * Le quatrième chiffre du tableau de bord, quand il existe.
 *
 * Tant qu'aucune sonde n'a été appelée, il n'y a pas de disponibilité à dire :
 * le tableau de bord garde alors le taux de réussite des mises en production,
 * plutôt que d'afficher « — » à la place d'un chiffre.
 *
 * @param {{uptime: number|null, incidents: number, downtime_seconds: number}|undefined} availability
 */
export function availabilityKpi(availability) {
  if (!availability || availability.uptime === null) return null

  const { incidents, downtime_seconds: downtime } = availability

  return {
    label: 'Disponibilité · 30 j',
    value: uptimeLabel(availability.uptime),
    note: {
      text:
        incidents === 0
          ? 'aucune panne'
          : `${incidents} ${incidents > 1 ? 'pannes' : 'panne'}, ${durationLabel(downtime)}`,
      tone: incidents === 0 ? 'text-moss' : 'text-ink-3',
    },
  }
}
