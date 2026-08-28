<script setup>
/** Écran « aucune donnée » : évite une zone vide sans explication. */
import AppIcon from '@/components/AppIcon.vue'

defineProps({
  icon: { type: String, default: 'inbox' },
  title: { type: String, required: true },
  description: { type: String, default: '' },
})
</script>

<template>
  <div class="flex flex-col items-center justify-center px-6 py-14 text-center">
    <div
      class="mb-4 flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500"
    >
      <AppIcon :name="icon" :size="24" />
    </div>

    <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ title }}</h3>
    <p v-if="description" class="mt-1 max-w-sm text-sm text-slate-500">{{ description }}</p>

    <div v-if="$slots.default" class="mt-5">
      <slot />
    </div>
  </div>
</template>
