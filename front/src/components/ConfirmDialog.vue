<script setup>
/** Confirmation avant une action irréversible (suppression, désactivation). */
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'

defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: 'Confirmer' },
  message: { type: String, default: 'Cette action est irréversible.' },
  confirmLabel: { type: String, default: 'Confirmer' },
  loading: { type: Boolean, default: false },
})

const emit = defineEmits(['confirm', 'close'])
</script>

<template>
  <BaseModal :open="open" :title="title" size="sm" @close="emit('close')">
    <p class="text-[0.82rem] leading-relaxed text-ink-2">{{ message }}</p>

    <!-- Contenu additionnel : saisie de confirmation, avertissement... -->
    <div v-if="$slots.default" class="mt-4">
      <slot />
    </div>

    <template #footer>
      <BaseButton variant="secondary" :disabled="loading" @click="emit('close')">
        Annuler
      </BaseButton>
      <BaseButton variant="danger" :loading="loading" @click="emit('confirm')">
        {{ confirmLabel }}
      </BaseButton>
    </template>
  </BaseModal>
</template>
