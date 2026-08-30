<script setup>
/**
 * Module « Tickets » — suivi clavier-first.
 *
 * Écran DÉDIÉ, distinct de ModuleView : un ticket a un numéro, une priorité
 * ordonnée et un cycle de vie, qu'une liste générique « titre + statut »
 * ne sait pas représenter.
 *
 * Deux principes gouvernent l'interface :
 *
 *  1. TOUT SE FAIT AU CLAVIER. La souris reste possible, mais chaque geste a
 *     sa touche, et le curseur de sélection est un état durable — distinct du
 *     survol, qui ne l'est pas.
 *
 *  2. AUCUN FILTRE N'ATTEND LE RÉSEAU. Les tickets sont chargés d'un bloc et
 *     filtrés localement : une frappe dans la recherche doit réduire la liste
 *     dans la même image, sinon on tape plus vite que l'écran ne répond.
 *
 * Les compteurs, eux, viennent du SERVEUR et non de la liste chargée : le
 * chargement est plafonné (500 lignes), et des totaux recalculés localement
 * mentiraient dès qu'un compte dépasserait ce plafond.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import AppIcon from '@/components/AppIcon.vue'
import TicketPanel from '@/components/tickets/TicketPanel.vue'
import TicketRow from '@/components/tickets/TicketRow.vue'
import TicketStatusIcon from '@/components/tickets/TicketStatusIcon.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { ticketsApi } from '@/services/api'
import { play } from '@/services/sound'
import { useUiStore } from '@/stores/ui'
import { BOARD_ORDER, PRIORITIES, advanceStatus } from '@/utils/tickets'

const ui = useUiStore()

const tickets = ref([])
const stats = ref(null)
const projects = ref([])
const loading = ref(true)
const saving = ref(false)

const search = ref('')
const statusFilter = ref(null)

const activeId = ref(null)
const panelOpen = ref(false)
const helpOpen = ref(false)
const composing = ref(false)
const composeTitle = ref('')

const searchInput = ref(null)
const composeInput = ref(null)
const listBox = ref(null)
const panel = ref(null)

// --- Chargement --------------------------------------------------------------

async function load({ silent = false } = {}) {
  if (!silent) loading.value = true

  try {
    const { tickets: rows, meta } = await ticketsApi.list()

    tickets.value = rows
    stats.value = meta.stats
    projects.value = meta.projects
  } catch (error) {
    ui.notify(error.message, 'error')
  } finally {
    loading.value = false
  }
}

/**
 * Rafraîchissement des compteurs après une écriture.
 *
 * Un garde-fou d'appel en vol suffit : enchaîner les raccourcis déclencherait
 * sinon une requête par frappe, et c'est toujours la dernière qui compte.
 */
let refreshing = false

async function refresh() {
  if (refreshing) return

  refreshing = true

  try {
    await load({ silent: true })
  } finally {
    refreshing = false
  }
}

// --- Filtrage et regroupement ------------------------------------------------

const filtered = computed(() => {
  const needle = search.value.trim().toLowerCase()

  return tickets.value.filter((ticket) => {
    if (statusFilter.value && ticket.status !== statusFilter.value) return false

    if (!needle) return true

    // Le numéro est cherché comme le texte : « 128 » doit trouver #128.
    return (
      String(ticket.number).includes(needle) ||
      ticket.title.toLowerCase().includes(needle) ||
      (ticket.description ?? '').toLowerCase().includes(needle) ||
      (ticket.project ?? '').toLowerCase().includes(needle) ||
      ticket.labels.some((label) => label.includes(needle))
    )
  })
})

/** Groupes affichés, dans l'ordre du travail — en cours d'abord, clos ensuite. */
const groups = computed(() =>
  BOARD_ORDER.map((status) => ({
    status,
    tickets: filtered.value.filter((ticket) => ticket.status === status.value),
  })).filter((group) => group.tickets.length > 0),
)

/**
 * Liste À PLAT dans l'ordre d'affichage.
 *
 * C'est elle que j/k parcourent : le curseur doit suivre l'ordre de lecture
 * de l'écran, pas celui du tableau source.
 */
const flat = computed(() => groups.value.flatMap((group) => group.tickets))

const activeIndex = computed(() => flat.value.findIndex((ticket) => ticket.id === activeId.value))

const activeTicket = computed(() => flat.value[activeIndex.value] ?? null)

