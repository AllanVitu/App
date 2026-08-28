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

const dateFormatter = new Intl.DateTimeFormat('fr-FR', {
  day: '2-digit',
  month: 'short',
  year: 'numeric',
})

const dateTimeFormatter = new Intl.DateTimeFormat('fr-FR', {
  day: '2-digit',
  month: 'short',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
})

const relativeFormatter = new Intl.RelativeTimeFormat('fr-FR', { numeric: 'auto' })

/** « 28 août 2026 » */
export function formatDate(value) {
  const date = parseDate(value)

  return date ? dateFormatter.format(date) : '—'
}

/** « 28 août 2026, 16:02 » */
export function formatDateTime(value) {
  const date = parseDate(value)

  return date ? dateTimeFormatter.format(date) : '—'
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
      return relativeFormatter.format(Math.round(seconds / divisor), unit)
    }
  }

  return dateFormatter.format(date)
}

/** Date au format attendu par <input type="date"> (AAAA-MM-JJ). */
export function toDateInput(value) {
  const date = parseDate(value)

  if (!date) return ''

  // Découpage manuel : toISOString() convertit en UTC et peut décaler d'un jour.
  const pad = (n) => String(n).padStart(2, '0')

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}
