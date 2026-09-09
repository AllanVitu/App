<script setup>
/**
 * Coupure du son, toujours à portée.
 *
 * Un son qu'on ne peut pas éteindre est une nuisance. Le bouton reste
 * visible en permanence dans la barre supérieure, et l'état est mémorisé.
 *
 * L'icône est un haut-parleur dont les ondes se rétractent : la barre
 * oblique classique dit « interdit », pas « silencieux ».
 */
import { computed } from 'vue'

import { useUiStore } from '@/stores/ui'

const ui = useUiStore()

const label = computed(() => (ui.soundOn ? 'Couper le son' : 'Activer le son'))
</script>

<template>
  <button
    type="button"
    class="rounded-field p-2 text-ink-2 transition-colors hover:bg-raised hover:text-ink"
    :aria-label="label"
    :aria-pressed="ui.soundOn"
    :title="label"
    data-sound-click="switchOn"
    @click="ui.toggleSound()"
  >
    <svg
      width="17"
      height="17"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="1.6"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <path d="M11 5 6.5 9H3v6h3.5L11 19z" />

      <!-- Ondes : présentes uniquement quand le son est actif -->
      <template v-if="ui.soundOn">
        <path d="M15 9.5a3.5 3.5 0 0 1 0 5" />
        <path d="M17.8 6.8a7.5 7.5 0 0 1 0 10.4" />
      </template>

      <!-- Silence : deux traits courts, sans interdiction -->
      <template v-else>
        <path d="M16 10.5v3" opacity="0.5" />
        <path d="M19 11.5v1" opacity="0.35" />
      </template>
    </svg>
  </button>
</template>
