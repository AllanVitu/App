<script setup>
/**
 * Un groupe d'erreurs, en carte de tableau.
 *
 * L'unité est le GROUPE, pas l'occurrence : mille fois la même exception est
 * un seul problème à corriger. D'où le compteur d'occurrences, mis en avant —
 * « 1 » et « 4 128 » n'appellent pas la même réaction, et c'est souvent la
 * seule chose qu'on regarde avant de décider par quoi commencer.
 *
 * La gravité passe par une pastille ET un mot. Jamais par la couleur seule :
 * « fatale » et « erreur » se distinguent en brique et en ocre, deux teintes
 * que la vision daltonienne rapproche.
 */
import { computed } from 'vue'

import PresenceMark from '@/components/ui/PresenceMark.vue'
import { formatRelative } from '@/utils/format'

const props = defineProps({
  group: { type: Object, required: true },
  /** Qui a cette erreur ouverte en ce moment (cf. ui/PresenceMark). */
  watchers: { type: Array, default: () => [] },
})

const LEVELS = {
  fatal: { label: 'fatale', tone: 'text-brick', dot: 'bg-brick' },
  error: { label: 'erreur', tone: 'text-ochre', dot: 'bg-ochre' },
  warning: { label: 'avertissement', tone: 'text-ink-3', dot: 'bg-ink-3' },
}

const level = computed(() => LEVELS[props.group.level] ?? LEVELS.error)
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <div class="flex items-center gap-2">
      <span class="size-2 shrink-0 rounded-pill" :class="level.dot" aria-hidden="true" />
      <span class="text-[0.7rem]" :class="level.tone">{{ level.label }}</span>

      <span class="ml-auto text-[0.78rem] font-semibold tabular-nums">
        {{ group.occurrences }}
      </span>
    </div>

    <p
      class="line-clamp-2 text-[0.82rem] leading-snug"
      :class="group.status === 'unresolved' ? 'text-ink' : 'text-ink-3'"
    >
      {{ group.title }}
    </p>

    <!-- L'endroit du code : c'est par lui qu'on reconnaît une erreur déjà vue,
         plus sûrement que par son message. -->
    <p v-if="group.culprit" class="truncate font-mono text-[0.68rem] text-ink-3">
      {{ group.culprit }}
    </p>

    <p class="text-[0.68rem] text-ink-3">{{ formatRelative(group.last_seen_at) }}</p>

    <PresenceMark :watchers="watchers" />
  </div>
</template>
