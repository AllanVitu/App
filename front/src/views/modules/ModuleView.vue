<script setup>
/**
 * Vue générique d'un module.
 *
 * Une seule vue sert les quatre modules : leur structure est identique et le
 * slug de l'URL détermine les données chargées. Ajouter un module en base le
 * rend immédiatement navigable, sans écrire de nouveau composant.
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'

import { Flip, ScrollTrigger, gsap, prefersReducedMotion } from '@/animations/gsap'
import AppIcon from '@/components/AppIcon.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import ItemFormModal from '@/components/ItemFormModal.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { itemsApi, modulesApi } from '@/services/api'
import { useModulesStore } from '@/stores/modules'
import { useUiStore } from '@/stores/ui'
import { formatDate, formatRelative } from '@/utils/format'

const props = defineProps({
  slug: { type: String, required: true },
})

const ui = useUiStore()
const modulesStore = useModulesStore()

const module = ref(null)
const items = ref([])
const meta = ref({ page: 1, per_page: 20, total: 0, total_pages: 1 })

const loading = ref(true)
const notFound = ref(false)

const filters = ref({ search: '', status: '', sort: 'created_at', direction: 'desc' })
const page = ref(1)

// --- Formulaire et suppression ---------------------------------------------
const formOpen = ref(false)
const editing = ref(null)
const submitting = ref(false)
const formErrors = ref({})

const confirmOpen = ref(false)
const deleting = ref(false)
const target = ref(null)

const STATUS_FILTERS = [
  { value: '', label: 'Tous les statuts' },
  { value: 'draft', label: 'Brouillons' },
  { value: 'active', label: 'Actifs' },
  { value: 'archived', label: 'Archivés' },
]

const SORTS = [
  { value: 'created_at', label: 'Plus récents' },
  { value: 'updated_at', label: 'Modifiés récemment' },
  { value: 'title', label: 'Titre (A-Z)' },
  { value: 'due_date', label: 'Échéance' },
]

const hasFilters = computed(() => Boolean(filters.value.search || filters.value.status))

/**
 * Une requête de liste peut être supplantée par la suivante (frappe rapide
 * dans la recherche) : l'ancienne est annulée pour éviter que sa réponse,
 * arrivée en retard, n'écrase la plus récente.
 */
let controller = null
let searchTimer = null

/** ScrollTriggers de la liste courante, à détruire avant chaque rechargement. */
let listTriggers = []

const listRoot = ref(null)

/**
 * Révélation des lignes : celles déjà visibles entrent tout de suite, les
 * suivantes à l'approche du défilement. `ScrollTrigger.batch` regroupe les
 * éléments proches pour produire un seul décalage cohérent plutôt que douze
 * animations isolées.
 */
function revealRows() {
  listTriggers.forEach((trigger) => trigger.kill())
  listTriggers = []

  if (prefersReducedMotion()) return

  listTriggers = ScrollTrigger.batch('[data-flip-item]', {
    start: 'top 95%',
    once: true,
    onEnter: (batch) =>
      gsap.fromTo(
        batch,
        { y: 16, opacity: 0 },
        { y: 0, opacity: 1, stagger: 0.05, duration: 0.45, overwrite: 'auto' },
      ),
  })
}

/**
 * Changement de filtre, de tri ou de page : Flip mémorise la position de
 * chaque ligne AVANT le rechargement, puis les fait glisser vers leur nouvelle
 * place. L'utilisateur suit du regard ce qui a été conservé, au lieu de voir
 * une liste sauter d'un état à l'autre.
 */
async function replayFlip(previousState) {
  await nextTick()

  Flip.from(previousState, {
    duration: 0.5,
    ease: 'appEnter',
    absolute: true,
    // Les lignes qui apparaissent et disparaissent ne « glissent » pas :
    // elles fondent, sinon le mouvement devient illisible.
    onEnter: (elements) =>
      gsap.fromTo(
        elements,
        { opacity: 0, y: 14 },
        { opacity: 1, y: 0, duration: 0.4, stagger: 0.03, overwrite: 'auto' },
      ),
    onLeave: (elements) => gsap.to(elements, { opacity: 0, y: -10, duration: 0.25 }),
  })
}

async function loadItems({ flip = false } = {}) {
  // L'état doit être capturé AVANT toute modification du DOM.
  const previousState =
    flip && !prefersReducedMotion() && items.value.length ? Flip.getState('[data-flip-item]') : null

  controller?.abort()
  controller = new AbortController()

  loading.value = true

  try {
    const params = {
      page: page.value,
      per_page: 12,
      sort: filters.value.sort,
      direction: filters.value.sort === 'title' ? 'asc' : 'desc',
    }

    if (filters.value.search) params.search = filters.value.search
    if (filters.value.status) params.status = filters.value.status

    const result = await itemsApi.list(props.slug, params, controller.signal)
    items.value = result.items
    meta.value = result.meta
  } catch (error) {
    if (error.canceled) return

    if (error.status === 404) {
      notFound.value = true
    } else {
      ui.notify(error.message, 'error')
    }
  } finally {
    // Une requête annulée a déjà cédé la main à la suivante : ne pas
    // repasser loading à false, sinon l'écran clignote.
    if (!controller.signal.aborted) loading.value = false
  }

  if (controller.signal.aborted) return

  if (previousState) {
    await replayFlip(previousState)
  } else {
    await nextTick()
    revealRows()
  }
}

