/**
 * Ce que le tableau de bord calcule, séparé de ce qu'il affiche.
 *
 * Tout ici est une fonction pure : la date du jour, la fenêtre de temps et
 * les listes arrivent en argument. C'est ce qui permet de vérifier « demain »,
 * « en retard » ou la place d'une mise en production sans monter l'écran.
 */

const HEURE = 3_600_000
const JOUR = 86_400_000

// ---------------------------------------------------------------------------
// L'en-tête
// ---------------------------------------------------------------------------

/** « Lundi 14 septembre » : la date comme on la dit, pas comme on la stocke. */
export function dayLabel(date) {
  const texte = date.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })

  return texte.charAt(0).toUpperCase() + texte.slice(1)
}

const NOMBRES = ['Aucun', 'Un', 'Deux', 'Trois', 'Quatre', 'Cinq']

const PLUS_GRAVE = {
  failed: 'une mise en production en échec',
  fatal: 'une erreur fatale',
  overdue: 'une échéance dépassée',
  probe_down: 'une adresse qui ne répond plus',
}

/**
 * La phrase sous le salut.
 *
 * Elle dit combien, puis le plus grave : « trois éléments » ne distingue pas
 * trois tickets urgents d'une production cassée. Le plus grave est le premier
 * de la liste parce que le SERVEUR la trie ainsi (cf. AttentionFeed) : cette
 * fonction ne réordonne rien, elle nomme.
 */
export function attentionSentence(items) {
  const n = items.length

  if (n === 0) return 'Rien ne demande votre attention.'

  const nombre = NOMBRES[n] ?? String(n)
  const debut =
    n === 1
      ? `${nombre} élément demande votre attention`
      : `${nombre} éléments demandent votre attention`
  const grave = PLUS_GRAVE[items[0].reason]

  if (!grave) return `${debut}.`

  return n === 1 ? `${debut} : ${grave}.` : `${debut}, dont ${grave}.`
}

// ---------------------------------------------------------------------------
// Les chiffres de tête
// ---------------------------------------------------------------------------

/**
 * Variation par rapport aux sept jours précédents.
 *
 * « goodWhen » dit dans quel sens la situation s'améliore ; sans lui, la
 * variation reste neutre. Plus de mises en production n'est ni bien ni mal —
 * plus d'erreurs, si.
 *
 * @param {number|null} value
 * @param {number|null} previous
 * @param {{ goodWhen?: 'up'|'down'|null, unit?: string }} [options]
 * @returns {{ text: string, tone: string } | null}
 */
export function delta(value, previous, { goodWhen = null, unit = '' } = {}) {
  if (value === null || value === undefined || previous === null || previous === undefined) {
    return null
  }

  const ecart = value - previous

  if (ecart === 0) return { text: 'stable sur 7 j', tone: 'text-ink-3' }

  // Le vrai signe moins (U+2212), et non le trait d'union : même largeur
  // que « + », les chiffres ne dansent pas d'une valeur à l'autre.
  const signe = ecart > 0 ? '+' : '−'
  const bon = goodWhen === null ? null : ecart > 0 === (goodWhen === 'up')

  return {
    text: `${signe}${Math.abs(ecart)}${unit} vs 7 j préc.`,
    tone: bon === null ? 'text-ink-3' : bon ? 'text-moss' : 'text-brick',
  }
}

// ---------------------------------------------------------------------------
// Demande attention
// ---------------------------------------------------------------------------

/**
 * La pastille porte sa classe EN TOUTES LETTRES : Tailwind ne génère que les
 * classes qu'il lit dans les sources, jamais une classe assemblée à la volée.
 */
export const REASONS = {
  failed: { label: 'échec', tone: 'text-brick', dot: 'bg-brick' },
  fatal: { label: 'fatale', tone: 'text-brick', dot: 'bg-brick' },
  overdue: { label: 'en retard', tone: 'text-brick', dot: 'bg-brick' },
  error: { label: 'non résolue', tone: 'text-ochre', dot: 'bg-ochre' },
  urgent: { label: 'urgent', tone: 'text-ochre', dot: 'bg-ochre' },
  probe_down: { label: 'en panne', tone: 'text-brick', dot: 'bg-brick' },
  probe_slow: { label: 'lente', tone: 'text-ochre', dot: 'bg-ochre' },
}

export const reasonOf = (item) => REASONS[item.reason] ?? REASONS.urgent

/**
 * Les trois lignes d'une alerte : ce qui se passe, où, et le détail.
 *
 * Une mise en production en échec se titre par ce qui arrive, pas par son
 * message de commit : « Corriger le calcul des remises » en tête d'une
 * alerte laisserait croire à une tâche, pas à une panne. Le message passe en
 * détail, où il dit ce qui a été livré.
 *
 * @param {object} item
 * @param {(iso: string) => string} relative
 */
export function attentionParts(item, relative) {
  const quand = item.at ? relative(item.at) : null

  if (item.reason === 'failed') {
    return {
      headline: 'Mise en production en échec',
      meta: [item.ref.replace('@', ' · '), quand].filter(Boolean).join(' · '),
      detail: item.title && item.title !== 'Déploiement en échec' ? item.title : null,
    }
  }

  // Une erreur et une sonde se situent de la même façon : où, et depuis quand.
  if (item.module === 'supervision' || item.module === 'disponibilite') {
    return {
      headline: item.title,
      meta: [item.ref, quand].filter(Boolean).join(' · '),
      detail: null,
    }
  }

  return { headline: item.title, meta: item.ref, detail: null }
}

