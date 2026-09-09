<script setup>
/**
 * Module « Supervision » : erreurs de production.
 *
 * L'unité de travail est le GROUPE, pas l'occurrence : mille fois la même
 * exception est un seul problème à corriger. C'est pourquoi la liste montre
 * des groupes avec leur nombre d'occurrences, et non un flux d'événements
 * dans lequel un problème récurrent noierait tous les autres.
 *
 * Rien ne se modifie ici sauf le STATUT de traitement. Une erreur est reçue,
 * pas saisie : un outil de supervision dont on peut retoucher les faits ne
 * supervise plus rien.
 */
import { computed, nextTick, onMounted, ref } from 'vue'

import AppIcon from '@/components/AppIcon.vue'
import BoardColumns from '@/components/board/BoardColumns.vue'
import ErrorCard from '@/components/modules/ErrorCard.vue'
import ErrorSparkline from '@/components/modules/ErrorSparkline.vue'
import IngestGuide from '@/components/modules/IngestGuide.vue'
import ModuleHeader from '@/components/modules/ModuleHeader.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import FilterChip from '@/components/ui/FilterChip.vue'
import SearchField from '@/components/ui/SearchField.vue'
import TruncationNotice from '@/components/ui/TruncationNotice.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { appEnter, prefersReducedMotion } from '@/animations/motion'
import { createLayout } from '@/animations/layout'
import { errorsApi } from '@/services/api'
import { play } from '@/services/sound'
import { useWriteQueue } from '@/composables/useWriteQueue'
import { useLoadMore } from '@/composables/useLoadMore'
import { useUiStore } from '@/stores/ui'
import { formatRelative } from '@/utils/format'

const ui = useUiStore()
const { enqueue } = useWriteQueue()

const groups = ref([])
const stats = ref(null)
// Compte SERVEUR, filtres compris : il voit au-delà du plafond de chargement,
// contrairement à la liste reçue (cf. ui/TruncationNotice.vue).
const total = ref(null)

// « Charger la suite » : le plafond de chargement reste, mais il cesse d'être
// une impasse (cf. composables/useLoadMore.js).
const { loadingMore, loadMore } = useLoadMore({
  rows: groups,
  fetch: async (offset) => (await errorsApi.list({ offset })).groups,
  onError: (message) => ui.notify(message, 'error'),
})
const daily = ref([])
const loading = ref(true)

const search = ref('')
// Aucun filtre par défaut, désormais : le tableau sépare LUI-MÊME les statuts.
// Démarrer sur « non résolues » masquerait deux colonnes sur trois, et donc le
// travail déjà fait — dont la vue est la moitié de l'intérêt d'un tableau.
const statusFilter = ref(null)
const openId = ref(null)
const detail = ref(null)
const detailLoading = ref(false)
const board = ref(null)
// Le mode d’emploi de l’ingestion : ouvert à la demande, pas en permanence.
const guideOpen = ref(false)

const LEVELS = {
  fatal: { label: 'fatale', tone: 'text-brick', dot: 'bg-brick' },
  error: { label: 'erreur', tone: 'text-ochre', dot: 'bg-ochre' },
  warning: { label: 'avertissement', tone: 'text-ink-3', dot: 'bg-ink-3' },
}

/** Colonnes, de gauche à droite : ce qui reste à traiter d'abord. */
const STATUSES = [
  { value: 'unresolved', label: 'non résolues', tone: 'text-ink' },
  { value: 'resolved', label: 'résolues', tone: 'text-moss' },
  { value: 'ignored', label: 'ignorées', tone: 'text-ink-3' },
]

const levelOf = (value) => LEVELS[value] ?? LEVELS.error

/** Le filtre réduit le tableau à une colonne au lieu de vider les autres. */
const visibleColumns = computed(() =>
  statusFilter.value ? STATUSES.filter((status) => status.value === statusFilter.value) : STATUSES,
)

/** Moteur de mise en page : une erreur triée GLISSE vers sa nouvelle colonne. */
let layout = null

function recordLayout() {
  const element = board.value?.root

  if (prefersReducedMotion() || !element) return false

  layout ??= createLayout(element, { children: '[role="option"]' })
  layout.record()

  return true
}

async function load({ silent = false } = {}) {
  if (!silent) loading.value = true

  try {
    const { groups: rows, meta } = await errorsApi.list()

    groups.value = rows
    stats.value = meta.stats
    total.value = meta.total ?? null
    daily.value = meta.daily
  } catch (error) {
    ui.notify(error.message, 'error')
  } finally {
    loading.value = false
  }
}

const filtered = computed(() => {
  const needle = search.value.trim().toLowerCase()

  return groups.value.filter((group) => {
    if (statusFilter.value && group.status !== statusFilter.value) return false

    if (!needle) return true

    return (
      group.title.toLowerCase().includes(needle) ||
      (group.culprit ?? '').toLowerCase().includes(needle)
    )
  })
})

