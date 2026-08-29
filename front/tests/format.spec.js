import { describe, expect, it } from 'vitest'

import { formatDate, formatRelative, parseDate, toDateInput } from '@/utils/format'

/**
 * Ces fonctions ont déjà causé un défaut visible en production : PostgreSQL
 * renvoyait « 2026-08-28 16:02:05+00 », que les moteurs JavaScript refusent
 * de parser, et toutes les dates s'affichaient « — ». L'API émet désormais
 * de l'ISO 8601, mais la tolérance au format hérité reste testée.
 */
describe('parseDate', () => {
  it('lit une date ISO 8601', () => {
    expect(parseDate('2026-08-28T16:02:05+00:00')?.toISOString()).toBe('2026-08-28T16:02:05.000Z')
  })

  it('tolère le format PostgreSQL séparé par une espace', () => {
    expect(parseDate('2026-08-28 16:02:05+00:00')?.toISOString()).toBe('2026-08-28T16:02:05.000Z')
  })

  it('renvoie null plutôt que « Invalid Date »', () => {
    expect(parseDate(null)).toBeNull()
    expect(parseDate('')).toBeNull()
    expect(parseDate('pas une date')).toBeNull()
  })

  it('laisse passer un objet Date', () => {
    const date = new Date('2026-01-01T00:00:00Z')
    expect(parseDate(date)).toBe(date)
  })
})

describe('formatDate', () => {
  it('met en forme en français', () => {
    expect(formatDate('2026-08-28T16:02:05+00:00')).toMatch(/28 août 2026/)
  })

  it('affiche un tiret pour une valeur absente', () => {
    expect(formatDate(null)).toBe('—')
    expect(formatDate('n’importe quoi')).toBe('—')
  })
})

describe('formatRelative', () => {
  it('exprime le passé proche en heures', () => {
    const troisHeures = new Date(Date.now() - 3 * 3600 * 1000).toISOString()
    expect(formatRelative(troisHeures)).toMatch(/il y a 3 heures/)
  })

  it('bascule sur la date absolue au-delà d’un mois', () => {
    const vieux = new Date(Date.now() - 200 * 24 * 3600 * 1000).toISOString()
    expect(formatRelative(vieux)).not.toMatch(/il y a/)
  })

  it('affiche un tiret pour une valeur absente', () => {
    expect(formatRelative(undefined)).toBe('—')
  })
})

describe('toDateInput', () => {
  it('produit le format attendu par <input type="date">', () => {
    // Découpage en heure locale volontaire : toISOString() convertit en UTC
    // et décalerait la date d'un jour selon le fuseau.
    const date = new Date(2026, 11, 24, 15, 30)
    expect(toDateInput(date)).toBe('2026-12-24')
  })

  it('renvoie une chaîne vide pour une valeur absente', () => {
    expect(toDateInput(null)).toBe('')
  })
})
