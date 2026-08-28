import { onMounted, onUnmounted, ref } from 'vue'

import { gsap, prefersReducedMotion } from '@/animations/gsap'

/**
 * Équivalent Vue du hook `useGSAP` de @gsap/react (qui, lui, est réservé à
 * React et ne peut pas être utilisé ici).
 *
 * Le rôle est le même :
 *  - exécuter les animations dans un `gsap.context()` porté par la racine du
 *    composant, pour que les sélecteurs (« .card », « h1 »…) soient
 *    automatiquement limités à ce composant ;
 *  - tout révoquer au démontage — sans quoi un ScrollTrigger ou un Draggable
 *    survivrait à la vue et provoquerait une fuite mémoire.
 *
 * Utilisation :
 *
 *   const root = useGsap((ctx, self) => {
 *     gsap.from('.card', { y: 20, opacity: 0, stagger: 0.06 })
 *   })
 *
 *   <template><div ref="root"> … </div></template>
 *
 * @param {(context: object) => void} setup
 * @param {{ respectReducedMotion?: boolean }} options
 * @returns {import('vue').Ref<HTMLElement|null>} référence à poser sur la racine
 */
export function useGsap(setup, { respectReducedMotion = true } = {}) {
  const root = ref(null)
  let context = null

  onMounted(() => {
    // Réglage système « animations réduites » : on ne joue rien. Toutes les
    // animations du projet étant des tweens `from()`, l'absence d'exécution
    // laisse l'interface dans son état final — rien à compenser.
    if (respectReducedMotion && prefersReducedMotion()) {
      return
    }

    context = gsap.context(setup, root.value ?? undefined)
  })

  onUnmounted(() => {
    context?.revert()
    context = null
  })

  return root
}

/**
 * Variante impérative, pour animer en dehors du montage (clic, réponse
 * serveur…) tout en gardant le nettoyage automatique.
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