/** Le curseur ne doit jamais désigner une ligne masquée par un filtre. */
watch(flat, (rows) => {
  if (rows.length === 0) {
    activeId.value = null
    panelOpen.value = false

    return
  }

  if (!rows.some((ticket) => ticket.id === activeId.value)) {
    activeId.value = rows[0].id
  }
})

// --- Déplacement du curseur --------------------------------------------------

function move(step) {
  const rows = flat.value

  if (rows.length === 0) return

  const index = activeIndex.value
  // Depuis « aucune sélection », descendre prend la première ligne et monter
  // prend la dernière : les deux gestes entrent dans la liste par son bord.
  const next =
    index === -1
      ? step > 0
        ? 0
        : rows.length - 1
      : Math.min(Math.max(index + step, 0), rows.length - 1)

  activeId.value = rows[next].id
  scrollActiveIntoView()
}

async function scrollActiveIntoView() {
  await nextTick()

  listBox.value
    ?.querySelector(`#ticket-${CSS.escape(activeId.value ?? '')}`)
    ?.scrollIntoView({ block: 'nearest' })
}

// --- Écritures ---------------------------------------------------------------

/**
 * Mise à jour optimiste : la ligne change à l'écran avant la réponse du
 * serveur, et revient à son état antérieur si l'appel échoue. Sans cela, un
 * raccourci clavier donnerait l'impression de n'avoir rien fait le temps de
 * l'aller-retour.
 */
async function patch(ticket, changes) {
  const index = tickets.value.findIndex((row) => row.id === ticket.id)

  if (index === -1) return

  const previous = tickets.value[index]

  tickets.value[index] = { ...previous, ...changes }
  saving.value = true

  try {
    tickets.value[index] = await ticketsApi.update(ticket.id, changes)
    play('tick')
    refresh()
  } catch (error) {
    tickets.value[index] = previous
    ui.notify(error.message, 'error')
  } finally {
    saving.value = false
  }
}

function setPriority(priority) {
  if (activeTicket.value) patch(activeTicket.value, { priority })
}

function shiftStatus(step) {
  if (!activeTicket.value) return

  patch(activeTicket.value, { status: advanceStatus(activeTicket.value.status, step) })
}

async function createTicket() {
  const title = composeTitle.value.trim()

  if (title === '') {
    composing.value = false

    return
  }

  try {
    // Le ticket naît dans la colonne filtrée, s'il y en a une : on crée là où
    // l'on regarde. Sans filtre, « à faire » est le défaut du serveur.
    const created = await ticketsApi.create({
      title,
      ...(statusFilter.value ? { status: statusFilter.value } : {}),
    })

    tickets.value.unshift(created)
    activeId.value = created.id
    composeTitle.value = ''
    play('success')
    refresh()

    // Le champ reste ouvert : on saisit rarement un seul ticket à la fois.
    await nextTick()
    composeInput.value?.focus()
  } catch (error) {
    ui.notify(error.message, 'error')
  }
}

async function removeTicket(ticket) {
  const index = tickets.value.findIndex((row) => row.id === ticket.id)
  const previous = tickets.value[index]

  tickets.value.splice(index, 1)
  panelOpen.value = false

  try {
    await ticketsApi.remove(ticket.id)
    ui.notify(`Ticket #${ticket.number} supprimé.`, 'info')
    refresh()
  } catch (error) {
    tickets.value.splice(index, 0, previous)
    ui.notify(error.message, 'error')
  }
}

// --- Clavier -----------------------------------------------------------------

async function openComposer() {
  composing.value = true
  await nextTick()
  composeInput.value?.focus()
}

function cancelCompose() {
  composing.value = false
  composeTitle.value = ''
}

function openTicket(ticket) {
  activeId.value = ticket.id
  panelOpen.value = true
  play('open')
}

async function focusSearch() {
  await nextTick()
  searchInput.value?.focus()
  searchInput.value?.select()
}

/** Une frappe destinée à un champ de saisie ne doit jamais être interceptée. */
function isTyping(target) {
  return (
    target instanceof HTMLElement &&
    (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable)
  )
}

