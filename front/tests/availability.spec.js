import { describe, expect, it } from 'vitest'

import {
  availabilityKpi,
  durationLabel,
  intervalLabel,
  outcomeInfo,
  recentBars,
  responseLabel,
  uptimeLabel,
} from '@/utils/availability'

describe('état d’une sonde', () => {
  it('dit ce que le dernier appel a donné', () => {
    expect(outcomeInfo({ last_outcome: 'up' }).label).toBe('répond')
    expect(outcomeInfo({ last_outcome: 'slow' }).label).toBe('lente')
    expect(outcomeInfo({ last_outcome: 'down' })).toMatchObject({
      label: 'en panne',
      tone: 'text-brick',
    })
  })

  it('ne confond pas « jamais vérifiée » avec une panne ni avec un succès', () => {
    expect(outcomeInfo({ last_outcome: null })).toMatchObject({
      label: 'jamais vérifiée',
      tone: 'text-ink-3',
    })
  })

  it('fait passer la pause avant le dernier résultat', () => {
    expect(outcomeInfo({ last_outcome: 'down', is_paused: true }).label).toBe('en pause')
  })

  it('nomme les paliers d’intervalle', () => {
    expect(intervalLabel(60)).toBe('Toutes les minutes')
    expect(intervalLabel(3600)).toBe('Toutes les heures')
    expect(intervalLabel(42)).toBe('Toutes les 42 s')
  })
})

describe('chiffres', () => {
  it('écrit la disponibilité à la française, sans couper le pourcentage', () => {
    expect(uptimeLabel(99.98)).toBe('99,98 %')
    expect(uptimeLabel(100)).toBe('100 %')
    expect(uptimeLabel(null)).toBe('—')
  })

  it('dit une durée dans l’unité qui se lit', () => {
    expect(durationLabel(42)).toBe('42 s')
    expect(durationLabel(240)).toBe('4 min')
    expect(durationLabel(7500)).toBe('2 h 05')
    expect(durationLabel(273600)).toBe('3 j 4 h')
  })

  it('dit un temps de réponse en millisecondes puis en secondes', () => {
    expect(responseLabel(128)).toBe('128 ms')
    expect(responseLabel(1840)).toBe('1,8 s')
    expect(responseLabel(null)).toBe('—')
  })
})

describe('barres des derniers appels', () => {
  it('garde vides, à gauche, les créneaux d’une sonde récente', () => {
    const barres = recentBars([{ outcome: 'up', response_ms: 100 }], 5)

    expect(barres).toHaveLength(5)
    expect(barres.slice(0, 4).every((barre) => barre.height === 0 && barre.mark === null)).toBe(
      true,
    )
    expect(barres[4]).toEqual({ height: 1, mark: null })
  })

  it('marque une panne d’un point, sans barre, et une lenteur d’un point sur sa barre', () => {
    const barres = recentBars(
      [
        { outcome: 'up', response_ms: 200 },
        { outcome: 'down', response_ms: null },
        { outcome: 'slow', response_ms: 400 },
      ],
      3,
    )

    expect(barres).toEqual([
      { height: 0.5, mark: null },
      { height: 0, mark: 'bg-brick' },
      { height: 1, mark: 'bg-ochre' },
    ])
  })

  it('ne garde que les derniers créneaux', () => {
    const appels = Array.from({ length: 40 }, (_, i) => ({ outcome: 'up', response_ms: i + 1 }))

    expect(recentBars(appels, 30)).toHaveLength(30)
  })
})

describe('chiffre du tableau de bord', () => {
  it('n’existe pas tant qu’aucune sonde n’a été appelée', () => {
    expect(availabilityKpi({ uptime: null, incidents: 0, downtime_seconds: 0 })).toBeNull()
    expect(availabilityKpi(undefined)).toBeNull()
  })

  it('dit la disponibilité et ce qu’ont coûté les pannes', () => {
    expect(availabilityKpi({ uptime: 99.98, incidents: 1, downtime_seconds: 240 })).toEqual({
      label: 'Disponibilité · 30 j',
      value: '99,98 %',
      note: { text: '1 panne, 4 min', tone: 'text-ink-3' },
    })

    expect(availabilityKpi({ uptime: 100, incidents: 0, downtime_seconds: 0 }).note).toEqual({
      text: 'aucune panne',
      tone: 'text-moss',
    })
  })
})