const headerStats = computed(() => {
  if (!stats.value) return []

  const list = [{ label: 'non résolues', value: stats.value.unresolved, tone: 'neutral' }]

  if (stats.value.fatal > 0) {
    list.push({ label: 'fatales', value: stats.value.fatal, tone: 'alert' })
  }

  list.push({ label: 'sur 24 h', value: stats.value.events_24h, tone: 'neutral' })

  if (stats.value.resolved > 0) {
    list.push({ label: 'résolues', value: stats.value.resolved, tone: 'good' })
  }

  return list
})

/** Groupe ouvert dans la fenêtre de détail — relu dans la liste vivante, pour
 *  que le changement de statut fait depuis la fenêtre s'y reflète. */
const openGroup = computed(() => groups.value.find((entry) => entry.id === openId.value) ?? null)

/**
 * Le détail est chargé À L'OUVERTURE, pas avec la liste : les piles d'appels
 * pèsent lourd et ne servent qu'au groupe qu'on regarde.
 */
async function openDetail(group) {
  openId.value = group.id
  detail.value = null
  detailLoading.value = true

  try {
    detail.value = await errorsApi.find(group.id)
  } catch (error) {
    ui.notify(error.message, 'error')
    openId.value = null
  } finally {
    detailLoading.value = false
  }
}

function closeDetail() {
  openId.value = null
  detail.value = null
}

/** Place un groupe à jour dans la liste, où qu'il se trouve désormais. */
function replaceRow(id, row) {
  const index = groups.value.findIndex((entry) => entry.id === id)

  if (index !== -1) groups.value[index] = row
}

/**
 * Rafraîchissement des COMPTEURS après une écriture — pas des lignes. Un
 * rechargement complet lit un instantané pris avant l'écriture suivante ; s'il
 * revient en dernier, il écrase une donnée plus récente.
 */
async function refresh() {
  try {
    const { meta } = await errorsApi.list()

    stats.value = meta.stats
    daily.value = meta.daily
  } catch {
    // Un compteur périmé n'est pas une raison d'alerter.
  }
}

/**
 * L'appel passe par la FILE D'ÉCRITURES : les trois statuts sont côte à côte,
 * on peut en enchaîner deux plus vite qu'un aller-retour. Sans file, c'est la
 * réponse la plus lente qui l'emporterait, et non le dernier clic.
 */
async function setStatus(group, status) {
  const index = groups.value.findIndex((entry) => entry.id === group.id)

  // Reposée dans sa propre colonne : rien n'a changé, on n'écrit rien.
  if (index === -1 || groups.value[index].status === status) return

  const previous = groups.value[index]

  // Mesuré AVANT l'écriture : la carte doit glisser vers sa nouvelle colonne,
  // pas disparaître ici pour reparaître là.
  const measured = recordLayout()

  groups.value[index] = { ...previous, status }

  if (measured) {
    await nextTick()

    layout.animate({
      duration: 420,
      ease: appEnter,
      enterFrom: { opacity: 0 },
      leaveTo: { opacity: 0 },
    })
  }

  try {
    replaceRow(group.id, await enqueue(group.id, () => errorsApi.setStatus(group.id, status)))
    play('tick')
    refresh()
  } catch (error) {
    replaceRow(group.id, previous)
    ui.notify(error.message, 'error')
  }
}

async function removeGroup(group) {
  const index = groups.value.findIndex((entry) => entry.id === group.id)
  const previous = groups.value[index]

  groups.value.splice(index, 1)
  openId.value = null

  try {
    await errorsApi.remove(group.id)
    load({ silent: true })
  } catch (error) {
    groups.value.splice(index, 0, previous)
    ui.notify(error.message, 'error')
  }
}

onMounted(load)
</script>

