import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * L'état d'interface : thème, tiroir, notifications.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  DEUX ENTRÉES QUE PERSONNE NE CONTRÔLE                              │
 * │                                                                     │
 * │  Le thème est relu dans « localStorage » — une valeur que           │
 * │  l'utilisateur peut éditer, qu'une ancienne version a pu écrire     │
 * │  autrement, et qui peut simplement ne pas être lisible en           │
 * │  navigation privée stricte. Un accès non gardé y suffit à faire     │
 * │  échouer le démarrage de toute l'application, avant même le premier │
 * │  rendu.                                                             │
 * │                                                                     │
 * │  Aucun parcours navigateur ne provoque ces cas : ils tournent tous  │
 * │  avec un stockage sain.                                             │
 * └─────────────────────────────────────────────────────────────────────┘
 */
vi.mock('@/services/sound', () => ({
  play: vi.fn(),
  setEnabled: vi.fn((value) => value),
  storedPreference: vi.fn(() => null),
  startAmbient: vi.fn(),
  stopAmbient: vi.fn(),
}))

/** `matchMedia` n'évalue rien dans jsdom : c'est ici qu'on décide du système. */
function systemeEnClair(clair) {
  window.matchMedia = vi.fn(() => ({
    matches: clair,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
  }))
}

/** Recharge le module pour que « readStoredTheme » relise le stockage. */
async function store() {
  vi.resetModules()

  const { useUiStore } = await import('@/stores/ui')

  return useUiStore()
}

beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.clear()
  document.documentElement.className = ''
  systemeEnClair(false)
  vi.clearAllMocks()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('store ui — le thème', () => {
  it('applique « clair » et le retient', async () => {
    const ui = await store()

    ui.applyTheme('light')

    // C'est la classe « light » qui bascule, pas « dark » : le sombre est
    // l'état par défaut de cette direction visuelle.
    expect(document.documentElement.classList.contains('light')).toBe(true)
    expect(localStorage.getItem('theme')).toBe('light')
  })

  it('« système » suit la préférence du système', async () => {
    systemeEnClair(true)

    const ui = await store()
    ui.applyTheme('system')

    expect(document.documentElement.classList.contains('light')).toBe(true)

    systemeEnClair(false)
    ui.applyTheme('system')

    expect(document.documentElement.classList.contains('light')).toBe(false)
  })

  it('refuse une valeur inventée et retombe sur « système »', async () => {
    const ui = await store()

    // Vient d'une URL bricolée, d'une extension, ou d'une version antérieure
    // qui écrivait autre chose. Sans validation, le thème deviendrait une
    // chaîne quelconque et la classe ne correspondrait plus à rien.
    ui.applyTheme('néon')

    expect(ui.theme).toBe('system')
    expect(localStorage.getItem('theme')).toBe('system')
  })

  it('ignore un thème stocké illisible', async () => {
    localStorage.setItem('theme', 'neon-des-annees-80')

    const ui = await store()

    expect(ui.theme).toBe('system')
  })

  it('démarre quand même si le stockage est inaccessible', async () => {
    const lire = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('stockage bloqué')
    })
    const ecrire = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('stockage bloqué')
    })

    // Navigation privée stricte, ou cookies tiers refusés. Le thème doit
    // rester actif pour la session ; c'est la seule chose qu'on perd.
    const ui = await store()

    expect(ui.theme).toBe('system')
    expect(() => ui.applyTheme('dark')).not.toThrow()
    expect(ui.theme).toBe('dark')

    lire.mockRestore()
    ecrire.mockRestore()
  })
})

describe('store ui — les notifications', () => {
  it('empile un message et le retire tout seul', async () => {
    vi.useFakeTimers()

    const ui = await store()
    const id = ui.notify('Enregistré.')

    expect(ui.toasts).toHaveLength(1)
    expect(ui.toasts[0]).toMatchObject({ id, message: 'Enregistré.', type: 'success' })

    // Un bandeau qui ne part jamais finit par recouvrir l'écran.
    vi.advanceTimersByTime(4000)
    expect(ui.toasts).toHaveLength(0)
  })

  it('ne retire que celui qu’on désigne', async () => {
    const ui = await store()

    const premier = ui.notify('un')
    ui.notify('deux')

    ui.dismiss(premier)

    expect(ui.toasts.map((t) => t.message)).toEqual(['deux'])
  })

  it('donne des identifiants distincts, même à message identique', async () => {
    const ui = await store()

    // Deux échecs successifs de la même action produisent le même texte : sans
    // identifiants distincts, en fermer un les fermerait tous les deux.
    const a = ui.notify('Échec.', 'error')
    const b = ui.notify('Échec.', 'error')

    expect(a).not.toBe(b)

    ui.dismiss(a)
    expect(ui.toasts).toHaveLength(1)
  })
})

describe('store ui — le tiroir', () => {
  it('bascule, ou obéit à une valeur explicite', async () => {
    const ui = await store()

    expect(ui.sidebarOpen).toBe(false)

    ui.toggleSidebar()
    expect(ui.sidebarOpen).toBe(true)

    // La forme explicite sert aux liens du menu, qui ferment le tiroir sans
    // avoir à savoir s'il était ouvert.
    ui.toggleSidebar(false)
    expect(ui.sidebarOpen).toBe(false)

    ui.toggleSidebar(false)
    expect(ui.sidebarOpen).toBe(false)
  })
})
