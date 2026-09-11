<script setup>
/**
 * « Quelqu'un est dessus, en ce moment. »
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  UN CONFLIT QU'ON PEUT NE PAS PROVOQUER VAUT MIEUX QU'UN CONFLIT    │
 * │  BIEN ARBITRÉ                                                       │
 * │                                                                     │
 * │  L'arbitrage de conflit fonctionne, et il garde le texte de chacun. │
 * │  Il n'en reste pas moins une interruption : deux personnes qui      │
 * │  écrivent le même champ perdent du temps, même bien départagées.    │
 * │                                                                     │
 * │  Ce marqueur se lit AVANT d'ouvrir. C'est sa seule raison d'être.   │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * EN OCRE, la couleur d'un avertissement — jamais en brique. Il n'y a rien
 * de cassé, seulement une raison d'attendre ou de prévenir.
 */
import { computed } from 'vue'

const props = defineProps({
  /** Les noms de ceux qui regardent ce sujet. */
  watchers: { type: Array, default: () => [] },
})

/**
 * « y est » ou « y sont » : l'accord se fait, parce qu'une interface qui
 * écrit « Bob et Alice y est » perd la confiance qu'elle demande ailleurs.
 */
const phrase = computed(() =>
  props.watchers.length > 1 ? `${props.watchers.join(', ')} y sont` : `${props.watchers[0]} y est`,
)
</script>

<template>
  <p
    v-if="watchers.length"
    class="flex items-center gap-1 text-[0.66rem] text-ochre"
    aria-live="polite"
  >
    <span class="size-1.5 shrink-0 rounded-pill bg-ochre" aria-hidden="true" />
    {{ phrase }}
  </p>
</template>
