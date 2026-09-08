<script setup>
/**
 * Vue générique d'un module.
 *
 * Une seule vue sert les quatre modules : leur structure est identique et le
 * slug de l'URL détermine les données chargées. Ajouter un module en base le
 * rend immédiatement navigable, sans écrire de nouveau composant.
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'

import { appEnter, prefersReducedMotion } from '@/animations/motion'
import { createLayout } from '@/animations/layout'
import { revealOnScroll } from '@/animations/reveal'
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

/** Arrêt de la révélation en cours, à appeler avant chaque rechargement. */
let stopReveal = null

/**
 * Moteur de mise en page de la liste, créé au premier usage.
 *
 * Il mesure les lignes AVANT le changement (record) puis les fait glisser
 * vers leur nouvelle place (animate) : c'est ce que faisait Flip.
 */
let layout = null

const listRoot = ref(null)

/**
 * Révélation des lignes : celles déjà visibles entrent tout de suite, les
 * suivantes à l'approche du défilement.
 */
function revealRows() {
  stopReveal?.()
  stopReveal = listRoot.value
    ? revealOnScroll(listRoot.value.querySelectorAll('[data-flip-item]'))
    : null
}

/**
 * Mesure la liste AVANT sa modification. Renvoie « false » quand il n'y a
 * rien à animer, ce qui dispense l'appelant de retenir la condition.
 */
function recordLayout() {
  if (prefersReducedMotion() || !items.value.length || !listRoot.value) return false

  layout ??= createLayout(listRoot.value, { children: '[data-flip-item]' })
  layout.record()

  return true
}

/**
 * Changement de filtre, de tri ou de page : les lignes conservées GLISSENT
 * vers leur nouvelle place. L'utilisateur suit du regard ce qui a été gardé,
 * au lieu de voir une liste sauter d'un état à l'autre.
 *
 * Celles qui arrivent et celles qui partent ne glissent pas : elles fondent.
 * Leur donner en plus un décalage vertical ferait deux mouvements
 * concurrents sur le même élément — et c'est illisible.
 */
async function replayLayout() {
  await nextTick()

  layout.animate({
    duration: 500,
    ease: appEnter,
    enterFrom: { opacity: 0 },
    leaveTo: { opacity: 0 },
  })
}

async function loadItems({ flip = false } = {}) {
  // La liste doit être mesurée AVANT toute modification du DOM.
  const measured = flip && recordLayout()

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

  if (measured) {
    await replayLayout()
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
  // milieu d'un contenu entièrement renouvelé. Le défilement est confié au
  // navigateur : « scroll-behavior: smooth » est déjà posé sur <html>, et il
  // sait seul l'annuler si l'utilisateur reprend la main.
  const anchor = listRoot.value

  if (anchor) {
    const top = anchor.getBoundingClientRect().top + window.scrollY - 90

    window.scrollTo({ top, behavior: prefersReducedMotion() ? 'auto' : 'smooth' })
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

  // L'observateur d'intersection survivrait à la vue et retiendrait ses
  // lignes en mémoire.
  stopReveal?.()
  stopReveal = null
  layout?.revert()
  layout = null
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
        <div class="flex size-11 shrink-0 items-center justify-center bg-raised text-ink">
          <AppIcon :name="module?.icon ?? 'layout-grid'" :size="22" />
        </div>
        <div>
          <h2 class="text-xl font-bold tracking-tight">{{ module?.name ?? 'Module' }}</h2>
          <p class="mt-0.5 text-sm text-ink-2">{{ module?.description }}</p>
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
          class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-3"
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
        class="text-sm text-ink-2 underline-offset-2 hover:underline"
        @click="resetFilters"
      >
        Réinitialiser
      </button>
    </div>

    <!-- Liste -->
    <div class="card overflow-hidden">
      <div v-if="loading" class="flex justify-center py-20">
        <BaseSpinner class="size-7 text-ink" />
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

      <ul v-else ref="listRoot" class="divide-y divide-line">
        <li
          v-for="item in items"
          :key="item.id"
          data-flip-item
          class="group flex items-start gap-3 px-4 py-2.5 transition-colors hover:bg-raised"
        >
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
              <p class="truncate font-medium">{{ item.title }}</p>
              <BaseBadge :status="item.status" />
              <span v-if="item.data?.priority === 'high'" class="chip border-brick text-brick">
                Priorité haute
              </span>
            </div>

            <p v-if="item.description" class="mt-1 line-clamp-2 text-sm text-ink-2">
              {{ item.description }}
            </p>

            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-3">
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
              class="p-2 text-ink-3 transition-colors hover:bg-raised hover:text-ink"
              :aria-label="`Modifier ${item.title}`"
              @click="openEdit(item)"
            >
              <AppIcon name="pencil" :size="16" />
            </button>
            <button
              type="button"
              class="p-2 text-ink-3 transition-colors hover:bg-brick-bg hover:text-brick"
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
        class="flex items-center justify-between border-t border-line px-4 py-2.5"
      >
        <p class="text-sm text-ink-2">
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
