<script setup>
/**
 * Un chiffre de tête, et sa comparaison à la période précédente.
 *
 * LA COMPARAISON EST FACULTATIVE, ET C'EST LE POINT DÉLICAT. Un FLUX se
 * compare — sept déploiements cette semaine contre quatre la précédente, la
 * phrase a un sens. Un ÉTAT ne se compare pas : « 12 tickets ouverts, +3 »
 * laisse croire qu'il s'en est créé trois, alors que le nombre peut avoir
 * monté parce qu'on en a fermé moins. Les tiles d'état passent donc
 * « previous » à null, et n'affichent rien.
 *
 * LE SENS DE LA VARIATION N'EST PAS UNIVERSEL non plus. Plus d'erreurs est
 * mauvais, plus de déploiements n'est ni bon ni mauvais, un meilleur taux de
 * réussite est bon. D'où « goodWhen », qui dit dans quel sens penche le vert
 * — ou qu'aucun sens ne penche.
 *
 * La couleur ne porte JAMAIS l'information seule : le signe (+/−) et la
 * flèche disent la même chose, et se lisent en niveaux de gris comme en
 * vision daltonienne.
 */
import { computed } from 'vue'

const props = defineProps({
  label: { type: String, required: true },
  value: { type: [Number, null], required: true },
  /** Période précédente. Null pour un état, qui ne se compare pas. */
  previous: { type: [Number, null], default: null },
  /** Unité collée au chiffre : « % ». */
  suffix: { type: String, default: '' },
  /** Unité de la VARIATION quand elle diffère : des points, pas des pourcents. */
  deltaSuffix: { type: String, default: '' },
  /** 'up' | 'down' | null — sens dans lequel la variation est une bonne nouvelle. */
  goodWhen: { type: String, default: null },
})

const delta = computed(() => {
  if (props.value === null || props.previous === null) return null

  return props.value - props.previous
})

const tone = computed(() => {
  if (delta.value === null || delta.value === 0 || !props.goodWhen) return 'text-ink-3'

  const improving = props.goodWhen === 'up' ? delta.value > 0 : delta.value < 0

  return improving ? 'text-moss' : 'text-brick'
})

/** Signe explicite : « 3 » ne dirait pas s'il s'agit d'une hausse. */
const deltaLabel = computed(() => {
  if (delta.value === null) return ''
  if (delta.value === 0) return 'stable'

  const arrow = delta.value > 0 ? '↑' : '↓'

  return `${arrow} ${delta.value > 0 ? '+' : '−'}${Math.abs(delta.value)}${props.deltaSuffix}`
})
</script>

<template>
  <div class="card px-4 py-3">
    <p class="label-caps truncate">{{ label }}</p>

    <p class="mt-1 flex items-baseline gap-1">
      <!-- Un état inconnu se dit, il ne s'invente pas : « 100 % de réussite »
           sur zéro déploiement serait une phrase vide. -->
      <span class="text-[1.6rem] font-semibold leading-none tabular-nums">
        {{ value === null ? '—' : value }}
      </span>
      <span v-if="suffix && value !== null" class="text-[0.9rem] text-ink-2">{{ suffix }}</span>
    </p>

    <p class="mt-1.5 h-4 text-[0.72rem] tabular-nums" :class="tone">
      <template v-if="delta !== null">
        {{ deltaLabel }}
        <span class="text-ink-3">/ 7 j préc.</span>
      </template>
    </p>
  </div>
</template>
