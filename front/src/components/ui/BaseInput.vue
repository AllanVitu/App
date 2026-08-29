<script setup>
/**
 * Champ de formulaire : libellé, saisie, message d'erreur et aide.
 *
 * L'erreur affichée provient directement du champ `errors` renvoyé par l'API
 * en 422 : la validation serveur reste la référence, le front n'en duplique
 * pas les règles.
 */
import { computed, useId } from 'vue'

const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  label: { type: String, default: '' },
  type: { type: String, default: 'text' },
  placeholder: { type: String, default: '' },
  error: { type: String, default: '' },
  hint: { type: String, default: '' },
  required: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  autocomplete: { type: String, default: 'off' },
  textarea: { type: Boolean, default: false },
  rows: { type: Number, default: 3 },
})

defineEmits(['update:modelValue'])

const id = useId()
const describedBy = computed(() => {
  if (props.error) return `${id}-error`

  return props.hint ? `${id}-hint` : undefined
})
</script>

<template>
  <div>
    <label v-if="label" :for="id" class="label-field">
      {{ label }}
      <span v-if="required" class="text-brick" aria-hidden="true">*</span>
    </label>

    <component
      :is="textarea ? 'textarea' : 'input'"
      :id="id"
      :value="modelValue"
      :type="textarea ? undefined : type"
      :rows="textarea ? rows : undefined"
      :placeholder="placeholder"
      :required="required"
      :disabled="disabled"
      :autocomplete="autocomplete"
      :aria-invalid="Boolean(error)"
      :aria-describedby="describedBy"
      class="input-field"
      :class="error ? 'border-brick focus:border-brick' : ''"
      @input="$emit('update:modelValue', $event.target.value)"
    />

    <p v-if="error" :id="`${id}-error`" class="mt-1.5 text-[0.72rem] text-brick">
      {{ error }}
    </p>
    <p v-else-if="hint" :id="`${id}-hint`" class="mt-1.5 text-[0.72rem] text-ink-3">
      {{ hint }}
    </p>
  </div>
</template>
