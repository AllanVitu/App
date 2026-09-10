<script setup>
/**
 * Un ticket, en carte de tableau.
 *
 * Le pendant de TicketRow, qui reste la forme dense en liste. Les deux
 * existent parce qu'ils ne répondent pas à la même question : la LIGNE sert à
 * balayer un grand nombre de tickets en cherchant une urgence, la CARTE à
 * voir où en est le travail par statut.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  POURQUOI LA PASTILLE DE STATUT RESTE SUR LA CARTE                  │
 * │                                                                     │
 * │  Elle paraît redondante : la colonne dit déjà le statut. Elle est   │
 * │  gardée pour deux raisons.                                          │
 * │                                                                     │
 * │  1. PENDANT UN GLISSEMENT, la carte quitte sa colonne et flotte     │
 * │     seule : la position ne dit plus rien.                           │
 * │  2. AU LECTEUR D'ÉCRAN, une « option » est annoncée seule. Faire    │
 * │     porter le statut par la seule position visuelle le rendrait     │
 * │     inaudible.                                                      │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * L'ordre de lecture va du plus discriminant au plus détaillé : priorité et
 * numéro d'abord — c'est par là qu'on trie du regard — puis le titre, puis
 * ce qui situe (étiquettes, projet, échéance).
 */
import { computed } from 'vue'

import TicketPriorityIcon from '@/components/tickets/TicketPriorityIcon.vue'
import TicketStatusIcon from '@/components/tickets/TicketStatusIcon.vue'
import UserAvatar from '@/components/ui/UserAvatar.vue'
import { formatDue, isClosed, isOverdue } from '@/utils/tickets'

const props = defineProps({
  ticket: { type: Object, required: true },
  /**
   * Qui a ce ticket ouvert en ce moment, par leur nom.
   *
   * L'information qui évite une collision : elle se voit AVANT d'ouvrir, pas
   * après avoir écrit. Un conflit qu'on peut ne pas provoquer vaut mieux
   * qu'un conflit bien arbitré.
   */
  watchers: { type: Array, default: () => [] },
})

const overdue = computed(() => isOverdue(props.ticket))
const closed = computed(() => isClosed(props.ticket))
const due = computed(() => formatDue(props.ticket))
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <div class="flex items-center gap-2">
      <TicketPriorityIcon :priority="ticket.priority" class="shrink-0" />
      <TicketStatusIcon :status="ticket.status" class="shrink-0" />

      <!-- Le numéro est la référence stable du ticket : en chasse fixe et en
           chiffres tabulaires, pour s'aligner d'une carte à l'autre. -->
      <span class="font-mono text-[0.7rem] tabular-nums text-ink-3">{{ ticket.number }}</span>

      <span class="ml-auto" />

      <span
        v-if="due"
        class="shrink-0 text-[0.7rem] tabular-nums"
        :class="overdue ? 'font-medium text-brick' : 'text-ink-3'"
      >
        {{ due }}
      </span>

      <!-- L'assigné à l'extrême droite, et SEULEMENT s'il y en a un : une
           colonne de tirets pour dire « personne » ferait du bruit là où
           l'absence se lit déjà toute seule. C'est la question qu'on balaye
           en descendant une colonne — « lesquels sont à moi ». -->
      <UserAvatar v-if="ticket.assignee_name" :name="ticket.assignee_name" size="xs" />
    </div>

    <!-- QUELQU'UN EST DESSUS, EN CE MOMENT.
         Placé en tête du corps de la carte, pas dans la ligne de repères du
         haut : ce n'est pas un attribut du ticket mais un fait passager, et
         le mélanger aux autres ferait croire à une propriété.

         En ocre — la couleur d'un avertissement, pas d'une erreur : il n'y a
         rien de cassé, seulement une raison d'attendre ou de prévenir. -->
    <p
      v-if="watchers.length"
      class="flex items-center gap-1 text-[0.66rem] text-ochre"
      aria-live="polite"
    >
      <span class="size-1.5 shrink-0 rounded-pill bg-ochre" aria-hidden="true" />
      {{ watchers.join(', ') }} {{ watchers.length > 1 ? 'y sont' : 'y est' }}
    </p>

    <!-- Deux lignes au plus : au-delà, une carte cesse d'être balayable et
         les colonnes perdent leur régularité. -->
    <p
      class="line-clamp-2 text-[0.82rem] leading-snug"
      :class="closed ? 'text-ink-3 line-through decoration-ink-3/40' : 'text-ink'"
    >
      {{ ticket.title }}
    </p>

    <div v-if="ticket.labels.length || ticket.project" class="flex flex-wrap items-center gap-1">
      <span
        v-for="label in ticket.labels.slice(0, 2)"
        :key="label"
        class="rounded-pill border border-line px-1.5 py-px text-[0.64rem] text-ink-3"
      >
        {{ label }}
      </span>
      <span v-if="ticket.labels.length > 2" class="text-[0.64rem] text-ink-3">
        +{{ ticket.labels.length - 2 }}
      </span>

      <span v-if="ticket.project" class="truncate text-[0.68rem] text-ink-3">
        {{ ticket.project }}
      </span>
    </div>
  </div>
</template>
