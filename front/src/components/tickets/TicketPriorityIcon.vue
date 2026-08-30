<script setup>
/**
 * Glyphe de priorité : trois barres de hauteur croissante.
 *
 * Les barres au-delà du niveau restent VISIBLES, en faible opacité. Sans
 * elles, « basse » et « aucune » produiraient des glyphes de largeurs
 * différentes et la colonne cesserait d'être alignée — or c'est justement
 * l'alignement qui permet de comparer une pile de tickets d'un coup d'œil.
 *
 * « Urgente » sort du barreau : un point d'exclamation, parce qu'une
 * quatrième barre ne se distinguerait pas assez de la troisième.
 */
import { computed } from 'vue'

import { priorityOf } from '@/utils/tickets'

const props = defineProps({
  priority: { type: String, required: true },
  size: { type: [Number, String], default: 14 },
})

const meta = computed(() => priorityOf(props.priority))

/** x, y, hauteur — trois barres montantes. */
const BARS = [
  { x: 2, y: 9.5, height: 4.5 },
  { x: 6.5, y: 6.5, height: 7.5 },
  { x: 11, y: 3.5, height: 10.5 },
]

const isUrgent = computed(() => props.priority === 'urgent')
</script>

<template>
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 16 16"
    fill="none"
    :class="meta.tone"
    role="img"
    :aria-label="`priorité ${meta.label}`"
  >
    <template v-if="isUrgent">
      <rect x="6.4" y="2" width="3.2" height="8" rx="1.2" fill="currentColor" />
      <rect x="6.4" y="11.6" width="3.2" height="2.8" rx="1.2" fill="currentColor" />
    </template>

    <template v-else>
      <rect
        v-for="(bar, index) in BARS"
        :key="bar.x"
        :x="bar.x"
        :y="bar.y"
        width="3"
        :height="bar.height"
        rx="1"
        fill="currentColor"
        :opacity="index < meta.bars ? 1 : 0.22"
      />
    </template>
  </svg>
</template>
