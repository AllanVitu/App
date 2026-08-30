import { onMounted, onUnmounted, ref } from 'vue'

import { gsap, prefersReducedMotion } from '@/animations/gsap'

/**
 * Équivalent Vue du hook `useGSAP` de @gsap/react (qui, lui, est réservé à
 * React et ne peut pas être utilisé ici).
 *
 * Le rôle :
 *  - exécuter les animations dans un `gsap.context()` porté par la racine du
 *    composant, pour que les sélecteurs (« .card », « h1 »…) soient
 *    automatiquement limités à ce composant ;
 *  - tout révoquer au démontage — sans quoi un ScrollTrigger ou un Draggable
 *    survivrait à la vue et provoquerait une fuite mémoire.
 *
 * Ce module ne sert plus QUE la moitié application : les écrans publics ont
 * leur équivalent, `composables/useAnime.js`. Les deux ont volontairement le
 * même contrat, à une différence près, documentée là-bas : côté GSAP toutes
 * les animations sont des tweens `from()`, donc ne rien jouer laisse
 * l'interface dans son état final ; côté anime.js il faut POSER cet état.
 *
 * Une variante `useGsap(setup)` — contexte créé au montage — existait ici.
 * Son dernier appelant était AuthLayout, passé à anime.js : elle a été
 * retirée plutôt que laissée sans usage.
 *
 * Utilisation :
 *
 *   const { root, run } = useGsapContext()
 *   run(() => gsap.fromTo('.card', { opacity: 0 }, { opacity: 1 }))
 *
 *   <template><div ref="root"> … </div></template>
 *
 * @returns {{ root: import('vue').Ref<HTMLElement|null>, run: (fn: Function) => void }}
 */
export function useGsapContext() {
  const root = ref(null)
  let context = null

  onMounted(() => {
    context = gsap.context(() => {}, root.value ?? undefined)
  })

  onUnmounted(() => {
    context?.revert()
    context = null
  })

  return {
    root,
    /** Exécute une fonction d'animation dans le contexte du composant. */
    run(fn) {
      if (prefersReducedMotion()) return

      context ? context.add(fn) : fn()
    },
  }
}
