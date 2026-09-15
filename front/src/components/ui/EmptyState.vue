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
      class="mb-4 flex size-11 items-center justify-center border border-line bg-raised text-ink-3"
    >
      <AppIcon :name="icon" :size="24" />
    </div>

    <h3 class="text-[0.85rem] font-semibold">{{ title }}</h3>
    <p v-if="description" class="mt-1.5 max-w-sm text-[0.8rem] leading-relaxed text-ink-2">
      {{ description }}
    </p>

    <div v-if="$slots.default" class="mt-5">
      <slot />
    </div>
  </div>
</template>
