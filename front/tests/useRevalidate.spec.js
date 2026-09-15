import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useRevalidate } from '@/composables/useRevalidate'

/**
 * Le rafraîchissement au retour sur l'onglet.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  RIEN DE TOUT CECI N'EST OBSERVABLE DANS UN NAVIGATEUR PILOTÉ       │
 * │                                                                     │
 * │  Playwright ne sait pas cacher un onglet pendant trente et une      │
 * │  secondes, ni couper le réseau puis le rendre, sans allonger la     │
 * │  suite d'autant. Ici, l'horloge est feinte et les deux se jouent en │
 * │  quelques millisecondes.                                            │
 * │                                                                     │
 * │  Et ce qui compte le plus, ce n'est PAS que le rechargement parte : │
 * │  c'est qu'il ne parte pas quand il ne faut pas. Un rechargement de  │
 * │  trop fait sauter la liste sous les yeux, ou lève une erreur alors  │
 * │  qu'on est simplement hors ligne.                                   │
 * └─────────────────────────────────────────────────────────────────────┘
 */

const Vide = { template: '<div />' }

/** Monte un composant réduit à l'appel du composable. */
function monter(recharger, options) {
  return mount({
    setup() {
      useRevalidate(recharger, options)

      return {}
    },
    ...Vide,
  })
}

/** Simule le passage en arrière-plan puis le retour, `ms` plus tard. */
function absence(ms) {
  visible('hidden')
  document.dispatchEvent(new Event('visibilitychange'))

  vi.advanceTimersByTime(ms)

  visible('visible')
  document.dispatchEvent(new Event('visibilitychange'))
}

function visible(etat) {
  Object.defineProperty(document, 'visibilityState', { value: etat, configurable: true })
}

function enLigne(valeur) {
  Object.defineProperty(navigator, 'onLine', { value: valeur, configurable: true })
}

beforeEach(() => {
  vi.useFakeTimers()
  visible('visible')
  enLigne(true)
})

afterEach(() => {
  vi.useRealTimers()
})

describe('useRevalidate', () => {
  it('relit après une absence qui a duré', async () => {
    const recharger = vi.fn()
    monter(recharger, { apres: 30_000 })

    absence(45_000)

    expect(recharger).toHaveBeenCalledTimes(1)
  })

  it('ne relit pas pour un aller-retour de deux secondes', async () => {
    const recharger = vi.fn()
    monter(recharger, { apres: 30_000 })

    // Copier une empreinte de commit depuis un autre onglet et revenir. Sous
    // le seuil, l'utilisateur n'est jamais vraiment parti : recharger ferait
    // sauter la liste sous ses yeux pour ne rien lui apprendre.
    absence(2_000)

    expect(recharger).not.toHaveBeenCalled()
  })

  it('ne relit pas au montage', async () => {
    const recharger = vi.fn()
    monter(recharger)

    // L'onglet n'a jamais été caché : la vue vient de charger d'elle-même.
    // Sans la marque de départ, un simple événement de visibilité au montage
    // déclencherait un second appel immédiat.
    document.dispatchEvent(new Event('visibilitychange'))

    expect(recharger).not.toHaveBeenCalled()
  })

  it('ne relit pas hors ligne', async () => {
    const recharger = vi.fn()
    monter(recharger, { apres: 1_000 })

    enLigne(false)
    absence(60_000)

    // La requête échouerait, et l'écran afficherait une erreur pour un
    // rafraîchissement que personne n'a demandé.
    expect(recharger).not.toHaveBeenCalled()
  })

  it('relit dès que le réseau revient', async () => {
    const recharger = vi.fn()
    monter(recharger)

    enLigne(true)
    window.dispatchEvent(new Event('online'))

    // Une coupure laisse l'écran figé sur ce qu'il avait ; la connexion
    // revenue, rien ne justifie d'attendre en plus un changement d'onglet.
    expect(recharger).toHaveBeenCalledTimes(1)
  })

  it('ignore le retour du réseau si l’onglet est caché', async () => {
    const recharger = vi.fn()
    monter(recharger)

    visible('hidden')
    window.dispatchEvent(new Event('online'))

    // Il sera traité à son retour, avec son propre seuil. Recharger un onglet
    // qu'on ne regarde pas dépense une requête pour un écran que personne ne
    // verra dans cet état.
    expect(recharger).not.toHaveBeenCalled()
  })

  it('obéit au veto de la vue', async () => {
    const recharger = vi.fn()
    let ouvert = true

    monter(recharger, { apres: 1_000, actif: () => !ouvert })

    absence(60_000)
    expect(recharger).not.toHaveBeenCalled()

    // Le veto est consulté AU DERNIER MOMENT, pas au montage : une vue peut
    // s'ouvrir et se fermer entre-temps.
    ouvert = false
    absence(60_000)

    expect(recharger).toHaveBeenCalledTimes(1)
  })

  it('ne laisse aucun écouteur derrière lui', async () => {
    const recharger = vi.fn()
    const wrapper = monter(recharger, { apres: 1_000 })

    wrapper.unmount()

    absence(60_000)
    window.dispatchEvent(new Event('online'))

    // Un écouteur oublié rechargerait au nom d'un écran qui n'existe plus, et
    // écrirait dans des références que personne ne lit.
    expect(recharger).not.toHaveBeenCalled()
  })
})
