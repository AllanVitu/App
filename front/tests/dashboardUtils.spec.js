import { describe, expect, it } from 'vitest'

import {
  attentionParts,
  attentionSentence,
  barHeights,
  dayLabel,
  delta,
  dueLabel,
  hourTicks,
  labelShift,
  linePosition,
  moduleStatus,
  placeStations,
} from '@/utils/dashboard'

const FROM = '2026-09-13T12:00:00Z'
const TO = '2026-09-14T12:00:00Z'

describe('en-tête', () => {
  it('dit la date comme on la dit', () => {
    expect(dayLabel(new Date(2026, 8, 14))).toBe('Lundi 14 septembre')
  })

  it('ne dit rien de grave quand rien ne l’est', () => {
    expect(attentionSentence([])).toBe('Rien ne demande votre attention.')
    expect(attentionSentence([{ reason: 'urgent' }, { reason: 'error' }])).toBe(
      'Deux éléments demandent votre attention.',
    )
  })

  it('nomme le plus grave, que le serveur a placé en tête', () => {
    expect(
      attentionSentence([{ reason: 'failed' }, { reason: 'fatal' }, { reason: 'urgent' }]),
    ).toBe('Trois éléments demandent votre attention, dont une mise en production en échec.')
    expect(attentionSentence([{ reason: 'fatal' }])).toBe(
      'Un élément demande votre attention : une erreur fatale.',
    )
  })
})

describe('sondes dans l’accueil', () => {
  it('nomme une adresse qui ne répond plus comme le plus grave', () => {
    expect(attentionSentence([{ reason: 'probe_down' }, { reason: 'urgent' }])).toBe(
      'Deux éléments demandent votre attention, dont une adresse qui ne répond plus.',
    )
  })

  it('situe une sonde par son hôte et depuis quand', () => {
    expect(
      attentionParts(
        {
          module: 'disponibilite',
          reason: 'probe_down',
          ref: 'boutique.fr',
          title: 'Paiement',
          at: 'x',
        },
        () => 'il y a 4 min',
      ),
    ).toEqual({ headline: 'Paiement', meta: 'boutique.fr · il y a 4 min', detail: null })
  })
})

describe('chiffres de tête', () => {
  it('colore la variation selon le sens où la situation s’améliore', () => {
    expect(delta(212, 143, { goodWhen: 'down' })).toEqual({
      text: '+69 vs 7 j préc.',
      tone: 'text-brick',
    })
    expect(delta(90, 95, { goodWhen: 'up', unit: ' pts' })).toEqual({
      text: '−5 pts vs 7 j préc.',
      tone: 'text-brick',
    })
    expect(delta(23, 20)).toEqual({ text: '+3 vs 7 j préc.', tone: 'text-ink-3' })
  })

  it('ne compare pas ce qui n’a pas de période précédente', () => {
    expect(delta(34, null)).toBeNull()
    expect(delta(null, 12)).toBeNull()
    expect(delta(4, 4, { goodWhen: 'down' })).toEqual({
      text: 'stable sur 7 j',
      tone: 'text-ink-3',
    })
  })
})

describe('demande attention', () => {
  const relative = () => 'il y a 12 min'

  it('titre une production cassée par ce qui arrive, pas par son commit', () => {
    const parts = attentionParts(
      {
        module: 'deploiement',
        reason: 'failed',
        ref: 'main@a41f9c2',
        title: 'Corriger les remises',
        at: 'x',
      },
      relative,
    )

    expect(parts).toEqual({
      headline: 'Mise en production en échec',
      meta: 'main · a41f9c2 · il y a 12 min',
      detail: 'Corriger les remises',
    })
  })

  it('ne répète pas le titre de repli en détail', () => {
    const parts = attentionParts(
      {
        module: 'deploiement',
        reason: 'failed',
        ref: 'main@a41f9c2',
        title: 'Déploiement en échec',
        at: null,
      },
      relative,
    )

    expect(parts.detail).toBeNull()
    expect(parts.meta).toBe('main · a41f9c2')
  })

  it('situe une erreur par ses occurrences et son dernier passage', () => {
    expect(
      attentionParts(
        { module: 'supervision', reason: 'fatal', ref: '×128', title: 'TypeError', at: 'x' },
        relative,
      ),
    ).toEqual({ headline: 'TypeError', meta: '×128 · il y a 12 min', detail: null })
  })
})

