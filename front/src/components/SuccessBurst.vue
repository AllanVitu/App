<script setup>
/**
 * Pastille de confirmation animée : le cercle puis la coche se **tracent**
 * (DrawSVGPlugin), la pastille rebondit (CustomBounce), et une gerbe de
 * particules part du centre (Physics2DPlugin).
 *
 * Réservé aux fins de parcours (adresse confirmée, mot de passe réinitialisé) :
 * c'est le seul endroit où une animation franchement expressive se justifie.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'

import { gsap, prefersReducedMotion } from '@/animations/gsap'

const props = defineProps({
  /** Gerbe de particules : à réserver au moment le plus marquant. */
  burst: { type: Boolean, default: false },
  particles: { type: Number, default: 18 },
})

const root = ref(null)

let context = null

onMounted(() => {
  if (prefersReducedMotion() || !root.value) return

  context = gsap.context(() => {
    const timeline = gsap.timeline()

    timeline
      .fromTo('[data-badge]', { scale: 0.3 }, { scale: 1, duration: 0.75, ease: 'appBounce' })
      .fromTo('[data-circle]', { drawSVG: '0%' }, { drawSVG: '100%', duration: 0.5 }, 0.05)
      .fromTo('[data-check]', { drawSVG: '0%' }, { drawSVG: '100%', duration: 0.35 }, 0.35)

    if (!props.burst) return

    // Les particules partent du centre avec un angle et une vitesse tirés au
    // sort, puis retombent : c'est le moteur de Physics2DPlugin, pas une
    // trajectoire scriptée à la main.
    timeline.fromTo('[data-particle]', { opacity: 0 }, { opacity: 1, duration: 0.1 }, 0.3)

    timeline.to(
      '[data-particle]',
      {
        duration: 1.4,
        physics2D: {
          velocity: 'random(160, 300)',
          angle: 'random(200, 340)',
          gravity: 420,
        },
        opacity: 0,
        scale: 'random(0.4, 1)',
        ease: 'none',
        stagger: 0.012,
      },
      0.32,
    )
  }, root.value)
})

// Révocation explicite : aucune timeline ne survit au composant.
onBeforeUnmount(() => {
  context?.revert()
  context = null
})
</script>

<template>
  <div ref="root" class="relative mx-auto w-fit">
    <!-- Particules : purement décoratives, hors flux -->
    <div
      v-if="burst"
      class="pointer-events-none absolute left-1/2 top-1/2 size-0"
      aria-hidden="true"
    >
      <span
        v-for="n in particles"
        :key="n"
        data-particle
        class="absolute size-1"
        :class="['bg-ink', n % 3 === 0 ? 'bg-moss' : '', n % 4 === 0 ? 'bg-ochre' : '']"
      />
    </div>

    <div
      data-badge
      class="relative flex size-14 items-center justify-center border border-moss bg-moss-bg text-moss"
    >
      <svg width="34" height="34" viewBox="0 0 36 36" fill="none" aria-hidden="true">
        <circle
          data-circle
          cx="18"
          cy="18"
          r="14"
          stroke="currentColor"
          stroke-width="2"
          opacity="0.35"
        />
        <path
          data-check
          d="M11.5 18.5l4.5 4.5 9-9.5"
          stroke="currentColor"
          stroke-width="2.6"
          stroke-linecap="round"
          stroke-linejoin="round"
        />
      </svg>
    </div>
  </div>
</template>
