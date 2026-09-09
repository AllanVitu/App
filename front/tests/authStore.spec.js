import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/auth'
import { authApi } from '@/services/api'
import { setAccessToken } from '@/services/http'
import { setTimeZone } from '@/utils/format'

/**
 * La session.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  148 LIGNES DONT DÉPEND TOUT LE RESTE                               │
 * │                                                                     │
 * │  Ce store décide qui est connecté, où va le jeton d'accès, et       │
 * │  quand la session est vidée. Les parcours navigateur le traversent  │
 * │  trente fois par exécution — mais toujours par le chemin heureux :  │
 * │  on se connecte, ça marche.                                         │
 * │                                                                     │
 * │  Les cas qui comptent sont les autres. Une déconnexion pendant une  │
 * │  coupure réseau. Un jeton sans utilisateur. Un cookie absent, qui   │
 * │  n'est pas une erreur mais l'état normal d'un visiteur.             │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Les frontières sont remplacées — API, client HTTP, formateurs de dates.
 * Ce qui se vérifie ici, ce sont les DÉCISIONS du store, pas le réseau.
 */
vi.mock('@/services/api', () => ({
  authApi: {
    login: vi.fn(),
    register: vi.fn(),
    refresh: vi.fn(),
    logout: vi.fn(),
    me: vi.fn(),
  },
}))

vi.mock('@/services/http', () => ({
  setAccessToken: vi.fn(),
  configureSession: vi.fn(),
}))

vi.mock('@/utils/format', () => ({ setTimeZone: vi.fn() }))

const SESSION = { user: { id: 'u1', full_name: 'Camille Dupont' }, access_token: 'jeton-1' }
const PROFIL = {
  user: { id: 'u1', full_name: 'Camille Dupont' },
  settings: { timezone: 'Europe/Paris', theme: 'dark' },
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
})

