<script setup>
/**
 * Bouton applicatif.
 *
 * Rend un <button> ou un <RouterLink> selon la présence de `to`, ce qui évite
 * de dupliquer les styles entre actions et liens de navigation.
 *
 * Direction visuelle : rectangle à filet, angles vifs. L'action principale
 * inverse l'encre et le papier plutôt que d'introduire une couleur — la
 * couleur reste réservée au sens (statut, danger).
 */
import { computed } from 'vue'

import BaseSpinner from './BaseSpinner.vue'

const props = defineProps({
  variant: {
    type: String,
    default: 'primary',
    validator: (v) => ['primary', 'secondary', 'ghost', 'danger'].includes(v),
  },
  size: { type: String, default: 'md', validator: (v) => ['sm', 'md', 'lg'].includes(v) },
  type: { type: String, default: 'button' },
  to: { type: [String, Object], default: null },
  loading: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  block: { type: Boolean, default: false },
  /** Préfixe « > », emprunté à l'invite de commande. */
  prompt: { type: Boolean, default: false },
})

const VARIANTS = {
  primary:
    'border border-ink bg-ink text-paper hover:bg-ink-2 hover:border-ink-2 disabled:hover:bg-ink',
  secondary: 'border border-line bg-panel text-ink hover:border-ink hover:bg-raised',
  ghost: 'border border-transparent text-ink-2 hover:border-line hover:text-ink hover:bg-panel',
  danger: 'border border-brick bg-brick text-paper hover:opacity-90',
}

const SIZES = {
  sm: 'gap-1.5 px-3 py-1 text-[0.78rem]',
  md: 'gap-2 px-4 py-2 text-[0.85rem]',
  lg: 'gap-2 px-5 py-2.5 text-[0.9rem]',
}

const classes = computed(() => [
  'inline-flex items-center justify-center rounded-field font-medium transition-colors',
  'disabled:cursor-not-allowed disabled:opacity-50',
  VARIANTS[props.variant],
  SIZES[props.size],
  props.block ? 'w-full' : '',
])

// Un bouton en cours de chargement ne doit pas pouvoir être resoumis.
const isDisabled = computed(() => props.disabled || props.loading)
</script>

<template>
  <RouterLink v-if="to" :to="to" :class="classes">
    <span v-if="prompt" aria-hidden="true" class="text-ink-3">&gt;</span>
    <slot />
  </RouterLink>

  <button v-else :type="type" :disabled="isDisabled" :class="classes">
    <BaseSpinner v-if="loading" class="size-3.5" />
    <span v-else-if="prompt" aria-hidden="true" class="opacity-60">&gt;</span>
    <slot />
  </button>
</template>
