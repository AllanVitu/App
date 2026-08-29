<script setup>
/**
 * Galerie des modules : spirale ou liste.
 *
 * Les MÊMES éléments du DOM servent aux deux dispositions — seules leurs
 * classes et leurs positions changent. C'est ce qui permet à Flip d'animer
 * le passage : chaque tuile glisse de son ancienne place vers la nouvelle,
 * au lieu de disparaître d'un côté pour réapparaître de l'autre.
 *
 * La spirale n'est qu'un arrangement visuel. Dans le DOM, les tuiles
 * restent des liens dans l'ordre du catalogue : la navigation au clavier et
 * les lecteurs d'écran suivent cet ordre, pas la courbe.
 */
import { computed, nextTick, onMounted, ref } from 'vue'

import { Flip, gsap, prefersReducedMotion } from '@/animations/gsap'
import AppIcon from '@/components/AppIcon.vue'
import { play } from '@/services/sound'

const props = defineProps({
  modules: { type: Array, default: () => [] },
})

const STORAGE_KEY = 'modules-view'
const MODES = ['spiral', 'list']

const root = ref(null)

/** Mode retenu d'une visite à l'autre : c'est une préférence, pas un état. */
const mode = ref(readStoredMode())

function readStoredMode() {
  try {
    const stored = localStorage.getItem(STORAGE_KEY)

    return MODES.includes(stored) ? stored : 'spiral'
  } catch {
    return 'spiral'
  }
}

/**
 * Spirale d'Archimède : le rayon croît linéairement avec l'angle,
 * r(θ) = r₀ + k·θ. Les tuiles s'éloignent donc du centre à pas constant,
 * ce qui garde l'écart entre elles régulier — une spirale logarithmique
 * les tasserait au centre et les disperserait à l'extérieur.
 */
// Géométrie contrainte par deux exigences, et non choisie à vue.
//
// 1. CONTENANCE — la tuile la plus externe doit tenir dans le cadre :
//    R0 + K·(n−1)·TURN + demi-largeur ≤ 50.
//    Ici : 15 + 2,42 × 9,08 + 11 = 48.
//
// 2. SÉPARATION — deux tuiles voisines ne doivent pas se confondre. La corde
//    entre elles vaut 2·r·sin(TURN/2) ; il la faut supérieure à la largeur
//    d'une tuile. Au rayon moyen (26) : 2 × 26 × sin(65°) ≈ 47, pour des
//    tuiles de 22 unités.
//
// Un premier essai avec un pas angulaire serré (83°) et un pas radial de 5,5
// échouait sur ce second point : les tuiles s'empilaient en grappe au centre
// et la figure ne se lisait pas comme une spirale.
const TURN = 2.27 // écart angulaire entre deux tuiles, en radians (130°)
const R0 = 15 // rayon de départ, en % du conteneur
const K = 2.42 // croissance du rayon par radian

/** Position brute d'une tuile sur la courbe, avant recentrage. */
function pointAt(index) {
  const angle = index * TURN - Math.PI / 2 // départ en haut plutôt qu'à droite
  const radius = R0 + K * (index * TURN)

  return { angle, x: radius * Math.cos(angle), y: radius * Math.sin(angle) }
}

/**
 * Décalage de recentrage.
 *
 * Une spirale n'est pas centrée sur son origine : avec cinq tuiles réparties
 * sur une révolution et demie, la matière se concentre d'un côté. Placer
 * l'origine au milieu du cadre laissait donc un grand vide en haut à gauche
 * et débordait en bas à droite.
 *
 * On centre la BOÎTE ENGLOBANTE des tuiles, pas la courbe.
 */
const offset = computed(() => {
  const points = props.modules.map((_, index) => pointAt(index))

  if (points.length === 0) return { x: 0, y: 0 }

  const xs = points.map((point) => point.x)
  const ys = points.map((point) => point.y)

  return {
    x: -(Math.min(...xs) + Math.max(...xs)) / 2,
    y: -(Math.min(...ys) + Math.max(...ys)) / 2,
  }
})

const layout = computed(() =>
  props.modules.map((module, index) => {
    const { angle, x, y } = pointAt(index)

    return {
      module,
      // Coordonnées en pourcentage : la spirale suit la taille du conteneur.
      x: 50 + x + offset.value.x,
      y: 50 + y + offset.value.y,
      // Les tuiles grandissent vers l'extérieur : le regard suit la courbe
      // dans le sens de lecture du catalogue.
      scale: 0.86 + index * 0.038,
      // Inclinaison tangentielle, BORNÉE. Sans borne, l'angle cumulé atteint
      // 240° sur la cinquième tuile : le texte devenait illisible pour un
      // gain purement décoratif.
      rotation: Math.max(-6, Math.min(6, (((angle * 180) / Math.PI) % 360) * 0.045)),
    }
  }),
)

/** Tracé de la courbe, échantillonné finement pour rester lisse. */
const spiralPath = computed(() => {
  const points = []
  const last = Math.max(props.modules.length - 1, 0) * TURN

  // Le MÊME décalage que les tuiles : sans lui, la courbe ne passerait plus
  // par elles et l'arrangement paraîtrait accidentel.
  const { x: dx, y: dy } = offset.value

  for (let theta = 0; theta <= last + 0.3; theta += 0.06) {
    const angle = theta - Math.PI / 2
    const radius = R0 + K * theta

    points.push(
      `${(50 + radius * Math.cos(angle) + dx).toFixed(2)},${(50 + radius * Math.sin(angle) + dy).toFixed(2)}`,
    )
  }

  return points.length ? `M${points.join(' L')}` : ''
})

/**
 * Bascule. L'état de départ est capturé AVANT le changement de mode, puis
 * Flip fait glisser chaque tuile vers sa nouvelle position.
 */
