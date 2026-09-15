<script setup>
/**
 * Pastille de statut.
 *
 * Les trois statuts correspondent au type PostgreSQL item_status
 * (draft / active / archived). Un carré de couleur porte le sens, le texte
 * reste en encre : sur un fond papier, un aplat coloré serait trop bruyant
 * répété douze fois dans une liste.
 */
import { computed } from 'vue'

const props = defineProps({
  status: { type: String, default: 'draft' },
})

const STATUSES = {
  draft: { label: 'brouillon', dot: 'bg-ink-3', text: 'text-ink-2' },
  active: { label: 'actif', dot: 'bg-moss', text: 'text-moss' },
  archived: { label: 'archivé', dot: 'bg-ochre', text: 'text-ochre' },
}

const current = computed(() => STATUSES[props.status] ?? STATUSES.draft)
</script>

<template>
  <span class="chip border-line" :class="current.text">
    <span class="size-1.5 shrink-0" :class="current.dot" aria-hidden="true" />
    {{ current.label }}
  </span>
</template>
