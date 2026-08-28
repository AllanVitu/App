<script setup>
/**
 * Formulaire de création / modification d'un élément de module.
 *
 * Le même composant sert aux deux cas : la présence de `item` bascule en
 * édition. Les erreurs affichées sont celles renvoyées par l'API (422).
 */
import { reactive, ref, watch } from 'vue'

import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { toDateInput } from '@/utils/format'

const props = defineProps({
  open: { type: Boolean, default: false },
  item: { type: Object, default: null },
  submitting: { type: Boolean, default: false },
  errors: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['submit', 'close'])

const STATUSES = [
  { value: 'draft', label: 'Brouillon' },
  { value: 'active', label: 'Actif' },
  { value: 'archived', label: 'Archivé' },
]

const PRIORITIES = [
  { value: '', label: 'Non définie' },
  { value: 'low', label: 'Basse' },
  { value: 'medium', label: 'Moyenne' },
  { value: 'high', label: 'Haute' },
]

const form = reactive({
  title: '',
  description: '',
  status: 'draft',
  due_date: '',
  priority: '',
})

const isEditing = ref(false)

/**
 * Réinitialise le formulaire à chaque ouverture : sans cela, la modale
 * rouvrirait avec les valeurs de l'élément précédent.
 */
watch(
  () => props.open,
  (open) => {
    if (!open) return

    isEditing.value = Boolean(props.item)

    form.title = props.item?.title ?? ''
    form.description = props.item?.description ?? ''
    form.status = props.item?.status ?? 'draft'
    form.due_date = toDateInput(props.item?.due_date)
    // « priority » vit dans la charge utile JSONB : un champ métier libre,
    // ajouté sans migration de schéma.
    form.priority = props.item?.data?.priority ?? ''
  },
  { immediate: true },
)

function submit() {
  emit('submit', {
    title: form.title,
    description: form.description || null,
    status: form.status,
    due_date: form.due_date || null,
    data: form.priority ? { priority: form.priority } : {},
  })
}
</script>

<template>
  <BaseModal
    :open="open"
    :title="isEditing ? 'Modifier l\'élément' : 'Nouvel élément'"
    @close="emit('close')"
  >
    <form id="item-form" class="space-y-4" novalidate @submit.prevent="submit">
      <BaseInput
        v-model="form.title"
        label="Titre"
        placeholder="Intitulé de l'élément"
        required
        :error="errors.title"
      />

      <BaseInput
        v-model="form.description"
        label="Description"
        placeholder="Quelques précisions (facultatif)"
        textarea
        :rows="3"
        :error="errors.description"
      />

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label for="item-status" class="label-field">Statut</label>
          <select id="item-status" v-model="form.status" class="input-field">
            <option v-for="status in STATUSES" :key="status.value" :value="status.value">
              {{ status.label }}
            </option>
          </select>
          <p v-if="errors.status" class="mt-1.5 text-xs text-red-600">{{ errors.status }}</p>
        </div>

        <div>
          <label for="item-priority" class="label-field">Priorité</label>
          <select id="item-priority" v-model="form.priority" class="input-field">
            <option v-for="priority in PRIORITIES" :key="priority.value" :value="priority.value">
              {{ priority.label }}
            </option>
          </select>
        </div>
      </div>

      <BaseInput
        v-model="form.due_date"
        label="Échéance"
        type="date"
        :error="errors.due_date"
      />
    </form>

    <template #footer>
      <BaseButton variant="secondary" :disabled="submitting" @click="emit('close')">
        Annuler
      </BaseButton>
      <BaseButton type="submit" form="item-form" :loading="submitting">
        {{ isEditing ? 'Enregistrer' : 'Créer' }}
      </BaseButton>
    </template>
  </BaseModal>
</template>
