<script setup>
/**
 * Les initiales de quelqu'un, dans un carré à filet.
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
 * PAS D'IMAGE ICI, même quand le compte a un « avatar_url ». Les initiales
 * sont toujours disponibles, ne chargent rien, et ne laissent jamais un trou
 * pendant que le réseau répond. Le jour où les photos comptent vraiment,
 * elles se poseront dans ce composant et nulle part ailleurs.
 */
import { computed } from 'vue'

const props = defineProps({
  /** Nom complet. Vide ou absent donne le tiret de « personne ». */
  name: { type: String, default: '' },
  size: { type: String, default: 'md', validator: (v) => ['xs', 'sm', 'md'].includes(v) },
  /** Repère de l'espace plutôt que d'une personne : arrondi, jamais carré. */
  muted: { type: Boolean, default: false },
})

const SIZES = {
  xs: 'size-5 text-[0.56rem]',
  sm: 'size-7 text-[0.66rem]',
  md: 'size-8 text-[0.68rem]',
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
</script>

<template>
  <span
    class="inline-flex shrink-0 items-center justify-center border font-semibold"
    :class="[
      SIZES[size],
      muted ? 'border-line bg-panel text-ink-3' : 'border-line bg-raised text-ink',
    ]"
    :title="name || 'Personne'"
  >
    <!-- Le titre porte le nom entier ; les initiales seules seraient
         indéchiffrables au lecteur d'écran. -->
    <span aria-hidden="true">{{ initials }}</span>
    <span class="sr-only">{{ name || 'Personne' }}</span>
  </span>
</template>
