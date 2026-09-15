<script setup>
/**
 * Un déploiement, en carte de tableau.
 *
 * L'environnement est la première chose lue, et c'est délibéré : « production »
 * et « prévisualisation » ne se regardent pas avec la même attention. Il est
 * marqué par un mot ET une graisse — jamais par la couleur seule, qui est déjà
 * prise par le statut.
 *
 * L'empreinte du commit reste en chasse fixe : c'est une chaîne qu'on compare
 * caractère à caractère avec un terminal ouvert à côté.
 */
import { computed } from 'vue'

import PresenceMark from '@/components/ui/PresenceMark.vue'
import { formatRelative } from '@/utils/format'

const props = defineProps({
  deployment: { type: Object, required: true },
  /** Durée lisible, formatée par la vue — la règle lui appartient. */
  duration: { type: String, default: '—' },
  /** Qui a ce déploiement ouvert en ce moment (cf. ui/PresenceMark). */
  watchers: { type: Array, default: () => [] },
})

const production = computed(() => props.deployment.environment === 'production')
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <div class="flex items-center gap-2">
      <span class="text-[0.68rem]" :class="production ? 'font-semibold text-ink' : 'text-ink-3'">
        {{ production ? 'production' : 'prévisu.' }}
      </span>

      <span class="ml-auto text-[0.68rem] tabular-nums text-ink-3">{{ duration }}</span>
    </div>

    <p class="line-clamp-2 text-[0.82rem] leading-snug">
      {{ deployment.commit_message ?? 'Sans message' }}
    </p>

    <p class="truncate font-mono text-[0.68rem] text-ink-3">
      {{ deployment.branch }}@{{ deployment.commit_sha.slice(0, 7) }}
    </p>

    <p class="text-[0.68rem] text-ink-3">{{ formatRelative(deployment.created_at) }}</p>

    <PresenceMark :watchers="watchers" />
  </div>
</template>