// ---------------------------------------------------------------------------
// Ma journée
// ---------------------------------------------------------------------------

export const PRIORITIES = {
  urgent: { label: 'urgent', tone: 'text-brick' },
  high: { label: 'haute', tone: 'text-ochre' },
  medium: { label: 'moyenne', tone: 'text-ink-3' },
  low: { label: 'basse', tone: 'text-ink-3' },
}

/**
 * L'échéance comme on la dit : « aujourd'hui », « demain », « jeudi », puis
 * la date. Une échéance passée dit « en retard » — « lundi » serait lu comme
 * le lundi qui vient.
 *
 * @param {string|null} due   date SQL « AAAA-MM-JJ », sans fuseau
 * @param {Date} today
 * @returns {{ text: string, late: boolean } | null}
 */
export function dueLabel(due, today) {
  if (!due) return null

  // Construite en heure LOCALE : « new Date('2026-09-15') » serait minuit
  // UTC, donc la veille au soir pour quiconque se trouve à l'ouest de
  // Greenwich.
  const [annee, mois, jour] = due.split('-').map(Number)
  const echeance = new Date(annee, mois - 1, jour)
  const debut = new Date(today.getFullYear(), today.getMonth(), today.getDate())

  // Arrondi, et non tronqué : un changement d'heure fait des journées de 23
  // ou 25 heures.
  const ecart = Math.round((echeance - debut) / JOUR)

  if (ecart < 0) return { text: 'en retard', late: true }
  if (ecart === 0) return { text: "aujourd'hui", late: false }
  if (ecart === 1) return { text: 'demain', late: false }
  if (ecart < 7)
    return { text: echeance.toLocaleDateString('fr-FR', { weekday: 'long' }), late: false }

  return {
    text: echeance.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' }),
    late: false,
  }
}

// ---------------------------------------------------------------------------
// Vos lignes
// ---------------------------------------------------------------------------

/**
 * L'état d'une ligne en quelques mots : le premier signal d'alerte s'il y en
 * a un, sinon le compte ordinaire du module.
 */
export function moduleStatus(module) {
  const alerte = (module.signals ?? []).find((signal) => signal.tone === 'alert')

  if (alerte) return { text: `${alerte.value} ${alerte.label}`, tone: 'text-brick' }

  return { text: `${module.items_count ?? 0} ${module.unit ?? ''}`.trim(), tone: 'text-ink-3' }
}

// ---------------------------------------------------------------------------
// La ligne de production
// ---------------------------------------------------------------------------

/** Place d'un instant sur la ligne, entre 0 et 1 ; hors fenêtre, ramené au bord. */
export function linePosition(at, from, to) {
  const debut = Date.parse(from)
  const fin = Date.parse(to)

  if (!(fin > debut)) return 0

  return Math.min(1, Math.max(0, (Date.parse(at) - debut) / (fin - debut)))
}

/**
 * Décalage horizontal d'une étiquette posée sur la ligne. Centrée en
 * général, elle s'aligne sur le bord près des extrémités : sinon la moitié
 * de « maintenant » sortirait du cadre.
 */
export function labelShift(position) {
  if (position < 0.06) return '0%'
  if (position > 0.94) return '-100%'

  return '-50%'
}

/**
 * Graduations toutes les `step` heures, et « maintenant » au bout. L'heure
 * est celle de l'horloge du lecteur, pas celle du serveur.
 */
export function hourTicks(from, to, step = 4) {
  const debut = Date.parse(from)
  const fin = Date.parse(to)
  const ticks = []

  // On s'arrête avant la fin : une graduation « 14 h » collée à
  // « maintenant » les rendrait illisibles toutes les deux.
  for (let instant = debut; instant < fin - (step * HEURE) / 2; instant += step * HEURE) {
    const date = new Date(instant)

    ticks.push({
      position: linePosition(date.toISOString(), from, to),
      label: `${String(date.getHours()).padStart(2, '0')} h`,
    })
  }

  ticks.push({ position: 1, label: 'maintenant' })

  return ticks
}

/**
 * Hauteur de chaque barre d'erreurs, proportionnelle à l'heure la plus
 * chargée. Une heure non nulle garde au moins deux pixels : une erreur isolée
 * disparaîtrait sinon sous le pic de la nuit.
 */
export function barHeights(counts, max) {
  const plus = Math.max(0, ...counts)

  if (plus === 0) return counts.map(() => 0)

  return counts.map((n) => (n === 0 ? 0 : Math.max(2, Math.round((n / plus) * max))))
}

/**
 * Les mises en production placées sur la ligne, et celles dont on écrit
 * l'empreinte.
 *
 * Deux étiquettes trop proches se chevauchent et ne se lisent plus, ni l'une
 * ni l'autre. Une mise en production en échec est nommée en priorité — c'est
 * elle qu'on cherche du regard ; les autres ne le sont que s'il reste la
 * place, en commençant par la plus récente.
 */
export function placeStations(deployments, from, to, gap = 0.09) {
  const stations = deployments.map((deployment) => ({
    ...deployment,
    position: linePosition(deployment.at, from, to),
    failed: deployment.status === 'error',
    labelled: false,
  }))

  const nommees = []
  const libre = (position) => nommees.every((autre) => Math.abs(autre - position) >= gap)

  const nommer = (station) => {
    if (!station.labelled && libre(station.position)) {
      station.labelled = true
      nommees.push(station.position)
    }
  }

  stations.filter((station) => station.failed).forEach(nommer)
  ;[...stations].reverse().forEach(nommer)

  return stations
}