async function loadModule() {
  notFound.value = false

  // Le module est souvent déjà en cache (menu latéral) : évite un appel.
  module.value = modulesStore.bySlug(props.slug)

  if (!module.value) {
    try {
      module.value = await modulesApi.find(props.slug)
    } catch (error) {
      notFound.value = true
      loading.value = false

      return
    }
  }

  await loadItems()
}

// Recherche temporisée : une requête après la frappe, pas une par caractère.
function onSearchInput() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    loadItems({ flip: true })
  }, 350)
}

function applyFilter() {
  page.value = 1
  loadItems({ flip: true })
}

function resetFilters() {
  filters.value.search = ''
  filters.value.status = ''
  applyFilter()
}

function goToPage(next) {
  if (next < 1 || next > meta.value.total_pages) return

  page.value = next
  loadItems({ flip: true })

  // Retour en haut de liste : sans cela, changer de page laisse l'œil au
  // milieu d'un contenu entièrement renouvelé.
  if (!prefersReducedMotion() && listRoot.value) {
    gsap.to(window, { duration: 0.4, scrollTo: { y: listRoot.value, offsetY: 90 } })
  }
}

// --- Actions ----------------------------------------------------------------

function openCreate() {
  editing.value = null
  formErrors.value = {}
  formOpen.value = true
}

function openEdit(item) {
  editing.value = item
  formErrors.value = {}
  formOpen.value = true
}

async function submitForm(payload) {
  submitting.value = true
  formErrors.value = {}

  try {
    if (editing.value) {
      await itemsApi.update(editing.value.id, payload)
      ui.notify('Élément mis à jour.')
    } else {
      await itemsApi.create(props.slug, payload)
      modulesStore.adjustCount(props.slug, 1)
      ui.notify('Élément créé.')
    }

    formOpen.value = false
    await loadItems({ flip: true })
  } catch (error) {
    formErrors.value = error.errors ?? {}

    if (!Object.keys(formErrors.value).length) {
      ui.notify(error.message, 'error')
    }
  } finally {
    submitting.value = false
  }
}

function askDelete(item) {
  target.value = item
  confirmOpen.value = true
}

async function confirmDelete() {
  deleting.value = true

  try {
    await itemsApi.remove(target.value.id)
    modulesStore.adjustCount(props.slug, -1)
    ui.notify('Élément supprimé.')

    // Si la page devient vide après suppression, revenir à la précédente.
    if (items.value.length === 1 && page.value > 1) page.value -= 1

    confirmOpen.value = false
    await loadItems({ flip: true })
  } catch (error) {
    ui.notify(error.message, 'error')
  } finally {
    deleting.value = false
  }
}

// Le slug change quand on passe d'un module à l'autre : le composant est
// réutilisé par le routeur, il faut donc recharger explicitement.
watch(() => props.slug, loadModule, { immediate: true })

onBeforeUnmount(() => {
  controller?.abort()
  clearTimeout(searchTimer)

  // Les ScrollTriggers survivraient à la vue et fuiraient en mémoire.
  listTriggers.forEach((trigger) => trigger.kill())
  listTriggers = []
})
</script>