function onKeydown(event) {
  // Échap agit partout, y compris depuis un champ : c'est la sortie de
  // secours, elle ne peut pas dépendre du focus.
  if (event.key === 'Escape') {
    if (helpOpen.value) helpOpen.value = false
    else if (composing.value) cancelCompose()
    else if (panelOpen.value) panelOpen.value = false
    else if (search.value) search.value = ''
    else event.target?.blur?.()

    return
  }

  if (isTyping(event.target)) return

  // Les combinaisons appartiennent au navigateur et au système.
  if (event.metaKey || event.ctrlKey || event.altKey) return

  const key = event.key

  // --- Navigation ---
  if (key === 'j' || key === 'ArrowDown') return act(event, () => move(1))
  if (key === 'k' || key === 'ArrowUp') return act(event, () => move(-1))

  if (key === 'Enter') {
    return act(event, () => {
      if (activeTicket.value) openTicket(activeTicket.value)
    })
  }

  // --- Recherche et création ---
  if (key === '/') return act(event, focusSearch)
  if (key === 'c') return act(event, openComposer)
  if (key === '?') return act(event, () => (helpOpen.value = !helpOpen.value))

  // --- Édition du ticket sous le curseur ---
  if (!activeTicket.value) return

  if (key === 'ArrowRight') return act(event, () => shiftStatus(1))
  if (key === 'ArrowLeft') return act(event, () => shiftStatus(-1))
  if (key === 'd') return act(event, () => patch(activeTicket.value, { status: 'done' }))
  if (key === 'a') return act(event, () => patch(activeTicket.value, { status: 'canceled' }))

  if (key === 'e') {
    return act(event, async () => {
      panelOpen.value = true
      await panel.value?.focusTitle()
    })
  }

  if (key === 'Backspace' || key === 'Delete') {
    return act(event, () => removeTicket(activeTicket.value))
  }

  const priority = PRIORITIES.find((entry) => entry.key === key)

  if (priority) return act(event, () => setPriority(priority.value))
}

/** Un raccourci reconnu ne doit pas AUSSI déclencher l'action native de la
 *  touche — la flèche ferait défiler la page, l'espace la ferait sauter. */
function act(event, action) {
  event.preventDefault()
  action()
}