<template>
  <div class="flex h-full min-h-0 flex-col gap-5">
    <ModuleHeader title="supervision" :stats="headerStats">
      <template #aside>
        <!-- La courbe répond à une question que les compteurs ne traitent
             pas : est-ce que ça empire ? -->
        <div v-if="daily.length" class="w-full sm:w-auto">
          <p class="label-caps mb-1">occurrences · 14 jours</p>
          <ErrorSparkline :points="daily" />
        </div>
      </template>

      <template #filters>
        <SearchField
          v-model="search"
          placeholder="Message ou origine"
          label="Rechercher une erreur"
        />

        <FilterChip :active="statusFilter === null" @click="statusFilter = null">
          toutes
        </FilterChip>

        <FilterChip
          v-for="status in STATUSES"
          :key="status.value"
          :active="statusFilter === status.value"
          @click="statusFilter = statusFilter === status.value ? null : status.value"
        >
          {{ status.label }}
        </FilterChip>

        <span class="flex-1" />

        <!-- La porte d'entrée du module. Elle n'existait nulle part : on
             pouvait consulter des erreurs sans jamais savoir comment en
             envoyer. -->
        <button
          type="button"
          class="chip border-line text-ink-2 transition-colors hover:border-ink-3 hover:text-ink"
          @click="guideOpen = true"
        >
          <AppIcon name="plus" :size="13" />
          recevoir des erreurs
        </button>
      </template>
    </ModuleHeader>

    <TruncationNotice
      :loading="loadingMore"
      @more="loadMore"
      :loaded="groups.length"
      :total="total"
      unit="groupes d'erreurs"
      hint="Filtrez par statut de traitement, ou cherchez un message."
    />

    <div v-if="loading" class="flex flex-1 items-center justify-center py-20">
      <BaseSpinner class="size-7 text-ink" />
    </div>

    <div v-else class="card flex min-h-0 flex-1 flex-col overflow-hidden">
      <EmptyState
        v-if="filtered.length === 0"
        class="flex-1"
        :title="groups.length ? 'Aucune erreur ne correspond' : 'Aucune erreur'"
        :description="
          groups.length
            ? 'Modifiez la recherche ou le filtre de statut.'
            : 'La production ne remonte rien : c\'est la bonne nouvelle.'
        "
      />

      <!-- Le tableau. Glissable : changer le statut de traitement d'une erreur
           est un geste légitime, contrairement au déploiement où le statut est
           un FAIT constaté (cf. BoardColumns). -->
      <div v-else class="flex min-h-0 flex-1 flex-col overflow-hidden p-2">
        <BoardColumns
          ref="board"
          label="Groupes d'erreurs"
          id-prefix="erreur"
          draggable
          :columns="visibleColumns"
          :items="filtered"
          @select="openDetail"
          @move="setStatus"
        >
          <template #card="{ item }">
            <ErrorCard :group="item" />
          </template>
        </BoardColumns>
      </div>
    </div>

    <BaseModal :open="guideOpen" title="recevoir des erreurs" size="lg" @close="guideOpen = false">
      <IngestGuide />
    </BaseModal>

    <!-- Détail : en fenêtre et non déplié dans la colonne. Une pile d'appels
         est large par nature ; l'afficher dans une colonne de 13 rem la
         réduirait à une bouillie de retours à la ligne. -->
    <BaseModal
      :open="Boolean(openGroup)"
      :title="openGroup?.title ?? ''"
      size="lg"
      @close="closeDetail"
    >
      <div v-if="openGroup" class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
          <span class="chip border-line" :class="levelOf(openGroup.level).tone">
            <span
              class="size-1.5 rounded-pill"
              :class="levelOf(openGroup.level).dot"
              aria-hidden="true"
            />
            {{ levelOf(openGroup.level).label }}
          </span>

          <span v-if="openGroup.culprit" class="font-mono text-[0.72rem] text-ink-3">
            {{ openGroup.culprit }}
          </span>

          <span class="flex-1" />

          <span class="text-[0.7rem] text-ink-3">
            {{ openGroup.occurrences }} occurrences · vue pour la première fois
            {{ formatRelative(openGroup.first_seen_at) }}
          </span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <FilterChip
            v-for="status in STATUSES"
            :key="status.value"
            :active="openGroup.status === status.value"
            @click="setStatus(openGroup, status.value)"
          >
            {{ status.label }}
          </FilterChip>

          <span class="flex-1" />

          <button
            type="button"
            class="chip border-line text-ink-3 transition-colors hover:border-brick hover:text-brick"
            aria-label="Supprimer ce groupe d'erreurs"
            @click="removeGroup(openGroup)"
          >
            <AppIcon name="trash" :size="13" />
          </button>
        </div>

        <div v-if="detailLoading" class="py-6 text-center">
          <BaseSpinner class="mx-auto size-5 text-ink-3" />
        </div>

        <div v-else-if="detail?.events?.length">
          <p class="label-caps mb-2">
            dernière occurrence · {{ formatRelative(detail.events[0].occurred_at) }}
          </p>
          <pre
            class="max-h-72 overflow-auto whitespace-pre-wrap rounded-field border border-line bg-raised p-3 font-mono text-[0.72rem] leading-relaxed text-ink-2"
            >{{ detail.events[0].stack ?? detail.events[0].message }}</pre>

          <p v-if="detail.events.length > 1" class="mt-2 text-[0.7rem] text-ink-3">
            {{ detail.events.length - 1 }} autre{{
              detail.events.length > 2 ? 's' : ''
            }}
            occurrence{{ detail.events.length > 2 ? 's' : '' }} récente{{
              detail.events.length > 2 ? 's' : ''
            }}.
          </p>
        </div>

        <p v-else class="text-[0.76rem] text-ink-3">Aucune occurrence enregistrée.</p>
      </div>
    </BaseModal>
  </div>
</template>
