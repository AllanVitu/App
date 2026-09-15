import { defineComponent } from 'vue'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useMotion } from '@/composables/useMotion'
import { createScope, prefersReducedMotion, settle } from '@/animations/motion'

/**
 * Le point d'entrée d'animation des écrans publics.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  SOIXANTE-CINQ LIGNES QUI PEUVENT RENDRE UN ÉCRAN INVISIBLE         │
 * │                                                                     │
 * │  anime.js anime depuis un état EXPLICITE : [départ, arrivée]. Ne    │
 * │  rien jouer ne laisse donc pas l'interface dans son état final — ça │
 * │  la laisse à son état de DÉPART, c'est-à-dire opacité zéro.         │
 * │                                                                     │
 * │  En mouvement réduit, il faut POSER l'état final. S'abstenir — le   │
 * │  réflexe évident, et ce que faisait la version GSAP dont ce fichier │
 * │  descend — rendrait la page d'accueil entièrement vide pour les     │
 * │  personnes qui ont désactivé les animations. Aucune suite ne le     │
 * │  verrait : les tests de bout en bout tournent sans cette            │
 * │  préférence, et le rendu « réussit » puisque le HTML est là.        │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Le moteur est remplacé : ce qui se vérifie ici est la DÉCISION — jouer,
 * poser, ou révoquer — et non le travail d'anime.js, qui a ses propres tests.
 */
vi.mock('@/animations/motion', () => ({
  prefersReducedMotion: vi.fn(() => false),
  settle: vi.fn(),

  // `add()` REND LA PORTÉE — signature `add(…): this` dans les types
  // d'anime.js, vérifiée plutôt que supposée. Un simulacre qui renvoie
  // « undefined » fait croire à une fuite qui n'existe pas : le composable
  // écrit `scope = createScope(…).add(setup)`, et c'est ce chaînage qui lui
  // donne de quoi révoquer au démontage.
  createScope: vi.fn(() => {
    const portee = { revert: vi.fn() }

    portee.add = vi.fn(() => portee)

    return portee
  }),
}))

const Harnais = defineComponent({
  props: {
    setup: { type: Function, required: true },
    options: { type: Object, default: () => ({}) },
  },
  setup: (props) => ({ root: useMotion(props.setup, props.options) }),
  template: '<div ref="root"><span data-anim="titre" /><span data-anim="bloc" /><em /></div>',
})

const Vide = defineComponent({
  props: { setup: { type: Function, required: true } },
  setup: (props) => ({ autre: useMotion(props.setup) }),
  template: '<div />',
})

enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.clearAllMocks()
  prefersReducedMotion.mockReturnValue(false)
})

describe('useMotion', () => {
  it('joue les animations dans une portée liée à la racine', () => {
    const jouer = vi.fn()
    const wrapper = mount(Harnais, { props: { setup: jouer } })

    // La portée est ce qui rend la révocation possible : sans elle, une
    // animation en cours continuerait de toucher des nœuds retirés du
    // document.
    expect(createScope).toHaveBeenCalledWith({ root: wrapper.element })
    expect(createScope.mock.results[0].value.add).toHaveBeenCalledWith(jouer)
    expect(settle).not.toHaveBeenCalled()
  })

  it('en mouvement réduit, POSE l’état final au lieu de s’abstenir', () => {
    prefersReducedMotion.mockReturnValue(true)

    const jouer = vi.fn()
    mount(Harnais, { props: { setup: jouer } })

    // Rien n'est joué…
    expect(createScope).not.toHaveBeenCalled()
    expect(jouer).not.toHaveBeenCalled()

    // …mais tout ce qui porte « data-anim » est remis à son état d'arrivée.
    // C'EST LA LIGNE QUI ÉVITE UNE PAGE VIDE.
    expect(settle).toHaveBeenCalledTimes(1)

    const poses = [...settle.mock.calls[0][0]]
    expect(poses).toHaveLength(2)
    expect(poses.map((n) => n.dataset.anim)).toEqual(['titre', 'bloc'])
  })

  it('ne pose que ce qui est déclaré animé', () => {
    prefersReducedMotion.mockReturnValue(true)

    mount(Harnais, { props: { setup: vi.fn() } })

    // L'élément sans « data-anim » n'a jamais été rendu invisible : le poser
    // reviendrait à écrire un état d'arrivée sur quelque chose qui n'a pas
    // d'état de départ.
    const poses = [...settle.mock.calls[0][0]]
    expect(poses.some((n) => n.tagName === 'EM')).toBe(false)
  })

  it('accepte un autre sélecteur de repli', () => {
    prefersReducedMotion.mockReturnValue(true)

    mount(Harnais, {
      props: { setup: vi.fn(), options: { settleSelector: '[data-anim="titre"]' } },
    })

    expect([...settle.mock.calls[0][0]]).toHaveLength(1)
  })

  it('ne fait rien sans racine', () => {
    // La référence n'est posée sur aucun élément : mieux vaut ne rien faire
    // que d'appeler « querySelectorAll » sur null.
    mount(Vide, { props: { setup: vi.fn() } })

    expect(createScope).not.toHaveBeenCalled()
    expect(settle).not.toHaveBeenCalled()
  })

  it('révoque la portée au démontage', () => {
    const wrapper = mount(Harnais, { props: { setup: vi.fn() } })
    const portee = createScope.mock.results[0].value

    wrapper.unmount()

    expect(portee.revert).toHaveBeenCalledTimes(1)
  })

  it('n’a rien à révoquer en mouvement réduit', () => {
    prefersReducedMotion.mockReturnValue(true)

    const wrapper = mount(Harnais, { props: { setup: vi.fn() } })

    // Aucune portée n'a été créée : le démontage ne doit pas lever pour
    // autant.
    expect(() => wrapper.unmount()).not.toThrow()
  })
})
