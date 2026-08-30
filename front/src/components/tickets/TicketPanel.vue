<script setup>
/**
 * Panneau de détail d'un ticket.
 *
 * Tiroir latéral plutôt que fenêtre modale : la liste reste visible et le
 * curseur clavier ne se perd pas. Ouvrir un ticket dans un suivi n'est pas
 * une interruption, c'est un déplacement de l'attention.
 *
 * Toutes les modifications sont ENVOYÉES CHAMP PAR CHAMP, dès la validation
 * de ce champ. Il n'y a donc pas de bouton « enregistrer » à oublier, et
 * l'API ne reçoit jamais un ticket complet potentiellement périmé : seul le
 * champ modifié part (mise à jour partielle, cf. TicketController).
 */
import { computed, nextTick, ref, watch } from 'vue'

import AppIcon from '@/components/AppIcon.vue'
import TicketPriorityIcon from '@/components/tickets/TicketPriorityIcon.vue'
import TicketStatusIcon from '@/components/tickets/TicketStatusIcon.vue'
import { PRIORITIES, STATUSES, formatDue, isOverdue } from '@/utils/tickets'

const props = defineProps({
  ticket: { type: Object, required: true },
  projects: { type: Array, default: () => [] },
  saving: { type: Boolean, default: false },
})

const emit = defineEmits(['patch', 'close', 'delete'])

const titleInput = ref(null)
const draftTitle = ref('')
const draftDescription = ref('')
const draftProject = ref('')
const draftLabels = ref('')

/**
 * Champ en cours de saisie, s'il y en a un.
 *
 * Les enregistrements partent champ par champ, donc une réponse du serveur
 * peut arriver PENDANT qu'on saisit un autre champ. Sans ce garde-fou, la
 * resynchronisation qui suit écrasait la saisie en cours : on tapait des
 * étiquettes, l'enregistrement du projet aboutissait, et le texte tapé
 * disparaissait sous les doigts.
 */
const editing = ref(null)

/**
 * Les brouillons se resynchronisent quand on change de ticket, mais AUSSI
 * quand le ticket courant revient modifié du serveur — sinon un champ
 * garderait la valeur d'avant l'enregistrement, et la normalisation faite
 * côté serveur (minuscules, doublons) ne se verrait jamais.
 *
 * Le champ en cours de saisie est le seul épargné : lui seul a une valeur
 * plus récente que celle du serveur.
 */
watch(
  () => props.ticket,
  (ticket) => {
    if (editing.value !== 'title') draftTitle.value = ticket.title
    if (editing.value !== 'description') draftDescription.value = ticket.description ?? ''
    if (editing.value !== 'project') draftProject.value = ticket.project ?? ''
    if (editing.value !== 'labels') draftLabels.value = ticket.labels.join(', ')
  },
  { immediate: true, deep: true },
)

const beginEdit = (field) => (editing.value = field)

const due = computed(() => formatDue(props.ticket))
const overdue = computed(() => isOverdue(props.ticket))

/**
 * N'émet que si la valeur a réellement changé : sans ce garde-fou, un simple
 * passage dans un champ déclencherait une requête inutile.
 *
 * Les tableaux sont comparés élément par élément, et non par concaténation :
 * `['a b']` et `['a', 'b']` produiraient la même chaîne jointe alors que ce
 * sont deux listes d'étiquettes différentes.
 */
function sameValue(current, next) {
  if (Array.isArray(current) && Array.isArray(next)) {
    return current.length === next.length && current.every((entry, i) => entry === next[i])
  }

  return (current ?? '') === (next ?? '')
}

function commit(field, value) {
  const current = props.ticket[field] ?? (field === 'labels' ? [] : '')

  if (sameValue(current, value)) return

  emit('patch', { [field]: value })
}

function commitTitle() {
  const value = draftTitle.value.trim()

  // Un titre vidé est refusé par le serveur : on rétablit l'ancien plutôt que
  // d'envoyer une requête qu'on sait perdue d'avance.
  if (value === '') {
    draftTitle.value = props.ticket.title

    return
  }

  commit('title', value)
}

function commitLabels() {
  // La normalisation (minuscules, doublons) est faite par le SERVEUR : la
  // refaire ici créerait deux sources de vérité pour la même règle.
  const parsed = draftLabels.value
    .split(',')
    .map((label) => label.trim())
    .filter(Boolean)

  commit('labels', parsed)
}

/**
 * Fin de saisie : on enregistre, PUIS on rend la main au serveur.
 *
 * L'ordre compte. Libérer le champ avant l'enregistrement laisserait la
 * réponse écraser la valeur qu'on vient tout juste de valider ; le libérer
 * après permet à la version normalisée par le serveur de revenir s'afficher.
 */
function endEdit(field) {
  if (field === 'title') commitTitle()
  else if (field === 'description') commit('description', draftDescription.value.trim() || null)
  else if (field === 'project') commit('project', draftProject.value.trim() || null)
  else if (field === 'labels') commitLabels()

  editing.value = null
}

/** Appelée par la vue quand le panneau vient de s'ouvrir en mode édition. */
async function focusTitle() {
  await nextTick()
  titleInput.value?.focus()
  titleInput.value?.select()
}

defineExpose({ focusTitle })
</script>

