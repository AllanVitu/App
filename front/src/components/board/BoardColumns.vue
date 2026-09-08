<script setup>
/**
 * Tableau en colonnes, partagé par les modules qui ont un vrai STATUT.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  QUAND CE COMPOSANT A SA PLACE, ET QUAND IL N'EN A PAS              │
 * │                                                                     │
 * │  Une colonne par statut suppose un CYCLE DE VIE : quelque chose qui │
 * │  avance, et qu'on fait avancer. C'est vrai des tickets (en attente  │
 * │  -> à faire -> en cours -> terminé), des déploiements et des        │
 * │  erreurs.                                                           │
 * │                                                                     │
 * │  Ce n'est pas vrai d'une table de données ni d'un fichier de        │
 * │  maquette : leur donner des colonnes inventerait une progression    │
 * │  qui n'existe pas, et le lecteur y verrait un sens.                 │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * DÉPLACER N'EST PAS TOUJOURS PERMIS, et c'est réglé par module. Un ticket
 * se fait avancer : le glissement est le geste juste. Un déploiement est un
 * ÉVÉNEMENT : le faire glisser de « en échec » vers « en ligne » réécrirait
 * l'histoire. D'où « draggable », faux par défaut.
 *
 * ACCESSIBILITÉ. Le conteneur reste une « listbox » et chaque carte une
 * « option » : c'est ce qui porte le curseur clavier, qui est le chemin
 * PRINCIPAL du module — le glissement n'est qu'une couche de plus. Les
 * enveloppes de colonne portent « role="presentation" » pour ne pas rompre
 * le lien entre la liste et ses options.
 */
import { computed, ref } from 'vue'

import { useBoardDrag } from '@/composables/useBoardDrag'

const props = defineProps({
  /** Colonnes, dans l'ordre d'affichage : [{ value, label, tone }]. */
  columns: { type: Array, required: true },
  items: { type: Array, required: true },
  /** Champ portant le statut. */
  statusKey: { type: String, default: 'status' },
  /** Identifiant de la carte sous le curseur clavier. */
  activeId: { type: String, default: null },
  /** Autorise le glissement d'une colonne à l'autre. */
  draggable: { type: Boolean, default: false },
  label: { type: String, default: 'Tableau' },
  /** Préfixe des identifiants de carte, pour « aria-activedescendant ». */
  idPrefix: { type: String, default: 'card' },
})

const emit = defineEmits(['move', 'select', 'open'])

const root = ref(null)

// La vue parente mesure ce nœud AVANT d'écrire, pour faire glisser les cartes
// vers leur nouvelle colonne au lieu de les faire sauter (animations/layout.js).
defineExpose({ root })

const { draggingId, overColumn, onPointerDown } = useBoardDrag({
  onDrop(id, column) {
    const item = props.items.find((entry) => String(entry.id) === id)

    // Reposée dans sa propre colonne : rien n'a changé, on n'écrit rien.
    if (!item || item[props.statusKey] === column) return

    emit('move', item, column)
  },
})

/**
 * Répartition en une seule passe.
 *
 * Un `filter` par colonne relirait la liste autant de fois qu'il y a de
 * colonnes — cinq passes sur cinq cents tickets à chaque frappe dans la
 * recherche. Une passe suffit.
 */
const grouped = computed(() => {
  const buckets = new Map(props.columns.map((column) => [column.value, []]))

  for (const item of props.items) {
    buckets.get(item[props.statusKey])?.push(item)
  }

  return buckets
})

const itemsOf = (column) => grouped.value.get(column.value) ?? []

function startDrag(event) {
  if (!props.draggable) return

  onPointerDown(event, event.currentTarget.dataset.boardCard, root.value)
}
</script>

<template>
  <div
    ref="root"
    role="listbox"
    :aria-label="label"
    class="grid min-h-0 flex-1 gap-2.5 overflow-x-auto pb-1"
    :style="{ gridTemplateColumns: `repeat(${columns.length}, minmax(13rem, 1fr))` }"
  >
    <div
      v-for="column in columns"
      :key="column.value"
      role="presentation"
      class="flex min-w-0 flex-col"
    >
      <!-- En-tête : le libellé, et le nombre de cartes qu'il contient. Le
           compteur est la première chose qu'on lit d'une colonne — c'est lui
           qui dit si le travail s'accumule quelque part. -->
      <div class="mb-2 flex shrink-0 items-center gap-2 px-1">
        <span class="text-[0.78rem] font-semibold lowercase" :class="column.tone">
          {{ column.label }}
        </span>
        <span class="text-[0.72rem] tabular-nums text-ink-3">{{ itemsOf(column).length }}</span>
      </div>

      <!-- Zone de dépôt. « data-column » est ce que le glissement mesure ;
           le fond change quand la carte la survole, sans quoi on lâcherait
           à l'aveugle. -->
      <div
        :data-column="column.value"
        role="presentation"
        class="flex min-h-24 flex-1 flex-col gap-2 rounded-card border border-dashed p-2 transition-colors"
        :class="
          overColumn === column.value && draggingId
            ? 'border-ink-3 bg-raised'
            : 'border-transparent bg-panel/40'
        "
      >
        <div
          v-for="item in itemsOf(column)"
          :key="item.id"
          :id="`${idPrefix}-${item.id}`"
          :data-board-card="String(item.id)"
          role="option"
          :aria-selected="String(item.id) === activeId"
          class="card cursor-pointer p-2.5 transition-colors hover:border-line-2 hover:bg-raised"
          :class="[
            String(item.id) === activeId ? 'border-ink-3' : '',
            draggingId === String(item.id) ? 'opacity-90 shadow-lg' : '',
            draggable ? 'touch-none' : '',
          ]"
          @pointerdown="startDrag"
          @click="emit('select', item)"
          @dblclick="emit('open', item)"
        >
          <slot name="card" :item="item" />
        </div>

        <!-- Une colonne vide se dit. Sans cela, on ne sait pas si elle est
             vide ou si le filtre a tout masqué. -->
        <p
          v-if="!itemsOf(column).length"
          class="px-1 py-2 text-[0.72rem] text-ink-3"
          aria-hidden="true"
        >
          aucun
        </p>
      </div>
    </div>
  </div>
</template>
