<script setup>
/**
 * Quelqu'un, dans un carré à filet : sa photo s'il en a une, ses initiales
 * sinon.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  LE MÊME CALCUL ÉTAIT ÉCRIT À QUATRE ENDROITS                       │
 * │                                                                     │
 * │  Barre latérale, sélecteur d'espace, écran Équipe — et l'assignation │
 * │  allait en ajouter deux de plus, sur la carte et sur la ligne d'un   │
 * │  ticket. Six copies de « découper, prendre deux mots, majuscule »,   │
 * │  qui divergeraient à la première correction.                        │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * LA PHOTO SE POSE ICI, ET NULLE PART AILLEURS — c'était la promesse du jour
 * où ce composant a remplacé ses copies.
 *
 * Les initiales restent le socle. Elles s'affichent tant que l'image n'est
 * pas arrivée, et reviennent si elle ne vient pas : adresse expirée, fichier
 * retiré, réseau coupé. La photo se pose PAR-DESSUS et n'apparaît qu'une fois
 * chargée — jamais de trou à la place d'un visage, jamais de saut.
 */
import { computed, ref, watch } from 'vue'

import { assetUrl } from '@/utils/assets'

const props = defineProps({
  /** Nom complet. Vide ou absent donne le tiret de « personne ». */
  name: { type: String, default: '' },
  /** Adresse signée de la photo, telle que l'API la renvoie. Null : les initiales. */
  src: { type: String, default: null },
  size: {
    type: String,
    default: 'md',
    validator: (v) => ['xs', 'sm', 'md', 'lg'].includes(v),
  },
  /** Repère de l'espace plutôt que d'une personne : arrondi, jamais carré. */
  muted: { type: Boolean, default: false },
})

const SIZES = {
  xs: 'size-5 text-[0.56rem]',
  sm: 'size-7 text-[0.66rem]',
  md: 'size-8 text-[0.68rem]',
  lg: 'size-16 text-xl',
}

/**
 * Deux initiales au plus. « Marie-Claire Dupont » donne MD et non MCD : au
 * delà de deux lettres, le carré cesse d'être lisible à cette taille.
 */
const initials = computed(() => {
  const parts = props.name.split(/\s+/).filter(Boolean)

  if (parts.length === 0) return '—'

  return parts
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join('')
})

const adresse = computed(() => assetUrl(props.src))
const chargee = ref(false)
const echec = ref(false)

// Une nouvelle adresse mérite un nouvel essai : l'échec de l'ancienne — une
// signature expirée, typiquement — ne dit rien d'elle.
watch(adresse, () => {
  chargee.value = false
  echec.value = false
})
</script>

<template>
  <span
    class="relative inline-flex shrink-0 items-center justify-center overflow-hidden border font-semibold"
    :class="[
      SIZES[size],
      muted ? 'border-line bg-panel text-ink-3' : 'border-line bg-raised text-ink',
    ]"
    :title="name || 'Personne'"
  >
    <!-- Le titre porte le nom entier ; les initiales seules seraient
         indéchiffrables au lecteur d'écran. -->
    <span aria-hidden="true">{{ initials }}</span>

    <img
      v-if="adresse && !echec"
      :src="adresse"
      alt=""
      decoding="async"
      referrerpolicy="no-referrer"
      class="absolute inset-0 size-full object-cover"
      :class="chargee ? 'opacity-100' : 'opacity-0'"
      @load="chargee = true"
      @error="echec = true"
    />

    <span class="sr-only">{{ name || 'Personne' }}</span>
  </span>
</template>
