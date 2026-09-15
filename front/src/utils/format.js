/**
 * Utilitaires de formatage des dates.
 *
 * PostgreSQL renvoie ses timestamps sous la forme « 2026-08-28 16:02:05+00 » :
 * l'espace entre la date et l'heure n'est pas du ISO 8601 strict et certains
 * navigateurs refusent de le parser. La normalisation est faite ici, une fois
 * pour toute l'application.
 */

/**
 * @param {string|Date|null} value
 * @returns {Date|null} null si la valeur est absente ou illisible
 */
export function parseDate(value) {
  if (!value) return null
  if (value instanceof Date) return value

  const normalized = String(value).replace(' ', 'T')
  const date = new Date(normalized)

  return Number.isNaN(date.getTime()) ? null : date
}

/**
 * ---------------------------------------------------------------------------
 * FUSEAU HORAIRE
 *
 * Le fuseau choisi dans les Paramètres était enregistré en base et lu par
 * PERSONNE : toutes les dates s'affichaient dans celui du navigateur. L'écran
 * proposait un réglage qui ne réglait rien.
 *
 * Les formateurs vivent donc dans un `shallowRef` plutôt qu'en constantes.
 * C'est ce qui rend le changement VISIBLE sans rechargement : un composant qui
 * appelle `formatDate()` dans son gabarit lit ce ref, donc en dépend, donc se
 * redessine quand le fuseau change. Avec des constantes, l'écran aurait gardé
 * les anciennes heures jusqu'au prochain rechargement complet.
 * ---------------------------------------------------------------------------
 */
import { shallowRef } from 'vue'

const DATE = { day: '2-digit', month: 'short', year: 'numeric' }
const DATE_TIME = { ...DATE, hour: '2-digit', minute: '2-digit' }

/**
 * `timeZone: undefined` laisse Intl prendre celui du navigateur — c'est le
 * bon repli tant que les paramètres du compte ne sont pas chargés.
 */
const build = (zone) => ({
  zone,
  date: new Intl.DateTimeFormat('fr-FR', { ...DATE, timeZone: zone }),
  dateTime: new Intl.DateTimeFormat('fr-FR', { ...DATE_TIME, timeZone: zone }),
  relative: new Intl.RelativeTimeFormat('fr-FR', { numeric: 'auto' }),
})

const formatters = shallowRef(build(undefined))

/**
 * Applique le fuseau du compte. Appelé par le store d'authentification dès que
 * les paramètres arrivent, et par l'écran Paramètres à chaque changement.
 *
 * Un fuseau invalide ne doit pas casser l'affichage de toute l'application :
 * on retombe sur celui du navigateur.
 *
 * @param {string|null} zone identifiant IANA, « Europe/Paris »
 */
export function setTimeZone(zone) {
  try {
    formatters.value = build(zone || undefined)
  } catch {
    formatters.value = build(undefined)
  }
}

/**
 * Date du jour DANS LE FUSEAU ACTIF, au format « AAAA-MM-JJ ».
 *
 * Corrige un décalage réel : « aujourd'hui » était calculé avec
 * `toISOString()`, donc en UTC. À Montréal, passé 20 h, la journée courante
 * était déjà celle du lendemain — un ticket à échéance du jour était annoncé
 * « en retard » alors qu'il restait quatre heures pour le traiter.
 *
 * « en-CA » n'est pas un choix de langue mais de FORMAT : c'est la locale qui
 * produit nativement AAAA-MM-JJ, donc directement comparable aux dates que
 * PostgreSQL renvoie.
 */
export function today() {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: formatters.value.zone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date())
}

/** « 28 août 2026 » */
export function formatDate(value) {
  const date = parseDate(value)

  return date ? formatters.value.date.format(date) : '—'
}

/** « 28 août 2026, 16:02 » */
export function formatDateTime(value) {
  const date = parseDate(value)

  return date ? formatters.value.dateTime.format(date) : '—'
}

/** « il y a 3 jours » — bascule sur la date absolue au-delà d'un mois. */
export function formatRelative(value) {
  const date = parseDate(value)

  if (!date) return '—'

  const seconds = Math.round((date.getTime() - Date.now()) / 1000)
  const absolute = Math.abs(seconds)

  const units = [
    { unit: 'second', limit: 60, divisor: 1 },
    { unit: 'minute', limit: 3600, divisor: 60 },
    { unit: 'hour', limit: 86400, divisor: 3600 },
    { unit: 'day', limit: 604800, divisor: 86400 },
    { unit: 'week', limit: 2592000, divisor: 604800 },
  ]

  for (const { unit, limit, divisor } of units) {
    if (absolute < limit) {
      return formatters.value.relative.format(Math.round(seconds / divisor), unit)
    }
  }

  return formatters.value.date.format(date)
}

/** Date au format attendu par <input type="date"> (AAAA-MM-JJ). */
export function toDateInput(value) {
  const date = parseDate(value)

  if (!date) return ''

  // Découpage manuel : toISOString() convertit en UTC et peut décaler d'un jour.
  const pad = (n) => String(n).padStart(2, '0')

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}
