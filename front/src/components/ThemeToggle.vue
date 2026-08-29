<script setup>
/**
 * Bascule clair / sombre.
 *
 * L'icône n'est pas remplacée : le disque du soleil se **transforme** en
 * croissant de lune (MorphSVGPlugin) pendant que les rayons se rétractent.
 * Un seul objet à l'écran, donc une transformation lisible — là où deux
 * icônes qui se substituent ne racontent rien.
 */
import { onMounted, ref, useId } from 'vue'

import { gsap, prefersReducedMotion } from '@/animations/gsap'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()

// Identifiants uniques : MorphSVG cible les formes par sélecteur, deux
// instances du composant ne doivent pas se marcher dessus.
const uid = useId().replace(/[^a-zA-Z0-9-_]/g, '')
const shapeId = `sun-${uid}`
const moonId = `moon-${uid}`

const svg = ref(null)
const isDark = ref(false)

/** Disque solaire, tracé comme un chemin : MorphSVG n'accepte pas <circle>. */
const SUN_PATH = 'M12 7.2a4.8 4.8 0 1 0 0 9.6 4.8 4.8 0 0 0 0-9.6z'
const MOON_PATH = 'M20.7 13.1A8.4 8.4 0 1 1 10.9 3.3a6.6 6.6 0 0 0 9.8 9.8z'

function paint(animated) {
  if (!svg.value) return

  const rays = svg.value.querySelectorAll('[data-ray]')
  const shape = svg.value.querySelector(`#${shapeId}`)
  const duration = animated && !prefersReducedMotion() ? 0.45 : 0

  gsap.to(shape, {
    morphSVG: isDark.value ? `#${moonId}` : SUN_PATH,
    duration,
    ease: 'appEnter',
  })

  gsap.to(rays, {
    scale: isDark.value ? 0 : 1,
    opacity: isDark.value ? 0 : 1,
    duration: duration * 0.8,
    transformOrigin: 'center',
    stagger: { each: 0.02, from: 'random' },
  })

  // Petite rotation à chaque bascule : rend le changement perceptible même
  // du coin de l'œil.
  if (duration > 0) {
    gsap.fromTo(svg.value, { rotate: isDark.value ? -40 : 40 }, { rotate: 0, duration: 0.5 })
  }
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
    class="rounded-pill p-2 text-ink-2 transition-colors hover:bg-raised hover:text-ink"
    :aria-label="isDark ? 'Passer au thème clair' : 'Passer au thème sombre'"
    :aria-pressed="isDark"
    @click="toggle"
  >
    <svg
      ref="svg"
      width="17"
      height="17"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="1.5"
      stroke-linecap="round"
      aria-hidden="true"
    >
      <!-- Cible du morphing, jamais rendue -->
      <path :id="moonId" :d="MOON_PATH" class="hidden" />

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
