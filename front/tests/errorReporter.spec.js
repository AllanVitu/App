import { describe, expect, it, vi } from 'vitest'

import { createErrorReporter } from '@/services/errorReporter'

/**
 * Le signalement des erreurs du navigateur.
 *
 * La moitié de ces tests vérifie que RIEN ne part. C'est voulu : un filet qui
 * signale trop inonde la supervision et se fait couper ; un filet qui échoue
 * à signaler et relance sa propre erreur fait tourner l'onglet en boucle.
 */

function monter(options = {}) {
  const send = vi.fn(() => Promise.resolve())
  const signaler = createErrorReporter({ send, ...options })

  return { send, signaler }
}

describe('ce qui part', () => {
  it('une exception, avec son type, sa pile, son écran et son composant', () => {
    const { send, signaler } = monter({ release: '2026.09.13' })
    const erreur = new TypeError('impossible de lire « total »')

    expect(
      signaler('component', erreur, { route: 'module-tickets', component: 'TicketPanel' }),
    ).toBe(true)

    expect(send).toHaveBeenCalledWith({
      kind: 'component',
      message: 'TypeError: impossible de lire « total »',
      stack: erreur.stack,
      route: 'module-tickets',
      component: 'TicketPanel',
      release: '2026.09.13',
    })
  })

  it('un texte rejeté tel quel, sans pile', () => {
    const { send, signaler } = monter()

    signaler('promise', 'délai dépassé')

    expect(send.mock.calls[0][0]).toMatchObject({ message: 'délai dépassé', stack: null })
  })

  it('la même erreur sur un AUTRE écran : ce n’est plus la même panne', () => {
    const { send, signaler } = monter()
    const erreur = new Error('x')

    signaler('component', erreur, { route: 'module-tickets' })
    signaler('component', erreur, { route: 'module-design' })

    expect(send).toHaveBeenCalledTimes(2)
  })

  it('tronque ce que le serveur refuserait', () => {
    const { send, signaler } = monter({ release: 'r'.repeat(80) })
    const erreur = new Error('m'.repeat(5000))
    erreur.stack = 's'.repeat(20000)

    signaler('exception', erreur, { route: 'r'.repeat(500), component: 'c'.repeat(500) })

    const rapport = send.mock.calls[0][0]
    expect(rapport.message).toHaveLength(1000)
    expect(rapport.stack).toHaveLength(8000)
    expect(rapport.route).toHaveLength(120)
    expect(rapport.component).toHaveLength(120)
    expect(rapport.release).toHaveLength(40)
  })
})

describe('ce qui ne part pas', () => {
  it('rien sans session : la route l’exige, et un 401 ne servirait à personne', () => {
    const { send, signaler } = monter({ isEnabled: () => false })

    expect(signaler('component', new Error('x'))).toBe(false)
    expect(send).not.toHaveBeenCalled()
  })

  it('une erreur HTTP normalisée : l’API l’a déjà rangée, ou ce n’est pas une panne', () => {
    const { send, signaler } = monter()

    signaler('promise', { status: 500, message: 'Une erreur interne est survenue.', errors: {} })
    signaler('promise', {
      status: 422,
      message: 'Les données envoyées sont invalides.',
      errors: {},
    })

    expect(send).not.toHaveBeenCalled()
  })

  it('une requête annulée : elle a cédé la place à une plus récente', () => {
    const { send, signaler } = monter()

    signaler('promise', { canceled: true, status: 0 })

    expect(send).not.toHaveBeenCalled()
  })

  it('une valeur qui n’est ni une Error ni un texte', () => {
    const { send, signaler } = monter()

    signaler('promise', { donnees: 'quelconques' })
    signaler('promise', undefined)
    signaler('promise', 42)

    expect(send).not.toHaveBeenCalled()
  })

  it('la même erreur deux fois sur le même écran', () => {
    const { send, signaler } = monter()

    signaler('component', new Error('à chaque image'), { route: 'dashboard' })
    signaler('component', new Error('à chaque image'), { route: 'dashboard' })

    expect(send).toHaveBeenCalledTimes(1)
  })

  it('au-delà du budget de la page', () => {
    const { send, signaler } = monter({ budget: 3 })

    for (let i = 0; i < 10; i++) signaler('exception', new Error(`panne ${i}`))

    expect(send).toHaveBeenCalledTimes(3)
  })
})

describe('un envoi raté ne produit jamais d’erreur', () => {
  it('une promesse rejetée est avalée', async () => {
    const send = vi.fn(() => Promise.reject(new Error('réseau coupé')))
    const signaler = createErrorReporter({ send })

    expect(() => signaler('exception', new Error('x'))).not.toThrow()

    // Un rejet non traité ferait échouer la suite : laisser la file de
    // microtâches se vider suffit à le prouver.
    await new Promise((resolve) => setTimeout(resolve, 0))
  })

  it('un envoi qui lève avant de rendre sa promesse est avalé aussi', () => {
    const signaler = createErrorReporter({
      send: () => {
        throw new Error('client HTTP indisponible')
      },
    })

    expect(() => signaler('exception', new Error('x'))).not.toThrow()
  })
})