describe('ma journée', () => {
  const lundi = new Date(2026, 8, 14, 9, 30)

  it('dit l’échéance comme on la dit', () => {
    expect(dueLabel('2026-09-14', lundi)).toEqual({ text: "aujourd'hui", late: false })
    expect(dueLabel('2026-09-15', lundi)).toEqual({ text: 'demain', late: false })
    expect(dueLabel('2026-09-17', lundi)).toEqual({ text: 'jeudi', late: false })
    expect(dueLabel('2026-09-30', lundi)).toEqual({ text: '30 sept.', late: false })
  })

  it('dit « en retard » plutôt qu’un jour passé', () => {
    expect(dueLabel('2026-09-11', lundi)).toEqual({ text: 'en retard', late: true })
    expect(dueLabel(null, lundi)).toBeNull()
  })

  it('traverse un changement d’heure sans perdre un jour', () => {
    // Nuit du 24 au 25 octobre 2026 : 25 heures en France.
    expect(dueLabel('2026-10-26', new Date(2026, 9, 25, 12))).toEqual({
      text: 'demain',
      late: false,
    })
  })
})

describe('vos lignes', () => {
  it('montre l’alerte avant le compte ordinaire', () => {
    expect(
      moduleStatus({
        items_count: 23,
        unit: 'déploiements',
        signals: [
          { label: 'en cours', value: 2, tone: 'neutral' },
          { label: 'en échec', value: 1, tone: 'alert' },
        ],
      }),
    ).toEqual({ text: '1 en échec', tone: 'text-brick' })

    expect(moduleStatus({ items_count: 12, unit: 'tables', signals: [] })).toEqual({
      text: '12 tables',
      tone: 'text-ink-3',
    })
  })
})

describe('ligne de production', () => {
  it('place un instant entre les deux bornes, et jamais au-delà', () => {
    expect(linePosition('2026-09-14T00:00:00Z', FROM, TO)).toBe(0.5)
    expect(linePosition('2026-09-10T00:00:00Z', FROM, TO)).toBe(0)
    expect(linePosition('2026-09-15T00:00:00Z', FROM, TO)).toBe(1)
    expect(linePosition('2026-09-14T00:00:00Z', TO, FROM)).toBe(0)
  })

  it('aligne les étiquettes des extrémités sur le bord', () => {
    expect(labelShift(0)).toBe('0%')
    expect(labelShift(0.5)).toBe('-50%')
    expect(labelShift(1)).toBe('-100%')
  })

  it('gradue toutes les quatre heures et finit sur « maintenant »', () => {
    const ticks = hourTicks(FROM, TO)

    expect(ticks).toHaveLength(7)
    expect(ticks.map((tick) => Number(tick.position.toFixed(4)))).toEqual([
      0, 0.1667, 0.3333, 0.5, 0.6667, 0.8333, 1,
    ])
    expect(ticks.at(-1).label).toBe('maintenant')
    expect(ticks[0].label).toMatch(/^\d{2} h$/)
  })

  it('garde une erreur isolée visible sous un pic', () => {
    expect(barHeights([0, 1, 100], 38)).toEqual([0, 2, 38])
    expect(barHeights([0, 0], 38)).toEqual([0, 0])
  })

  it('nomme d’abord l’échec, puis les plus récentes qui ont la place', () => {
    const stations = placeStations(
      [
        { id: 'a', sha: 'aaaaaaa', status: 'ready', at: '2026-09-13T13:00:00Z' },
        { id: 'b', sha: 'bbbbbbb', status: 'ready', at: '2026-09-14T03:00:00Z' },
        { id: 'e', sha: 'eeeeeee', status: 'ready', at: '2026-09-14T04:00:00Z' },
        { id: 'c', sha: 'ccccccc', status: 'error', at: '2026-09-14T10:30:00Z' },
        { id: 'd', sha: 'ddddddd', status: 'ready', at: '2026-09-14T11:50:00Z' },
      ],
      FROM,
      TO,
    )

    // « d », la plus récente, tombe trop près de l’échec : elle se tait.
    // « b » et « e » se touchent : la plus récente des deux est nommée.
    expect(stations.map((station) => [station.id, station.labelled])).toEqual([
      ['a', true],
      ['b', false],
      ['e', true],
      ['c', true],
      ['d', false],
    ])
    expect(stations.find((station) => station.id === 'c').failed).toBe(true)
  })
})
