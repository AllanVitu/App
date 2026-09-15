<script setup>
/**
 * Champ de recherche d'une barre d'outils : la loupe, le champ, et le
 * décalage qui les empêche de se chevaucher.
 *
 * Six écrans répétaient ces neuf lignes — même icône, même positionnement
 * absolu, même `pl-9` calculé pour lui laisser la place. Corriger le
 * contraste de la loupe ou la largeur minimale demandait six modifications ;
 * il suffisait d'en manquer une pour qu'un module dérive.
 *
 * DEUX TAILLES, ET CE N'EST PAS DE LA DÉRIVE. Les cinq modules ont une barre
 * d'outils compacte, accordée à leurs pastilles. L'écran générique, lui, pose
 * ce champ à côté de deux listes déroulantes de taille normale : l'y mettre
 * compact le ferait paraître rabougri entre ses voisins immédiats. La taille
 * suit le voisinage, elle ne suit pas l'écran.
 */
import { ref } from 'vue'

import AppIcon from '@/components/AppIcon.vue'

const props = defineProps({
  /** Ce que le champ accepte, dit dans les termes du module. */
  placeholder: { type: String, required: true },

  /** Nom accessible : le champ n'a pas d'étiquette visible. */
  label: { type: String, required: true },

  /** « compact » dans une barre de module, « regular » à côté d'un select. */
  size: {
    type: String,
    default: 'compact',
    validator: (value) => ['compact', 'regular'].includes(value),
  },
})

const value = defineModel({ type: String, default: '' })

const input = ref(null)

/**
 * Le module Tickets amène le curseur ici avec la touche « / », puis
 * sélectionne le contenu pour qu'une nouvelle frappe remplace l'ancienne
 * recherche au lieu de s'y ajouter.
 */
defineExpose({
  focus: () => input.value?.focus(),
  select: () => input.value?.select(),
})
</script>

<template>
  <div class="relative flex-1" :class="props.size === 'compact' ? 'min-w-52' : 'min-w-56'">
    <AppIcon
      name="search"
      :size="props.size === 'compact' ? 14 : 16"
      class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-3"
    />

    <input
      ref="input"
      v-model="value"
      type="search"
      class="input-field pl-9"
      :class="props.size === 'compact' ? 'py-2 text-[0.82rem]' : ''"
      :placeholder="props.placeholder"
      :aria-label="props.label"
    />
  </div>
</template>
