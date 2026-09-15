import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useModulesStore } from '@/stores/modules'
import { modulesApi } from '@/services/api'

/**
 * Le catalogue des modules.
 *
 * Il est lu par le menu latéral, le tableau de bord et chaque écran de
 * module — c'est-à-dire par tout ce qui est à l'écran, en permanence. Sa
 * seule raison d'être est de ne pas multiplier les appels : ce qui se vérifie
 * ici, c'est donc surtout QU'IL NE RECHARGE PAS.
 */
vi.mock('@/services/api', () => ({ modulesApi: { list: vi.fn() } }))

const CATALOGUE = [
  { id: 1, slug: 'tickets', name: 'Tickets', items_count: 3 },
  { id: 2, slug: 'backend', name: 'Backend', items_count: 0 },
]

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
  modulesApi.list.mockResolvedValue(structuredClone(CATALOGUE))
})

describe('store modules', () => {
  it('ne charge qu’une fois', async () => {
    const modules = useModulesStore()

    await modules.load()
    await modules.load()
    await modules.load()

    // Le menu est monté sur chaque page authentifiée : sans cache, changer
    // d'écran paierait un aller-retour pour une liste qui ne bouge pas.
    expect(modulesApi.list).toHaveBeenCalledTimes(1)
    expect(modules.items).toHaveLength(2)
  })

  it('recharge sur demande explicite', async () => {
    const modules = useModulesStore()

    await modules.load()
    await modules.load({ force: true })

    expect(modulesApi.list).toHaveBeenCalledTimes(2)
  })

  it('retrouve un module par son slug, et rend null sinon', async () => {
    const modules = useModulesStore()
    await modules.load()

    expect(modules.bySlug('tickets').name).toBe('Tickets')

    // « null » et non « undefined » : les appelants écrivent
    // « bySlug(…)?.name ?? 'Module' », et un module ajouté en base sans écran
    // dédié passe par ici.
    expect(modules.bySlug('inexistant')).toBeNull()
  })

  it('remonte l’erreur ET la retient', async () => {
    const modules = useModulesStore()

    modulesApi.list.mockRejectedValue(new Error('API injoignable'))

    // Les deux : l'appelant affiche un bandeau, le store garde de quoi
    // afficher un état d'erreur dans le menu.
    await expect(modules.load()).rejects.toThrow('API injoignable')
    expect(modules.error).toBe('API injoignable')

    // Et l'indicateur retombe, sinon le menu resterait en chargement pour
    // toujours.
    expect(modules.loading).toBe(false)
    expect(modules.loaded).toBe(false)
  })

  it('réessaie après un échec, sans avoir à forcer', async () => {
    const modules = useModulesStore()

    modulesApi.list.mockRejectedValueOnce(new Error('coupure'))
    await expect(modules.load()).rejects.toThrow()

    // « loaded » étant resté faux, l'appel suivant repart — c'est ce qui
    // permet au menu de se remplir tout seul au retour du réseau.
    await modules.load()

    expect(modules.items).toHaveLength(2)
    expect(modules.error).toBeNull()
  })

  it('ajuste un compteur sans recharger la liste', async () => {
    const modules = useModulesStore()
    await modules.load()

    modules.adjustCount('tickets', 1)
    expect(modules.bySlug('tickets').items_count).toBe(4)

    modules.adjustCount('tickets', -2)
    expect(modules.bySlug('tickets').items_count).toBe(2)

    expect(modulesApi.list).toHaveBeenCalledTimes(1)
  })

  it('ne descend jamais un compteur sous zéro', async () => {
    const modules = useModulesStore()
    await modules.load()

    // Deux suppressions rapides, ou une suppression alors que le compteur
    // venait d'être remis à zéro par un rechargement : « backend : -1 » dans
    // le menu serait absurde et durerait jusqu'au rechargement suivant.
    modules.adjustCount('backend', -1)

    expect(modules.bySlug('backend').items_count).toBe(0)
  })

  it('ignore un slug inconnu', async () => {
    const modules = useModulesStore()
    await modules.load()

    expect(() => modules.adjustCount('fantome', 1)).not.toThrow()
  })

  it('se vide à la déconnexion', async () => {
    const modules = useModulesStore()
    await modules.load()

    modules.reset()

    // Le catalogue appartient au COMPTE : le laisser en place ferait
    // apparaître les modules du précédent utilisateur dans le menu du
    // suivant, le temps du premier chargement.
    expect(modules.items).toEqual([])
    expect(modules.loaded).toBe(false)

    await modules.load()
    expect(modulesApi.list).toHaveBeenCalledTimes(2)
  })
})
