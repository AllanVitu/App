<script setup>
/**
 * Avertit que la liste affichée est INCOMPLÈTE — et offre d'aller chercher
 * la suite.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  LE DÉFAUT QUE CE COMPOSANT CORRIGE                                 │
 * │                                                                     │
 * │  Chaque module charge sa liste d'un bloc, avec un plafond : 500     │
 * │  tickets, 200 déploiements, 200 groupes d'erreurs, 200 fichiers.    │
 * │  Le plafond est une bonne décision — il garde le filtrage local et  │
 * │  instantané, ce qui est le principal atout de ces écrans.           │
 * │                                                                     │
 * │  Ce qui n'allait pas, c'est qu'il était SILENCIEUX. Au-delà, des    │
 * │  lignes disparaissaient pendant que l'en-tête continuait d'afficher │
 * │  le vrai total, lu côté serveur. L'écran se contredisait lui-même,  │
 * │  et la moitié qu'on croyait était la fausse : on cherchait un       │
 * │  ticket qui existe, sans le trouver, sans rien pour le comprendre.  │
 * │                                                                     │
 * │  Une interface a le droit de ne pas tout montrer. Elle n'a pas le   │
 * │  droit de laisser croire qu'elle montre tout — ni de laisser sans   │
 * │  recours celui qui cherche ce qu'elle a coupé.                      │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Le compte total vient du SERVEUR (`meta.total`), qui voit au-delà du
 * plafond ; le compte affiché est celui des lignes réellement reçues. Les
 * comparer est le seul moyen fiable de savoir qu'on a coupé.
 *
 * L'avertissement se pose SOUS les filtres et AU-DESSUS de la liste : c'est
 * là qu'on regarde quand on ne trouve pas ce qu'on cherche.
 */
import { computed } from 'vue'

import AppIcon from '@/components/AppIcon.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'

const props = defineProps({
  /** Nombre de lignes réellement reçues et affichables. */
  loaded: { type: Number, required: true },
  /** Total côté serveur, filtres compris. Null tant qu'il n'est pas connu. */
  total: { type: [Number, null], default: null },
  /** Nom de ce qu'on compte, au pluriel : « tickets », « déploiements ». */
  unit: { type: String, required: true },
  /** Ce qui permettrait d'en voir davantage, formulé pour ce module. */
  hint: { type: String, default: 'Affinez la recherche ou les filtres pour atteindre le reste.' },
  /** Un chargement de la suite est en cours. */
  loading: { type: Boolean, default: false },
})

const emit = defineEmits(['more'])

const hidden = computed(() => {
  if (props.total === null) return 0

  return Math.max(0, props.total - props.loaded)
})
</script>

<template>
  <div
    v-if="hidden > 0"
    class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-field border border-ochre/40 bg-ochre-bg px-3 py-2 text-[0.76rem] text-ochre"
    role="status"
  >
    <AppIcon name="info" :size="14" class="shrink-0" />

    <span class="min-w-0 flex-1">
      <strong class="font-semibold tabular-nums">{{ hidden }}</strong>
      {{ unit }} ne sont pas affichés : cet écran en charge {{ loaded }} au plus.
      {{ hint }}
    </span>

    <!-- Le recours, à côté du constat. Un avertissement sans issue ne fait
         que nommer le problème. -->
    <button
      type="button"
      class="chip shrink-0 border-ochre/50 text-ochre transition-colors hover:border-ochre hover:bg-ochre/10 disabled:opacity-60"
      :disabled="loading"
      @click="emit('more')"
    >
      <BaseSpinner v-if="loading" class="size-3" />
      charger la suite
    </button>
  </div>
</template>
