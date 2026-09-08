import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  formatDate,
  formatDateTime,
  formatRelative,
  parseDate,
  setTimeZone,
  toDateInput,
  today,
} from '@/utils/format'

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

/**
 * Le fuseau horaire du compte.
 *
 * Il était enregistré en base et lu par personne : toutes les dates
 * s'affichaient dans celui du navigateur, et « aujourd'hui » était calculé en
 * UTC. Conséquence mesurable : à Montréal, passé 20 h, un ticket à échéance du
 * jour était annoncé « en retard » alors qu'il restait quatre heures.
 */
describe('setTimeZone', () => {
  // Chaque test repart du fuseau du navigateur : l'état est global au module.
  afterEach(() => setTimeZone(null))

  it("décale l'heure affichée selon le fuseau choisi", () => {
    const instant = '2026-08-28T23:30:00+00:00'

    setTimeZone('UTC')
    const utc = formatDateTime(instant)

    setTimeZone('Europe/Paris')
    const paris = formatDateTime(instant)

    // 23 h 30 UTC = 01 h 30 le lendemain à Paris : l'heure ET le jour changent.
    expect(utc).not.toBe(paris)
    expect(utc).toContain('23:30')
    expect(paris).toContain('01:30')
    expect(paris).toContain('29')
  })

  it('retombe sur le fuseau du navigateur si l’identifiant est invalide', () => {
    setTimeZone('Pas/Un/Fuseau')

    // Ne lève pas, et continue de formater : un réglage erroné en base ne doit
    // pas vider toutes les dates de l'application.
    expect(formatDate('2026-08-28T12:00:00+00:00')).not.toBe('—')
  })
})

describe('today', () => {
  afterEach(() => setTimeZone(null))

  it('donne le jour DANS le fuseau actif, pas en UTC', () => {
    // 2026-08-29, 01 h 30 à Paris — donc encore le 28 en UTC.
    vi.setSystemTime(new Date('2026-08-28T23:30:00Z'))

    setTimeZone('UTC')
    expect(today()).toBe('2026-08-28')

    setTimeZone('Europe/Paris')
    expect(today()).toBe('2026-08-29')

    vi.useRealTimers()
  })

  it('produit AAAA-MM-JJ, directement comparable aux dates de PostgreSQL', () => {
    expect(today()).toMatch(/^\d{4}-\d{2}-\d{2}$/)
  })
})
