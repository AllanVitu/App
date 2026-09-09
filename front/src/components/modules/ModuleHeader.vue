<script setup>
/**
 * En-tête commun aux écrans de module.
 *
 * Les cinq modules ont des métiers différents mais la même anatomie : un nom,
 * quelques chiffres qui disent où l'on en est, une barre de filtres. Extraire
 * cette structure garantit qu'ils se ressemblent — et qu'on n'a pas à décider
 * cinq fois de la taille d'un titre.
 *
 * Les chiffres portent un « ton » plutôt qu'une couleur : c'est l'écran qui
 * sait ce qui est alarmant chez lui, pas ce composant.
 */
import { computed } from 'vue'

import { moduleLine } from '@/utils/modules'

const props = defineProps({
  title: { type: String, required: true },
  /** [{ label, value, tone: 'alert' | 'good' | 'neutral' }] */
  stats: { type: Array, default: () => [] },
  /**
   * Le slug du module, pour sa couleur de ligne.
   *
   * Il est demandé plutôt que déduit du titre : le titre est un libellé
   * d'affichage, traduisible et modifiable, tandis que le slug est la clé.
   * Les faire coïncider serait vrai aujourd'hui et faux au premier renommage.
   */
  slug: { type: String, default: '' },
})

const ligne = computed(() => moduleLine(props.slug))

const TONES = {
  alert: 'text-brick',
  good: 'text-moss',
  neutral: 'text-ink-2',
}
</script>

<template>
  <header class="shrink-0">
    <div class="flex flex-wrap items-end justify-between gap-4">
      <!-- LA BARRE DE LIGNE, en grand.
           La même que dans le menu, à la même épaisseur, mais haute comme le
           titre : c'est la constance de ce trait d'un écran à l'autre qui en
           fait un repère et non une décoration. Elle répond à une seule
           question — sur quelle ligne suis-je ? -->
      <div class="flex items-stretch gap-3">
        <span class="ligne" :class="ligne" aria-hidden="true" />

        <div>
          <p class="label-caps">module</p>
          <h2 class="mt-0.5 text-2xl font-extrabold lowercase tracking-[-0.03em]">{{ title }}</h2>
        </div>
      </div>

      <!-- Les chiffres se lisent en une ligne : la valeur en gras, son
           libellé en retrait. L'inverse obligerait à chercher le nombre. -->
      <div
        v-if="stats.length"
        class="flex flex-wrap items-center gap-4 text-[0.76rem] tabular-nums"
      >
        <span v-for="stat in stats" :key="stat.label" :class="TONES[stat.tone] ?? TONES.neutral">
          <span class="font-semibold">{{ stat.value }}</span>
          {{ stat.label }}
        </span>
      </div>

      <!-- Emplacement libre : une courbe, un bouton, selon le module. -->
      <slot name="aside" />
    </div>

    <div v-if="$slots.filters" class="mt-4 flex flex-wrap items-center gap-2">
      <slot name="filters" />
    </div>
  </header>
</template>
