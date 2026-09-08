import {
  animate,
  appEnter,
  prefersReducedMotion,
  settle,
  stagger,
  utils,
} from '@/animations/motion'

/**
 * Révélation de lignes à l'approche du défilement.
 *
 * Remplace `ScrollTrigger.batch` de GSAP, et pas seulement pour le poids :
 * ScrollTrigger recalcule des positions À CHAQUE défilement, pour tous les
 * déclencheurs enregistrés. Un observateur d'intersection ne travaille que
 * lorsqu'un élément traverse réellement le bord de la fenêtre — le calcul
 * est fait par le navigateur, hors du fil principal.
 *
 * Le regroupement de `batch` est conservé, et c'est ce qui compte
 * visuellement : les lignes qui deviennent visibles ensemble entrent en UNE
 * cascade, pas en douze animations isolées. L'observateur livrant déjà ses
 * entrées par paquets, il suffit de laisser passer une image avant de jouer.
 *
 * @param {Iterable<Element>} elements
 * @param {{ step?: number }} [options] décalage entre deux lignes, en ms
 * @returns {() => void} à appeler au démontage
 */
export function revealOnScroll(elements, { step = 50 } = {}) {
  const rows = Array.from(elements)
  const noop = () => {}

  if (!rows.length) return noop

  // Mouvement réduit : on POSE l'état final. Ne rien faire laisserait les
  // lignes à leur état de départ — invisibles.
  if (prefersReducedMotion()) {
    settle(rows)

    return noop
  }

  utils.set(rows, { opacity: 0, translateY: 16 })

  let pending = []
  let frame = 0

  function flush() {
    frame = 0

    const batch = pending

    pending = []

    animate(batch, {
      translateY: [16, 0],
      opacity: [0, 1],
      duration: 450,
      delay: stagger(step),
      ease: appEnter,
    })
  }

  const observer = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue

        // Une seule fois par ligne : la révélation raconte l'arrivée, elle
        // n'a pas à se rejouer à chaque aller-retour de défilement.
        observer.unobserve(entry.target)
        pending.push(entry.target)
      }

      if (pending.length && !frame) frame = requestAnimationFrame(flush)
    },
    // Marge négative en bas : la ligne entre quand elle est franchement dans
    // le champ, pas au moment où son premier pixel affleure.
    { rootMargin: '0px 0px -5% 0px' },
  )

  rows.forEach((row) => observer.observe(row))

  return () => {
    observer.disconnect()
    cancelAnimationFrame(frame)
    // Filet : une ligne démontée en cours de route ne doit pas laisser sa
    // remplaçante hériter d'une opacité nulle.
    settle(rows)
  }
}
