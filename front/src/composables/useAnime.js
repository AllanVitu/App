import { onMounted, onUnmounted, ref } from 'vue'

import { createScope, prefersReducedMotion, settle } from '@/animations/anime'

/**
 * Équivalent d'useGsap pour la moitié publique du front.
 *
 * Le rôle est le même : exécuter les animations dans une portée liée à la
 * racine du composant, et tout révoquer au démontage — sans quoi une
 * animation en cours continuerait de toucher des nœuds retirés du document.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  DIFFÉRENCE ESSENTIELLE AVEC useGsap                                │
 * │                                                                     │
 * │  Côté GSAP, toutes les animations sont des tweens `from()` : ne     │
 * │  rien jouer laisse l'interface dans son état final, correct.        │
 * │                                                                     │
 * │  Ici, les animations partent d'un état explicite [départ, arrivée]. │
 * │  Ne rien jouer laisserait les éléments à leur état de DÉPART —      │
 * │  c'est-à-dire invisibles. En mouvement réduit, il faut donc POSER   │
 * │  l'état final, pas s'abstenir.                                      │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * D'où la convention : tout élément animé porte un attribut `data-anim`.
 * C'est ce qui permet de tous les rétablir sans que le composable ait à
 * connaître leur nature.
 *
 * Utilisation :
 *
 *   const root = useAnime(() => {
 *     animate('[data-anim="panel"]', { opacity: [0, 1], duration: DURATION.base })
 *   })
 *
 *   <template><div ref="root"><div data-anim="panel"> … </div></div></template>
 *
 * @param {(scope: object) => void} setup
 * @param {{ settleSelector?: string }} options
 * @returns {import('vue').Ref<HTMLElement|null>} référence à poser sur la racine
 */
export function useAnime(setup, { settleSelector = '[data-anim]' } = {}) {
  const root = ref(null)
  let scope = null

  onMounted(() => {
    const element = root.value

    if (!element) return

    if (prefersReducedMotion()) {
      // L'écran doit être COMPLET, pas figé à son état de départ.
      settle(element.querySelectorAll(settleSelector))

      return
    }

    scope = createScope({ root: element }).add(setup)
  })

  onUnmounted(() => {
    scope?.revert()
    scope = null
  })

  return root
}