onMounted(() => {
  load()
  window.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

const SHORTCUTS = [
  { keys: ['j', 'k'], label: 'ticket suivant / précédent' },
  { keys: ['Entrée'], label: 'ouvrir le détail' },
  { keys: ['e'], label: 'modifier le titre' },
  { keys: ['/'], label: 'rechercher' },
  { keys: ['c'], label: 'nouveau ticket' },
  { keys: ['→', '←'], label: 'avancer / reculer dans le cycle' },
  { keys: ['d'], label: 'marquer terminé' },
  { keys: ['a'], label: 'annuler le ticket' },
  { keys: ['1', '2', '3', '4', '0'], label: 'priorité : urgente → aucune' },
  { keys: ['⌫'], label: 'supprimer' },
  { keys: ['Échap'], label: 'fermer / vider' },
  { keys: ['?'], label: 'cette aide' },
]
</script>

<template>
  <div class="flex h-full min-h-0 flex-col gap-5">
    <!-- ============================ EN-TÊTE ============================ -->
    <header class="shrink-0">
      <div class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
          <p class="label-caps">module</p>
          <h2 class="mt-1 text-xl font-bold lowercase">tickets</h2>
        </div>

        <div v-if="stats" class="flex items-center gap-4 text-[0.76rem] tabular-nums">
          <span class="text-ink-2">
            <span class="font-semibold text-ink">{{ stats.open }}</span> ouverts
          </span>
          <span v-if="stats.urgent" class="text-brick">
            <span class="font-semibold">{{ stats.urgent }}</span> urgents
          </span>
          <span v-if="stats.overdue" class="text-brick">
            <span class="font-semibold">{{ stats.overdue }}</span> en retard
          </span>
          <span class="text-ink-3">{{ stats.done }} terminés</span>
        </div>
      </div>

      <!-- Barre de filtres -->
      <div class="mt-4 flex flex-wrap items-center gap-2">
        <div class="relative min-w-52 flex-1">
          <AppIcon
            name="search"
            :size="14"
            class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-3"
          />
          <input
            ref="searchInput"
            v-model="search"
            type="search"
            class="input-field py-2 pl-9 text-[0.82rem]"
            placeholder="Rechercher — touche /"
            aria-label="Rechercher un ticket"
          />
        </div>

        <button
          type="button"
          class="chip transition-colors"
          :class="
            statusFilter === null
              ? 'border-ink bg-raised text-ink'
              : 'border-line text-ink-3 hover:border-ink-3 hover:text-ink'
          "
          :aria-pressed="statusFilter === null"
          @click="statusFilter = null"
        >
          tous
        </button>

        <button
          v-for="status in BOARD_ORDER"
          :key="status.value"
          type="button"
          class="chip transition-colors"
          :class="
            statusFilter === status.value
              ? 'border-ink bg-raised text-ink'
              : 'border-line text-ink-3 hover:border-ink-3 hover:text-ink'
          "
          :aria-pressed="statusFilter === status.value"
          @click="statusFilter = statusFilter === status.value ? null : status.value"
        >
          <TicketStatusIcon :status="status.value" :size="12" />
          {{ status.short }}
        </button>

        <span class="flex-1" />

        <button
          type="button"
          class="chip border-line text-ink-3 transition-colors hover:border-ink-3 hover:text-ink"
          @click="helpOpen = !helpOpen"
        >
          raccourcis <kbd class="font-mono">?</kbd>
        </button>

        <button
          type="button"
          class="chip border-ink bg-ink text-paper transition-opacity hover:opacity-90"
          @click="openComposer"
        >
          <AppIcon name="plus" :size="13" />
          nouveau <kbd class="font-mono opacity-70">c</kbd>
        </button>
      </div>
    </header>

    <!-- ============================ AIDE ============================ -->
    <div v-if="helpOpen" class="card shrink-0 p-4">
      <div class="grid gap-x-8 gap-y-1.5 sm:grid-cols-2 lg:grid-cols-3">
        <div
          v-for="shortcut in SHORTCUTS"
          :key="shortcut.label"
          class="flex items-baseline gap-2 text-[0.76rem]"
        >
          <span class="flex shrink-0 gap-1">
            <kbd
              v-for="entry in shortcut.keys"
              :key="entry"
              class="rounded border border-line bg-raised px-1.5 py-px font-mono text-[0.68rem] text-ink-2"
            >
              {{ entry }}
            </kbd>
          </span>
          <span class="text-ink-3">{{ shortcut.label }}</span>
        </div>
      </div>
    </div>

    <!-- ============================ CORPS ============================ -->
    <div v-if="loading" class="flex flex-1 items-center justify-center py-20">
      <BaseSpinner class="size-7 text-ink" />
    </div>

    <div v-else class="flex min-h-0 flex-1 gap-5">
      <!-- Liste -->
      <div class="card flex min-w-0 flex-1 flex-col overflow-hidden">
        <!-- Composition : une ligne, un titre. Le reste se règle ensuite au
             clavier — demander sept champs pour noter une idée revient à ce
             qu'elle finisse ailleurs. -->
        <div v-if="composing" class="shrink-0 border-b border-line p-2">
          <input
            ref="composeInput"
            v-model="composeTitle"
            class="input-field py-2 text-[0.84rem]"
            placeholder="Titre du ticket — Entrée pour créer, Échap pour abandonner"
            @keydown.enter.prevent="createTicket"
            @keydown.esc.prevent="cancelCompose"
          />
        </div>

        <EmptyState
          v-if="flat.length === 0"
          class="flex-1"
          :title="search || statusFilter ? 'Aucun ticket ne correspond' : 'Aucun ticket'"
          :description="
            search || statusFilter
              ? 'Modifiez la recherche ou le filtre de statut.'
              : 'Appuyez sur « c » pour créer le premier.'
          "
        />

        <ul
          v-else
          ref="listBox"
          role="listbox"
          tabindex="0"
          aria-label="Tickets"
          :aria-activedescendant="activeId ? `ticket-${activeId}` : undefined"
          class="flex-1 overflow-y-auto focus:outline-none"
        >
          <template v-for="group in groups" :key="group.status.value">
            <!-- En-tête de groupe collant : en descendant une longue liste,
                 on doit toujours savoir dans quelle colonne on se trouve. -->
            <li
              class="sticky top-0 z-10 flex items-center gap-2 border-b border-line bg-panel px-3 py-1.5"
              role="presentation"
            >
              <TicketStatusIcon :status="group.status.value" :size="12" />
              <span class="label-caps">{{ group.status.label }}</span>
              <span class="text-[0.7rem] tabular-nums text-ink-3">{{ group.tickets.length }}</span>
            </li>

            <TicketRow
              v-for="ticket in group.tickets"
              :key="ticket.id"
              :ticket="ticket"
              :active="ticket.id === activeId"
              @select="activeId = ticket.id"
              @open="openTicket(ticket)"
            />
          </template>
        </ul>
      </div>

      <!-- Détail -->
      <TicketPanel
        v-if="panelOpen && activeTicket"
        ref="panel"
        :ticket="activeTicket"
        :projects="projects"
        :saving="saving"
        class="hidden lg:flex"
        @patch="patch(activeTicket, $event)"
        @close="panelOpen = false"
        @delete="removeTicket(activeTicket)"
      />
    </div>
  </div>
</template>