describe('store auth', () => {
  // ────────────────────────────────────────────────── ce qui définit « connecté »

  it('exige un utilisateur ET un jeton pour se dire connecté', async () => {
    const auth = useAuthStore()

    expect(auth.isAuthenticated).toBe(false)

    // Un utilisateur sans jeton n'est pas une session : ce serait un écran
    // rempli de données que la moindre requête refuserait de servir.
    auth.setUser({ id: 'u1', full_name: 'Camille Dupont' })
    expect(auth.isAuthenticated).toBe(false)

    authApi.login.mockResolvedValue(SESSION)
    authApi.me.mockResolvedValue(PROFIL)
    await auth.login({ email: 'a@b.c', password: 'x' })

    expect(auth.isAuthenticated).toBe(true)
  })

  it('confie le jeton au client HTTP, et jamais au stockage', async () => {
    const auth = useAuthStore()

    authApi.login.mockResolvedValue(SESSION)
    authApi.me.mockResolvedValue(PROFIL)
    await auth.login({ email: 'a@b.c', password: 'x' })

    // Le jeton vit EN MÉMOIRE : une faille XSS ne peut pas l'exfiltrer d'un
    // localStorage où il ne se trouve pas. La persistance entre deux
    // rechargements passe par le cookie HttpOnly, pas par là.
    expect(setAccessToken).toHaveBeenCalledWith('jeton-1')
    expect(localStorage.getItem('access_token')).toBeNull()
  })

  // ───────────────────────────────────────────── le fuseau, appliqué et non stocké

  it('applique le fuseau horaire dès que les paramètres arrivent', async () => {
    const auth = useAuthStore()

    authApi.login.mockResolvedValue(SESSION)
    authApi.me.mockResolvedValue(PROFIL)
    await auth.login({ email: 'a@b.c', password: 'x' })

    // Un fuseau qui reste une valeur en base ne sert à rien. C'est ici, au
    // seul endroit où les paramètres arrivent, qu'il est poussé dans les
    // formateurs — sinon toute l'application resterait au fuseau du
    // navigateur tant qu'on n'aurait pas ouvert l'écran Paramètres.
    expect(setTimeZone).toHaveBeenCalledWith('Europe/Paris')

    auth.setSettings({ timezone: 'America/Montreal' })
    expect(setTimeZone).toHaveBeenLastCalledWith('America/Montreal')
  })

  it('revient au fuseau du navigateur en vidant la session', async () => {
    const auth = useAuthStore()

    authApi.login.mockResolvedValue(SESSION)
    authApi.me.mockResolvedValue(PROFIL)
    await auth.login({ email: 'a@b.c', password: 'x' })

    authApi.logout.mockResolvedValue()
    await auth.logout()

    // Sans cela, l'écran de connexion afficherait les dates au fuseau de
    // celui qui vient de partir.
    expect(setTimeZone).toHaveBeenLastCalledWith(null)
  })

  // ───────────────────────────────────────────────── la déconnexion, quoi qu'il arrive

  it('vide la session même quand la déconnexion échoue', async () => {
    const auth = useAuthStore()

    authApi.login.mockResolvedValue(SESSION)
    authApi.me.mockResolvedValue(PROFIL)
    await auth.login({ email: 'a@b.c', password: 'x' })
    expect(auth.isAuthenticated).toBe(true)

    // ┌─────────────────────────────────────────────────────────────────┐
    // │  LE CAS QUI COMPTE                                              │
    // │                                                                 │
    // │  Réseau coupé, serveur en vrac : l'appel échoue. Si la session  │
    // │  locale survivait, l'utilisateur resterait bloqué en état       │
    // │  « connecté » sur un écran qui ne peut plus rien charger, sans  │
    // │  moyen d'en sortir autrement qu'en vidant son navigateur.       │
    // └─────────────────────────────────────────────────────────────────┘
    authApi.logout.mockRejectedValue(new Error('réseau'))

    await expect(auth.logout()).rejects.toThrow('réseau')
    expect(auth.isAuthenticated).toBe(false)
    expect(setAccessToken).toHaveBeenLastCalledWith(null)
  })

  // ──────────────────────────────────────────────────────── le démarrage

  it('traite l’absence de cookie comme un état normal', async () => {
    const auth = useAuthStore()

    // Un visiteur non connecté n'est pas une erreur. Si « initialize »
    // relançait, la garde de navigation ne se terminerait jamais et
    // l'application resterait sur un écran blanc.
    authApi.refresh.mockRejectedValue(new Error('401'))

    await expect(auth.initialize()).resolves.toBeUndefined()

    expect(auth.ready).toBe(true)
    expect(auth.isAuthenticated).toBe(false)
  })

  it('restaure la session quand le cookie est valide', async () => {
    const auth = useAuthStore()

    authApi.refresh.mockResolvedValue(SESSION)
    authApi.me.mockResolvedValue(PROFIL)

    await auth.initialize()

    expect(auth.isAuthenticated).toBe(true)
    expect(auth.settings.theme).toBe('dark')
  })

  it('ne se restaure qu’une fois', async () => {
    const auth = useAuthStore()

    authApi.refresh.mockResolvedValue(SESSION)
    authApi.me.mockResolvedValue(PROFIL)

    await auth.initialize()
    await auth.initialize()

    // La garde de navigation l'appelle à chaque route : sans ce court-circuit,
    // chaque changement d'écran paierait un aller-retour de rafraîchissement.
    expect(authApi.refresh).toHaveBeenCalledTimes(1)
  })

  // ─────────────────────────────────────────────────────────── les initiales

  it('compose les initiales sans jamais lever', () => {
    const auth = useAuthStore()

    expect(auth.initials).toBe('?')

    auth.setUser({ full_name: 'Camille Dupont' })
    expect(auth.initials).toBe('CD')

    // Trois mots : on n'en garde que deux, sinon la pastille déborde.
    auth.setUser({ full_name: 'Jean Michel Dupont' })
    expect(auth.initials).toBe('JM')

    auth.setUser({ full_name: 'Camille' })
    expect(auth.initials).toBe('C')

    // Espaces multiples : « filter(Boolean) » évite de lire le premier
    // caractère d'une chaîne vide, qui lèverait.
    auth.setUser({ full_name: '  Camille   Dupont  ' })
    expect(auth.initials).toBe('CD')

    auth.setUser({ full_name: '' })
    expect(auth.initials).toBe('?')
  })
})
