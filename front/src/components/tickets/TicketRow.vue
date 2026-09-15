<script setup>
/**
 * Une ligne de la liste des tickets.
 *
 * Dense par choix : un suivi se parcourt, il ne se lit pas. Chaque colonne
 * occupe une largeur FIXE (priorité, statut, numéro) afin que l'œil descende
 * la liste en ligne droite — c'est cet alignement qui permet de repérer les
 * urgences sans lire les titres.
 *
 * Deux états visuels distincts se superposent ici :
 *   — le SURVOL, transitoire, qui suit la souris ;
 *   — le CURSEUR clavier (« active »), qui est une position durable et la
 *     cible des raccourcis.
 * Les confondre rendrait les raccourcis imprévisibles dès que la souris
 * effleure la liste.
 */
import { computed } from 'vue'

import TicketPriorityIcon from '@/components/tickets/TicketPriorityIcon.vue'
import TicketStatusIcon from '@/components/tickets/TicketStatusIcon.vue'
import UserAvatar from '@/components/ui/UserAvatar.vue'
import { formatDue, isClosed, isOverdue } from '@/utils/tickets'

const props = defineProps({
  ticket: { type: Object, required: true },
  active: { type: Boolean, default: false },
})

defineEmits(['select', 'open'])

const overdue = computed(() => isOverdue(props.ticket))
const closed = computed(() => isClosed(props.ticket))
const due = computed(() => formatDue(props.ticket))
</script>

<template>
  <li
    :id="`ticket-${ticket.id}`"
    role="option"
    :aria-selected="active"
    class="group flex cursor-pointer items-center gap-3 border-l-2 px-3 py-2 transition-colors"
    :class="
      active
        ? 'border-l-ink bg-raised'
        : 'border-l-transparent hover:border-l-line-2 hover:bg-raised/60'
    "
    @click="$emit('select')"
    @dblclick="$emit('open')"
  >
    <TicketPriorityIcon :priority="ticket.priority" class="shrink-0" />
    <TicketStatusIcon :status="ticket.status" class="shrink-0" />

    <!-- Le numéro est la référence stable du ticket : en chasse fixe et en
         chiffres tabulaires, pour que les colonnes s'alignent. -->
    <span class="w-10 shrink-0 font-mono text-[0.72rem] tabular-nums text-ink-3">
      {{ ticket.number }}
    </span>

    <span
      class="min-w-0 flex-1 truncate text-[0.84rem]"
      :class="closed ? 'text-ink-3 line-through decoration-ink-3/40' : 'text-ink'"
    >
      {{ ticket.title }}
    </span>

    <!-- Étiquettes : deux au plus sur la ligne, le reste au compteur. Une
         ligne qui déborde casse l'alignement de toute la colonne. -->
    <span v-if="ticket.labels.length" class="hidden shrink-0 items-center gap-1 sm:flex">
      <span
        v-for="label in ticket.labels.slice(0, 2)"
        :key="label"
        class="rounded-pill border border-line px-1.5 py-px text-[0.66rem] text-ink-3"
      >
        {{ label }}
      </span>
      <span v-if="ticket.labels.length > 2" class="text-[0.66rem] text-ink-3">
        +{{ ticket.labels.length - 2 }}
      </span>
    </span>

    <span
      v-if="ticket.project"
      class="hidden w-28 shrink-0 truncate text-right text-[0.72rem] text-ink-3 lg:block"
    >
      {{ ticket.project }}
    </span>

    <span
      v-if="due"
      class="w-24 shrink-0 text-right text-[0.72rem] tabular-nums"
      :class="overdue ? 'font-medium text-brick' : 'text-ink-3'"
    >
      {{ due }}
    </span>
    <!-- Réservation de la place même sans échéance : sinon les lignes datées
         et les autres ne se termineraient pas au même endroit. -->
    <span v-else class="w-24 shrink-0" aria-hidden="true" />

    <!-- L'assigné ferme la ligne. Même règle de réservation que l'échéance :
         la colonne existe toujours, elle est simplement vide quand personne
         n'a pris le ticket — sinon les lignes se termineraient à des endroits
         différents et le balayage vertical y perdrait. -->
    <UserAvatar v-if="ticket.assignee_name" :name="ticket.assignee_name" size="xs" />
    <span v-else class="size-5 shrink-0" aria-hidden="true" />
  </li>
</template>
