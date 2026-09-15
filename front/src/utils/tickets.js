/**
 * Vocabulaire du module Tickets.
 *
 * Statuts et priorités sont définis ICI, une seule fois : la liste, la fenêtre
 * de détail, les filtres et la tuile du tableau de bord y puisent le même
 * libellé et la même couleur. Les dupliquer dans chaque composant garantirait
 * qu'ils finissent par diverger.
 *
 * L'ordre des tableaux reflète celui du type énuméré PostgreSQL
 * (cf. 06_tickets.sql) — il porte le cycle de vie pour les statuts et la
 * gravité pour les priorités.
 */
import { today } from '@/utils/format'

/** Ordre d'AVANCEMENT, qui n'est pas l'ordre d'affichage du tableau. */
export const STATUS_FLOW = ['backlog', 'todo', 'in_progress', 'done']

export const STATUSES = [
  { value: 'backlog', label: 'en attente', short: 'attente', tone: 'text-ink-3' },
  { value: 'todo', label: 'à faire', short: 'à faire', tone: 'text-ink' },
  { value: 'in_progress', label: 'en cours', short: 'en cours', tone: 'text-ochre' },
  { value: 'done', label: 'terminé', short: 'terminé', tone: 'text-moss' },
  { value: 'canceled', label: 'annulé', short: 'annulé', tone: 'text-ink-3' },
]

export const PRIORITIES = [
  { value: 'urgent', label: 'urgente', bars: 4, key: '1', tone: 'text-brick' },
  { value: 'high', label: 'haute', bars: 3, key: '2', tone: 'text-ochre' },
  { value: 'medium', label: 'moyenne', bars: 2, key: '3', tone: 'text-ink-2' },
  { value: 'low', label: 'basse', bars: 1, key: '4', tone: 'text-ink-3' },
  { value: 'none', label: 'aucune', bars: 0, key: '0', tone: 'text-ink-3' },
]

const byValue = (list) => Object.fromEntries(list.map((entry) => [entry.value, entry]))

const STATUS_BY_VALUE = byValue(STATUSES)
const PRIORITY_BY_VALUE = byValue(PRIORITIES)

/** Repli sur « à faire » plutôt qu'undefined : un statut inconnu ne doit pas
 *  faire disparaître une ligne de la liste. */
export const statusOf = (value) => STATUS_BY_VALUE[value] ?? STATUS_BY_VALUE.todo

export const priorityOf = (value) => PRIORITY_BY_VALUE[value] ?? PRIORITY_BY_VALUE.none

/**
 * Colonnes du tableau, de gauche à droite.
 *
 * L'ordre est celui du TRAVAIL : on avance un ticket vers la droite. C'est ce
 * qui rend le glissement évident sans qu'on ait à l'expliquer, et ce qui fait
 * qu'une accumulation dans une colonne se lit comme un embouteillage.
 *
 * Une version antérieure de cet écran groupait les tickets verticalement et
 * plaçait « en cours » en premier, pour que le travail actif soit en haut.
 * C'était juste pour une liste — en colonnes, ce serait remonter le courant.
 *
 * « annulé » ferme la marche : hors du flux, mais visible, parce qu'un ticket
 * abandonné qu'on ne voit plus est un ticket qu'on recrée.
 */
export const BOARD_ORDER = [...STATUS_FLOW, 'canceled'].map(statusOf)

/** Un ticket clos ne « travaille » plus : ni retard, ni avancement. */
export const isClosed = (ticket) => ticket.status === 'done' || ticket.status === 'canceled'

/**
 * Statut suivant / précédent dans le cycle.
 *
 * « annulé » est hors du flux : on n'y arrive pas en avançant, seulement par
 * une action explicite. Un ticket annulé qu'on fait avancer repart donc de
 * « à faire », ce qui est le geste attendu quand on rouvre un abandon.
 */
export function advanceStatus(status, step = 1) {
  const index = STATUS_FLOW.indexOf(status)

  if (index === -1) return step > 0 ? 'todo' : 'backlog'

  const next = Math.min(Math.max(index + step, 0), STATUS_FLOW.length - 1)

  return STATUS_FLOW[next]
}

/**
 * Retard : échéance dépassée ET ticket encore ouvert.
 *
 * Un ticket terminé après son échéance n'est pas « en retard » — il est
 * terminé. Le confondre remplirait la vue d'alertes sur du travail fait.
 */
export function isOverdue(ticket) {
  if (!ticket.due_date || isClosed(ticket)) return false

  // « today() » et non toISOString() : ce dernier calcule en UTC, et un
  // ticket à échéance du jour était annoncé en retard dès 20 h à Montréal.
  return ticket.due_date < today()
}

const shortDate = (dueDate) =>
  new Date(`${dueDate}T00:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })

/**
 * Échéance en clair, relative au jour même.
 *
 * Prend le TICKET et non la seule date, parce que la formulation dépend de
 * son statut : un ticket terminé après son échéance n'est pas « en retard »,
 * il est terminé. Afficher « 3 j de retard » sur une ligne barrée mettrait
 * une alerte sur du travail fait.
 *
 * Comparaison sur les chaînes « AAAA-MM-JJ » plutôt que sur des objets Date :
 * une date nue n'a pas d'heure, et la convertir en Date la placerait à minuit
 * UTC — ce qui décale d'un jour dès que le navigateur est à l'ouest de
 * Greenwich.
 */
export function formatDue(ticket) {
  const dueDate = ticket?.due_date

  if (!dueDate) return null

  if (isClosed(ticket)) return shortDate(dueDate)

  const now = today()

  if (dueDate === now) return "aujourd'hui"

  const days = Math.round((Date.parse(dueDate) - Date.parse(now)) / 86400000)

  if (days === 1) return 'demain'
  if (days === -1) return 'hier'
  if (days < 0) return `${-days} j de retard`
  if (days <= 14) return `dans ${days} j`

  return shortDate(dueDate)
}
