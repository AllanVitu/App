<script setup>
/**
 * Pastille de statut.
 *
 * Les trois statuts correspondent au type PostgreSQL item_status
 * (draft / active / archived) : le libellé français est traduit ici.
 */
import { computed } from 'vue'

const props = defineProps({
  status: { type: String, default: 'draft' },
})

const STATUSES = {
  draft: {
    label: 'Brouillon',
    classes: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
  },
  active: {
    label: 'Actif',
    classes: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
  },
  archived: {
    label: 'Archivé',
    classes: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
  },
}

const current = computed(() => STATUSES[props.status] ?? STATUSES.draft)
</script>

<template>
  <span
    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
    :class="current.classes"
  >
    {{ current.label }}
  </span>
</template>
