<script setup>
/**
 * Bascule clair / sombre.
 *
 * L'icône n'est pas remplacée : le disque du soleil se **transforme** en
 * croissant de lune pendant que les rayons se rétractent. Un seul objet à
 * l'écran, donc une transformation lisible — là où deux icônes qui se
 * substituent ne racontent rien.
 *
 * Le morphing était assuré par MorphSVGPlugin (GSAP). anime.js le fait
 * nativement avec `morphTo`, qui rééchantillonne les deux tracés sur un
 * même nombre de points puis interpole. D'où la contrainte ci-dessous : les
 * DEUX formes doivent exister dans le document, car la cible est mesurée
 * (`getTotalLength`), pas devinée.
 */
import { onMounted, ref, useId } from 'vue'

import { animate, appEnter, morphTo, prefersReducedMotion, stagger } from '@/animations/motion'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()

// Identifiants uniques : le morphing cible les formes par sélecteur, deux
// instances du composant ne doivent pas se marcher dessus.
const uid = useId().replace(/[^a-zA-Z0-9-_]/g, '')
const shapeId = `sun-${uid}`
const sunTargetId = `sun-target-${uid}`
const moonId = `moon-${uid}`

const root = ref(null)
const isDark = ref(false)

/** Disque solaire tracé comme un chemin : le morphing n'accepte pas <circle>. */
const SUN_PATH = 'M12 7.2a4.8 4.8 0 1 0 0 9.6 4.8 4.8 0 0 0 0-9.6z'
const MOON_PATH = 'M20.7 13.1A8.4 8.4 0 1 1 10.9 3.3a6.6 6.6 0 0 0 9.8 9.8z'

function paint(animated) {
  if (!root.value) return

  const rays = root.value.querySelectorAll('[data-ray]')
  const shape = root.value.querySelector(`#${shapeId}`)
  const dark = isDark.value

  // Sans mouvement, on POSE l'état : une animation de durée nulle ferait le
  // même travail, mais passerait par le moteur pour rien — et ce chemin-ci
  // est aussi celui du premier rendu, avant toute interaction.
  if (!animated || prefersReducedMotion()) {
    shape.setAttribute('d', dark ? MOON_PATH : SUN_PATH)
    rays.forEach((ray) => {
      ray.style.opacity = dark ? '0' : '1'
      ray.style.transform = dark ? 'scale(0)' : 'scale(1)'
    })

    return
  }

  animate(shape, {
    // Précision 1 point par unité de longueur : sur une icône de 24 px, le
    // disque fait ~30 unités de tour, donc ~30 points. Assez pour que le
    // cercle reste rond, assez peu pour que le calcul soit gratuit.
    d: morphTo(dark ? `#${moonId}` : `#${sunTargetId}`, 1),
    duration: 450,
    ease: appEnter,
  })

  animate(rays, {
    scale: dark ? 0 : 1,
    opacity: dark ? 0 : 1,
    duration: 360,
    delay: stagger(20, { from: 'random' }),
    ease: appEnter,
  })

  // Petite rotation à chaque bascule : rend le changement perceptible même
  // du coin de l'œil.
  animate(root.value, {
    rotate: [dark ? -40 : 40, 0],
    duration: 500,
    ease: appEnter,
  })
}

function toggle() {
  isDark.value = !isDark.value
  ui.applyTheme(isDark.value ? 'dark' : 'light')
  paint(true)
}

onMounted(() => {
  // L'état de départ vient du DOM : le thème est appliqué par le script
  // inline d'index.html, avant même le montage de Vue. Le sombre étant le
  // défaut, c'est l'ABSENCE de « light » qui le signale.
  isDark.value = !document.documentElement.classList.contains('light')
  paint(false)
})
</script>

<template>
  <button
    type="button"
    class="rounded-field p-2 text-ink-2 transition-colors hover:bg-raised hover:text-ink"
    :aria-label="isDark ? 'Passer au thème clair' : 'Passer au thème sombre'"
    :aria-pressed="isDark"
    @click="toggle"
  >
    <svg
      ref="root"
      width="17"
      height="17"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="1.5"
      stroke-linecap="round"
      aria-hidden="true"
    >
      <!-- Cibles du morphing. Dans <defs> et non masquées par une classe :
           une forme en « display: none » n'a pas de géométrie mesurable dans
           tous les navigateurs, et `morphTo` mesure sa cible. -->
      <defs>
        <path :id="moonId" :d="MOON_PATH" />
        <path :id="sunTargetId" :d="SUN_PATH" />
      </defs>

      <path :id="shapeId" :d="SUN_PATH" />

      <g>
        <path data-ray d="M12 1.6v2" />
        <path data-ray d="M12 20.4v2" />
        <path data-ray d="M4.2 4.2l1.4 1.4" />
        <path data-ray d="M18.4 18.4l1.4 1.4" />
        <path data-ray d="M1.6 12h2" />
        <path data-ray d="M20.4 12h2" />
        <path data-ray d="M4.2 19.8l1.4-1.4" />
        <path data-ray d="M18.4 5.6l1.4-1.4" />
      </g>
    </svg>
  </button>
</template>