<template>
  <div v-if="notFound">
    <EmptyState
      icon="alert"
      title="Module introuvable"
      description="Ce module n'existe pas ou ne fait pas partie de votre offre."
    >
      <BaseButton :to="{ name: 'dashboard' }" variant="secondary">
        Retour au tableau de bord
      </BaseButton>
    </EmptyState>
  </div>

  <div v-else class="space-y-6">
    <!-- En-tête du module -->
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div class="flex items-start gap-4">
        <div
          class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400"
        >
          <AppIcon :name="module?.icon ?? 'layout-grid'" :size="22" />
        </div>
        <div>
          <h2 class="text-xl font-bold tracking-tight">{{ module?.name ?? 'Module' }}</h2>
          <p class="mt-0.5 text-sm text-slate-500">{{ module?.description }}</p>
        </div>
      </div>

      <BaseButton @click="openCreate">
        <AppIcon name="plus" :size="16" />
        Nouvel élément
      </BaseButton>
    </div>

    <!-- Barre d'outils -->
    <div class="flex flex-wrap items-center gap-3">
      <div class="relative min-w-56 flex-1">
        <AppIcon
          name="search"
          :size="16"
          class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"
        />
        <input
          v-model="filters.search"
          type="search"
          placeholder="Rechercher…"
          class="input-field pl-9"
          aria-label="Rechercher un élément"
          @input="onSearchInput"
        />
      </div>

      <select
        v-model="filters.status"
        class="input-field w-auto"
        aria-label="Filtrer par statut"
        @change="applyFilter"
      >
        <option v-for="option in STATUS_FILTERS" :key="option.value" :value="option.value">
          {{ option.label }}
        </option>
      </select>

      <select
        v-model="filters.sort"
        class="input-field w-auto"
        aria-label="Trier les éléments"
        @change="applyFilter"
      >
        <option v-for="option in SORTS" :key="option.value" :value="option.value">
          {{ option.label }}
        </option>
      </select>

      <button
        v-if="hasFilters"
        type="button"
        class="text-sm text-slate-500 underline-offset-2 hover:underline"
        @click="resetFilters"
      >
        Réinitialiser
      </button>
    </div>

    <!-- Liste -->
    <div class="card overflow-hidden">
      <div v-if="loading" class="flex justify-center py-20">
        <BaseSpinner class="size-7 text-brand-600" />
      </div>

      <EmptyState
        v-else-if="!items.length"
        :title="hasFilters ? 'Aucun résultat' : 'Aucun élément'"
        :description="
          hasFilters
            ? 'Aucun élément ne correspond à votre recherche.'
            : 'Créez votre premier élément pour ce module.'
        "
      >
        <BaseButton v-if="hasFilters" variant="secondary" @click="resetFilters">
          Réinitialiser les filtres
        </BaseButton>
        <BaseButton v-else @click="openCreate">
          <AppIcon name="plus" :size="16" />
          Créer un élément
        </BaseButton>
      </EmptyState>

      <ul v-else ref="listRoot" class="divide-y divide-slate-200 dark:divide-slate-800">
        <li
          v-for="item in items"
          :key="item.id"
          data-flip-item
          class="group flex items-start gap-4 px-5 py-4 transition hover:bg-slate-50 dark:hover:bg-slate-800/40"
        >
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
              <p class="truncate font-medium">{{ item.title }}</p>
              <BaseBadge :status="item.status" />
              <span
                v-if="item.data?.priority === 'high'"
                class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-500/15 dark:text-red-400"
              >
                Priorité haute
              </span>
            </div>

            <p v-if="item.description" class="mt-1 line-clamp-2 text-sm text-slate-500">
              {{ item.description }}
            </p>

            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-400">
              <span class="inline-flex items-center gap-1">
                <AppIcon name="clock" :size="13" />
                Modifié {{ formatRelative(item.updated_at) }}
              </span>
              <span v-if="item.due_date" class="inline-flex items-center gap-1">
                <AppIcon name="calendar" :size="13" />
                Échéance {{ formatDate(item.due_date) }}
              </span>
            </div>
          </div>

          <div class="flex shrink-0 items-center gap-1">
            <button
              type="button"
              class="rounded-lg p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200"
              :aria-label="`Modifier ${item.title}`"
              @click="openEdit(item)"
            >
              <AppIcon name="pencil" :size="16" />
            </button>
            <button
              type="button"
              class="rounded-lg p-2 text-slate-400 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10 dark:hover:text-red-400"
              :aria-label="`Supprimer ${item.title}`"
              @click="askDelete(item)"
            >
              <AppIcon name="trash" :size="16" />
            </button>
          </div>
        </li>
      </ul>

      <!-- Pagination -->
      <div
        v-if="meta.total_pages > 1"
        class="flex items-center justify-between border-t border-slate-200 px-5 py-3 dark:border-slate-800"
      >
        <p class="text-sm text-slate-500">
          Page {{ meta.page }} sur {{ meta.total_pages }} · {{ meta.total }} élément(s)
        </p>

        <div class="flex gap-1">
          <BaseButton
            variant="secondary"
            size="sm"
            :disabled="meta.page <= 1"
            @click="goToPage(meta.page - 1)"
          >
            <AppIcon name="chevron-left" :size="15" />
            Précédent
          </BaseButton>
          <BaseButton
            variant="secondary"
            size="sm"
            :disabled="meta.page >= meta.total_pages"
            @click="goToPage(meta.page + 1)"
          >
            Suivant
            <AppIcon name="chevron-right" :size="15" />
          </BaseButton>
        </div>
      </div>
    </div>

    <ItemFormModal
      :open="formOpen"
      :item="editing"
      :submitting="submitting"
      :errors="formErrors"
      @submit="submitForm"
      @close="formOpen = false"
    />

    <ConfirmDialog
      :open="confirmOpen"
      title="Supprimer l'élément"
      :message="`« ${target?.title} » sera retiré de la liste. Cette action est irréversible.`"
      confirm-label="Supprimer"
      :loading="deleting"
      @confirm="confirmDelete"
      @close="confirmOpen = false"
    />
  </div>
</template>
