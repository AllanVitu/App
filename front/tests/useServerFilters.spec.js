import { effectScope, nextTick, ref } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useServerFilters } from '@/composables/useServerFilters'

/**
 * Les filtres qui partent au serveur quand la liste est tronquée.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  CE QUE CES TESTS GARDENT                                           │
 * │                                                                     │
 * │  Tant que la liste est complète, RIEN ne part : le filtrage local   │
 * │  est instantané, et c'est l'atout de ces écrans. Quand elle est     │
 * │  tronquée, une frappe ne vaut pas une requête, une réponse lente    │
 * │  n'écrase pas une plus récente, et effacer les filtres rend la      │
 * │  liste de départ — pas les résultats de la dernière recherche.      │
 * └─────────────────────────────────────────────────────────────────────┘
 */

function monter(options = {}) {
  const filtres = ref({})
  const tronquee = ref(true)
  const fetch = vi.fn(() => Promise.resolve({ lignes: ['du serveur'] }))
  const apply = vi.fn()
  const restore = vi.fn(() => Promise.resolve())
  const onError = vi.fn()

  const scope = effectScope()
  const api = scope.run(() =>
    useServerFilters({
      enabled: () => tronquee.value,
      params: () => filtres.value,
      fetch,
      apply,
      restore,
      onError,
      ...options,
    }),
  )

  return { filtres, tronquee, fetch, apply, restore, onError, scope, ...api, ...options }
}

/** Laisse la réactivité réagir, puis le délai s'écouler. */
async function laisserPasser(ms = 250) {
  await nextTick()
  await vi.advanceTimersByTimeAsync(ms)
}

beforeEach(() => vi.useFakeTimers())
afterEach(() => vi.useRealTimers())

describe('liste complète', () => {
  it('les filtres restent locaux : aucune requête ne part', async () => {
    const { filtres, tronquee, fetch } = monter()
    tronquee.value = false

    filtres.value = { search: 'panier' }
    await laisserPasser()

    expect(fetch).not.toHaveBeenCalled()
  })
})

describe('liste tronquée', () => {
  it('un filtre part au serveur, après la dernière frappe seulement', async () => {
    const { filtres, fetch, apply, active } = monter()

    for (const frappe of ['p', 'pa', 'pan']) {
      filtres.value = { search: frappe }
      await nextTick()
      await vi.advanceTimersByTimeAsync(100)
    }

    await laisserPasser()

    expect(fetch).toHaveBeenCalledTimes(1)
    expect(fetch.mock.calls[0][0]).toEqual({ search: 'pan' })
    expect(apply).toHaveBeenCalledWith({ lignes: ['du serveur'] })
    expect(active.value).toBe(true)
  })

  it('une réponse lente n’écrase pas une réponse plus récente', async () => {
    const reponses = []
    const fetch = vi.fn(
      (params, signal) => new Promise((resolve) => reponses.push({ params, signal, resolve })),
    )
    const { filtres, apply } = monter({ fetch })

    filtres.value = { search: 'a' }
    await laisserPasser()
    filtres.value = { search: 'ab' }
    await laisserPasser()

    // La première est annulée — mais ce n'est pas sur l'annulation qu'on
    // compte : un serveur peut répondre quand même. Elle arrive en retard.
    expect(reponses[0].signal.aborted).toBe(true)

    reponses[1].resolve({ lignes: ['récente'] })
    reponses[0].resolve({ lignes: ['périmée'] })
    await vi.advanceTimersByTimeAsync(0)

    expect(apply).toHaveBeenCalledTimes(1)
    expect(apply).toHaveBeenCalledWith({ lignes: ['récente'] })
  })

  it('effacer les filtres rend la liste de départ', async () => {
    const { filtres, restore, active } = monter()

    filtres.value = { search: 'panier' }
    await laisserPasser()
    expect(active.value).toBe(true)

    filtres.value = {}
    await laisserPasser()

    expect(restore).toHaveBeenCalledTimes(1)
    expect(active.value).toBe(false)
  })

  it('une adresse partagée avec un filtre : la requête part dès que la troncature est connue', async () => {
    const { filtres, tronquee, fetch } = monter()

    // L'écran s'ouvre sur « ?q=panier » : le filtre est posé AVANT que la
    // liste de départ ne soit chargée, donc avant de savoir qu'elle déborde.
    tronquee.value = false
    filtres.value = { search: 'panier' }
    await laisserPasser()
    expect(fetch).not.toHaveBeenCalled()

    tronquee.value = true
    await laisserPasser()

    expect(fetch).toHaveBeenCalledTimes(1)
  })
})

describe('rechargement', () => {
  it('relit les résultats filtrés tant que le mode serveur est actif', async () => {
    const { filtres, fetch, restore, reload } = monter()

    filtres.value = { search: 'panier' }
    await laisserPasser()

    // Le retour sur l'onglet, le flux temps réel, une restauration : sans
    // cela, ils remplaceraient la recherche par les 500 premiers tickets.
    await reload()

    expect(fetch).toHaveBeenCalledTimes(2)
    expect(fetch.mock.calls[1][0]).toEqual({ search: 'panier' })
    expect(restore).not.toHaveBeenCalled()
  })

  it('relit la liste de départ hors du mode serveur', async () => {
    const { fetch, restore, reload } = monter()

    await reload()

    expect(restore).toHaveBeenCalledTimes(1)
    expect(fetch).not.toHaveBeenCalled()
  })
})

describe('échecs et démontage', () => {
  it('une requête annulée n’est pas une erreur', async () => {
    const fetch = vi.fn(() => Promise.reject({ canceled: true, status: 0 }))
    const { filtres, onError, apply } = monter({ fetch })

    filtres.value = { search: 'panier' }
    await laisserPasser()

    expect(onError).not.toHaveBeenCalled()
    expect(apply).not.toHaveBeenCalled()
  })

  it('un échec est signalé, et la liste affichée reste telle quelle', async () => {
    const fetch = vi.fn(() => Promise.reject({ status: 0, message: 'Réseau coupé.' }))
    const { filtres, onError, apply, active } = monter({ fetch })

    filtres.value = { search: 'panier' }
    await laisserPasser()

    expect(onError).toHaveBeenCalledWith('Réseau coupé.')
    expect(apply).not.toHaveBeenCalled()
    expect(active.value).toBe(false)
  })

  it('le démontage annule la requête en vol et le délai en cours', async () => {
    const fetch = vi.fn(() => new Promise(() => {}))
    const { filtres, scope } = monter({ fetch })

    filtres.value = { search: 'a' }
    await laisserPasser()
    const signal = fetch.mock.calls[0][1]

    filtres.value = { search: 'ab' }
    await nextTick()
    scope.stop()

    await vi.advanceTimersByTimeAsync(1000)

    expect(signal.aborted).toBe(true)
    expect(fetch).toHaveBeenCalledTimes(1)
  })
})