async function setMode(next) {
  if (next === mode.value) return

  play(next === 'spiral' ? 'switchOn' : 'switchOff')

  const state =
    prefersReducedMotion() || !root.value
      ? null
      : Flip.getState(root.value.querySelectorAll('[data-tile]'))

  mode.value = next

  try {
    localStorage.setItem(STORAGE_KEY, next)
  } catch {
    /* stockage indisponible : le choix vaut pour la session */
  }

  if (!state) return

  await nextTick()

  Flip.from(state, {
    duration: 0.72,
    ease: 'appEnter',
    // Chaque tuile part avec un léger décalage : le mouvement se lit comme
    // un enroulement, pas comme un basculement en bloc.
    stagger: 0.045,
    absolute: true,
  })
}

onMounted(() => {
  if (prefersReducedMotion() || !root.value) return

  // Seule l'opacité est animée. En mode spirale, chaque tuile porte sa propre
  // échelle et sa propre rotation dans un transform en ligne : animer « scale »
  // ferait réécrire ce transform par GSAP, et la spirale s'aplatirait.
  gsap.fromTo(
    root.value.querySelectorAll('[data-tile]'),
    { opacity: 0 },
    { opacity: 1, duration: 0.6, stagger: 0.07, ease: 'appEnter', overwrite: 'auto' },
  )
})
</script>

<template>
  <section ref="root">
    <header class="mb-5 flex flex-wrap items-center justify-between gap-3">
      <h3 class="text-[0.95rem] font-semibold">vos modules</h3>

      <!-- Bascule : deux boutons plutôt qu'un interrupteur, pour que le mode
           courant se lise sans avoir à l'interpréter. -->
      <div
        class="flex items-center gap-0.5 rounded-pill border border-line bg-panel p-0.5"
        role="group"
        aria-label="Disposition des modules"
      >
        <button
          v-for="option in [
            { value: 'spiral', label: 'spirale' },
            { value: 'list', label: 'liste' },
          ]"
          :key="option.value"
          type="button"
          class="rounded-pill px-3 py-1 text-[0.75rem] transition-colors"
          :class="
            mode === option.value
              ? 'bg-ink text-paper'
              : 'text-ink-2 hover:bg-raised hover:text-ink'
          "
          :aria-pressed="mode === option.value"
          @click="setMode(option.value)"
        >
          {{ option.label }}
        </button>
      </div>
    </header>

    <!-- ============================ SPIRALE ============================ -->
    <div v-if="mode === 'spiral'" class="relative mx-auto aspect-square w-full max-w-3xl">
      <!-- Courbe guide : sans elle, les tuiles paraissent éparpillées plutôt
           qu'arrangées. -->
      <svg
        class="pointer-events-none absolute inset-0 size-full"
        viewBox="0 0 100 100"
        preserveAspectRatio="none"
        aria-hidden="true"
      >
        <path
          :d="spiralPath"
          fill="none"
          stroke="currentColor"
          stroke-width="1"
          stroke-dasharray="4 5"
          class="text-ink-3/50"
          vector-effect="non-scaling-stroke"
        />
      </svg>

      <RouterLink
        v-for="(tile, index) in layout"
        :key="tile.module.id"
        data-tile
        :to="{ name: 'module', params: { slug: tile.module.slug } }"
        class="group absolute w-[22%] max-w-40 rounded-card border border-line bg-panel p-4 transition-colors hover:border-ink-3 hover:bg-raised"
        :style="{
          left: `${tile.x}%`,
          top: `${tile.y}%`,
          transform: `translate(-50%, -50%) scale(${tile.scale}) rotate(${tile.rotation}deg)`,
          zIndex: index + 1,
        }"
      >
        <div class="flex items-start justify-between gap-2">
          <span
            class="flex size-9 items-center justify-center rounded-pill border border-line bg-raised text-ink"
          >
            <AppIcon :name="tile.module.icon" :size="17" />
          </span>
          <span class="label-caps tabular-nums">{{ String(index + 1).padStart(2, '0') }}</span>
        </div>

        <p class="mt-3 text-[0.9rem] font-semibold lowercase">{{ tile.module.name }}</p>
        <p class="mt-1 text-[0.72rem] text-ink-3">
          {{ tile.module.items_count }} élément{{ tile.module.items_count > 1 ? 's' : '' }}
        </p>
      </RouterLink>
    </div>

    <!-- ============================= LISTE ============================= -->
    <div v-else class="flex flex-col gap-2">
      <RouterLink
        v-for="(tile, index) in layout"
        :key="tile.module.id"
        data-tile
        :to="{ name: 'module', params: { slug: tile.module.slug } }"
        class="group flex items-center gap-4 rounded-card border border-line bg-panel p-4 transition-colors hover:border-ink-3 hover:bg-raised"
      >
        <span class="label-caps w-6 shrink-0 tabular-nums">
          {{ String(index + 1).padStart(2, '0') }}
        </span>

        <span
          class="flex size-9 shrink-0 items-center justify-center rounded-pill border border-line bg-raised text-ink"
        >
          <AppIcon :name="tile.module.icon" :size="17" />
        </span>

        <span class="min-w-0 flex-1">
          <span class="block text-[0.9rem] font-semibold lowercase">{{ tile.module.name }}</span>
          <span class="mt-0.5 line-clamp-1 block text-[0.78rem] text-ink-2">
            {{ tile.module.description }}
          </span>
        </span>

        <span class="shrink-0 text-[0.72rem] text-ink-3 tabular-nums">
          {{ tile.module.items_count }}
        </span>

        <AppIcon
          name="arrow-right"
          :size="16"
          class="shrink-0 text-ink-3 transition-transform group-hover:translate-x-0.5"
        />
      </RouterLink>
    </div>
  </section>
</template>