<template>
  <aside
    class="panel flex w-full shrink-0 flex-col overflow-hidden lg:w-96"
    aria-label="Détail du ticket"
  >
    <!-- En-tête : numéro, actions -->
    <header class="flex shrink-0 items-center gap-2 border-b border-line px-4 py-3">
      <span class="font-mono text-[0.78rem] tabular-nums text-ink-3">#{{ ticket.number }}</span>

      <span v-if="saving" class="text-[0.68rem] text-ink-3">enregistrement…</span>

      <span class="flex-1" />

      <button
        type="button"
        class="rounded-field p-1.5 text-ink-3 transition-colors hover:bg-raised hover:text-brick"
        aria-label="Supprimer le ticket"
        @click="$emit('delete')"
      >
        <AppIcon name="trash" :size="15" />
      </button>

      <button
        type="button"
        class="rounded-field p-1.5 text-ink-3 transition-colors hover:bg-raised hover:text-ink"
        aria-label="Fermer le détail"
        @click="$emit('close')"
      >
        <AppIcon name="close" :size="15" />
      </button>
    </header>

    <div class="flex-1 space-y-5 overflow-y-auto p-4">
      <!-- Titre : champ libre, enregistré à la sortie ou sur Entrée -->
      <input
        ref="titleInput"
        v-model="draftTitle"
        class="w-full resize-none border-0 bg-transparent p-0 text-[1rem] font-semibold text-ink outline-none placeholder:text-ink-3"
        placeholder="Titre du ticket"
        @focus="beginEdit('title')"
        @blur="endEdit('title')"
        @keydown.enter.prevent="endEdit('title')"
      />

      <!-- Statut -->
      <div>
        <p class="label-caps mb-2">statut</p>
        <div class="flex flex-wrap gap-1">
          <button
            v-for="option in STATUSES"
            :key="option.value"
            type="button"
            class="chip transition-colors"
            :class="
              ticket.status === option.value
                ? 'border-ink bg-raised text-ink'
                : 'border-line text-ink-3 hover:border-ink-3 hover:text-ink'
            "
            :aria-pressed="ticket.status === option.value"
            @click="commit('status', option.value)"
          >
            <TicketStatusIcon :status="option.value" :size="12" />
            {{ option.label }}
          </button>
        </div>
      </div>

      <!-- Priorité -->
      <div>
        <p class="label-caps mb-2">priorité</p>
        <div class="flex flex-wrap gap-1">
          <button
            v-for="option in PRIORITIES"
            :key="option.value"
            type="button"
            class="chip transition-colors"
            :class="
              ticket.priority === option.value
                ? 'border-ink bg-raised text-ink'
                : 'border-line text-ink-3 hover:border-ink-3 hover:text-ink'
            "
            :aria-pressed="ticket.priority === option.value"
            @click="commit('priority', option.value)"
          >
            <TicketPriorityIcon :priority="option.value" :size="12" />
            {{ option.label }}
          </button>
        </div>
      </div>

      <!-- Description -->
      <div>
        <label class="label-caps mb-2 block" :for="`desc-${ticket.id}`">description</label>
        <textarea
          :id="`desc-${ticket.id}`"
          v-model="draftDescription"
          rows="5"
          class="input-field resize-y"
          placeholder="Contexte, reproduction, décision…"
          @focus="beginEdit('description')"
          @blur="endEdit('description')"
        />
      </div>

      <!-- Projet : liste ouverte. <datalist> propose les projets existants
           sans interdire d'en saisir un nouveau — une liste fermée obligerait
           à créer le projet ailleurs avant de pouvoir l'utiliser ici. -->
      <div>
        <label class="label-caps mb-2 block" :for="`project-${ticket.id}`">projet</label>
        <input
          :id="`project-${ticket.id}`"
          v-model="draftProject"
          class="input-field"
          list="ticket-projects"
          placeholder="Aucun"
          @focus="beginEdit('project')"
          @blur="endEdit('project')"
        />
        <datalist id="ticket-projects">
          <option v-for="project in projects" :key="project" :value="project" />
        </datalist>
      </div>

      <!-- Étiquettes -->
      <div>
        <label class="label-caps mb-2 block" :for="`labels-${ticket.id}`">étiquettes</label>
        <input
          :id="`labels-${ticket.id}`"
          v-model="draftLabels"
          class="input-field"
          placeholder="séparées par des virgules"
          @focus="beginEdit('labels')"
          @blur="endEdit('labels')"
          @keydown.enter.prevent="endEdit('labels')"
        />
      </div>

      <!-- Échéance -->
      <div>
        <label class="label-caps mb-2 block" :for="`due-${ticket.id}`">échéance</label>
        <input
          :id="`due-${ticket.id}`"
          type="date"
          :value="ticket.due_date ?? ''"
          class="input-field"
          @change="commit('due_date', $event.target.value || null)"
        />
        <p v-if="due" class="mt-1.5 text-[0.72rem]" :class="overdue ? 'text-brick' : 'text-ink-3'">
          {{ due }}
        </p>
      </div>
    </div>

    <!-- Horodatages : information de bas de page, jamais éditable. -->
    <footer class="shrink-0 space-y-0.5 border-t border-line px-4 py-3 text-[0.68rem] text-ink-3">
      <p>créé le {{ new Date(ticket.created_at).toLocaleString('fr-FR') }}</p>
      <p v-if="ticket.completed_at">
        clos le {{ new Date(ticket.completed_at).toLocaleString('fr-FR') }}
      </p>
    </footer>
  </aside>
</template>
