<script setup>
/**
 * Pastille de confirmation animée : le cercle puis la coche se **tracent**,
 * la pastille rebondit, et une gerbe de particules part du centre.
 *
 * Réservé aux fins de parcours (adresse confirmée, mot de passe réinitialisé) :
 * c'est le seul endroit où une animation franchement expressive se justifie.
 *
 * Ce composant appartient à la moitié PUBLIQUE — il n'est monté que par
 * ResetPasswordView et VerifyEmailView. Il tourne donc sous anime.js, et
 * n'importe jamais GSAP.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'

import { animate, appBounce, prefersReducedMotion, svg, utils } from '@/animations/anime'

const props = defineProps({
  /** Gerbe de particules : à réserver au moment le plus marquant. */
  burst: { type: Boolean, default: false },
  particles: { type: Number, default: 18 },
})

const root = ref(null)

/** Toutes les animations lancées, pour les révoquer au démontage. */
let running = []

onMounted(() => {
  if (prefersReducedMotion() || !root.value) return

  const element = root.value

  running.push(
    animate(element.querySelector('[data-badge]'), {
      scale: [0.3, 1],
      duration: 750,
      ease: appBounce,
    }),
  )

  // createDrawable transforme un tracé SVG en cible animable : il pose le
  // pointillé et son décalage, et expose une propriété « draw » de la forme
  // « début fin ». Aller de « 0 0 » à « 0 1 », c'est dessiner le trait.
  const [circle] = svg.createDrawable(element.querySelector('[data-circle]'))
  const [check] = svg.createDrawable(element.querySelector('[data-check]'))

  running.push(
    animate(circle, { draw: ['0 0', '0 1'], duration: 500, delay: 50 }),
    animate(check, { draw: ['0 0', '0 1'], duration: 350, delay: 350 }),
  )

  if (!props.burst) return

  // --- Gerbe de particules -------------------------------------------------
  //
  // GSAP avait un moteur pour cela (Physics2DPlugin). anime.js n'en a pas, et
  // il n'en faut pas : une particule lancée puis soumise à la gravité suit
  //     x = vx·t          y = vy·t + ½·g·t²
  // Deux lignes d'arithmétique, et la trajectoire est exacte plutôt
  // qu'approchée par une courbe d'accélération.
  const shots = [...element.querySelectorAll('[data-particle]')].map((node) => ({
    node,
    // Vers le HAUT : les angles sont pris dans la moitié supérieure, et l'axe
    // y de l'écran descend — d'où le sinus négatif.
    angle: utils.random(200, 340) * (Math.PI / 180),
    velocity: utils.random(160, 300),
    scale: utils.random(40, 100) / 100,
  }))

  const GRAVITY = 420

  // UNE seule animation pilote les dix-huit particules : elles partagent la
  // même horloge, donc la même image. Dix-huit animations concurrentes
  // donneraient dix-huit calculs de progression pour le même instant.
  // L'objet piloté est muté en place par anime.js : on le lit directement
  // plutôt que de passer par les cibles du rappel.
  const driver = { t: 0 }

  running.push(
    animate(driver, {
      t: 1,
      duration: 1400,
      delay: 320,
      ease: 'linear',
      onUpdate: () => {
        const t = driver.t

        for (const shot of shots) {
          const x = Math.cos(shot.angle) * shot.velocity * t
          const y = Math.sin(shot.angle) * shot.velocity * t + 0.5 * GRAVITY * t * t

          shot.node.style.transform = `translate(${x.toFixed(1)}px, ${y.toFixed(1)}px) scale(${shot.scale})`
          // Apparition franche puis disparition progressive : la particule
          // ne doit pas s'éteindre avant d'avoir été vue partir.
          shot.node.style.opacity = String(t < 0.08 ? t / 0.08 : 1 - (t - 0.08) / 0.92)
        }
      },
    }),
  )
})

// Révocation explicite : aucune animation ne survit au composant.
onBeforeUnmount(() => {
  for (const animation of running) animation?.revert?.()
  running = []
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
