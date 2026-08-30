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
import { computed, onMounted, ref } from 'vue'

import AppIcon from '@/components/AppIcon.vue'
import ErrorSparkline from '@/components/modules/ErrorSparkline.vue'
import ModuleHeader from '@/components/modules/ModuleHeader.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { errorsApi } from '@/services/api'
import { play } from '@/services/sound'
import { useWriteQueue } from '@/composables/useWriteQueue'
import { useUiStore } from '@/stores/ui'
import { formatRelative } from '@/utils/format'

const ui = useUiStore()
const { enqueue } = useWriteQueue()

const groups = ref([])
const stats = ref(null)
const daily = ref([])
const loading = ref(true)

const search = ref('')
const statusFilter = ref('unresolved')
const openId = ref(null)
const detail = ref(null)
const detailLoading = ref(false)

const LEVELS = {
  fatal: { label: 'fatale', tone: 'text-brick', dot: 'bg-brick' },
  error: { label: 'erreur', tone: 'text-ochre', dot: 'bg-ochre' },
  warning: { label: 'avertissement', tone: 'text-ink-3', dot: 'bg-ink-3' },
}

const STATUSES = [
  { value: 'unresolved', label: 'non résolues' },
  { value: 'resolved', label: 'résolues' },
  { value: 'ignored', label: 'ignorées' },
]

const levelOf = (value) => LEVELS[value] ?? LEVELS.error

async function load({ silent = false } = {}) {
  if (!silent) loading.value = true

  try {
    const { groups: rows, meta } = await errorsApi.list()

    groups.value = rows
    stats.value = meta.stats
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

/**
 * Le détail est chargé À L'OUVERTURE, pas avec la liste : les piles d'appels
 * pèsent lourd et ne servent qu'au groupe qu'on regarde.
 */
async function toggle(group) {
  if (openId.value === group.id) {
    openId.value = null
    detail.value = null

    return
  }

  openId.value = group.id
  detail.value = null
  detailLoading.value = true
  play('open')

  try {
    detail.value = await errorsApi.find(group.id)
  } catch (error) {
    ui.notify(error.message, 'error')
    openId.value = null
  } finally {
    detailLoading.value = false
  }
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

  if (index === -1) return

  const previous = groups.value[index]

  groups.value[index] = { ...previous, status }

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
        <div class="relative min-w-52 flex-1">
          <AppIcon
            name="search"
            :size="14"
            class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-3"
          />
          <input
            v-model="search"
            type="search"
            class="input-field py-2 pl-9 text-[0.82rem]"
            placeholder="Message ou origine"
            aria-label="Rechercher une erreur"
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
          toutes
        </button>

        <button
          v-for="status in STATUSES"
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
          {{ status.label }}
        </button>
      </template>
    </ModuleHeader>

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

      <ul v-else class="flex-1 divide-y divide-line overflow-y-auto">
        <li v-for="group in filtered" :key="group.id">
          <button
            type="button"
            class="flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors hover:bg-raised"
            :aria-expanded="openId === group.id"
            @click="toggle(group)"
          >
            <span
              class="size-2 shrink-0 rounded-pill"
              :class="levelOf(group.level).dot"
              aria-hidden="true"
            />
            <span
              class="hidden w-24 shrink-0 text-[0.72rem] sm:block"
              :class="levelOf(group.level).tone"
            >
              {{ levelOf(group.level).label }}
            </span>

            <span class="min-w-0 flex-1">
              <span
                class="block truncate text-[0.84rem]"
                :class="group.status === 'unresolved' ? 'text-ink' : 'text-ink-3'"
              >
                {{ group.title }}
              </span>
              <span
                v-if="group.culprit"
                class="mt-0.5 block truncate font-mono text-[0.7rem] text-ink-3"
              >
                {{ group.culprit }}
              </span>
            </span>

            <!-- Le nombre d'occurrences EST l'information : « 1 » et
                 « 4 128 » n'appellent pas la même réaction. -->
            <span class="w-16 shrink-0 text-right text-[0.78rem] font-semibold tabular-nums">
              {{ group.occurrences }}
            </span>

            <span class="hidden w-28 shrink-0 text-right text-[0.72rem] text-ink-3 md:block">
              {{ formatRelative(group.last_seen_at) }}
            </span>

            <AppIcon
              name="chevron-down"
              :size="15"
              class="shrink-0 text-ink-3 transition-transform"
              :class="openId === group.id ? 'rotate-180' : ''"
            />
          </button>

          <div v-if="openId === group.id" class="border-t border-line bg-raised/40 px-4 py-3">
            <div class="mb-3 flex flex-wrap items-center gap-2">
              <button
                v-for="status in STATUSES"
                :key="status.value"
                type="button"
                class="chip transition-colors"
                :class="
                  group.status === status.value
                    ? 'border-ink bg-raised text-ink'
                    : 'border-line text-ink-3 hover:border-ink-3 hover:text-ink'
                "
                :aria-pressed="group.status === status.value"
                @click="setStatus(group, status.value)"
              >
                {{ status.label }}
              </button>

              <span class="flex-1" />

              <span class="text-[0.7rem] text-ink-3">
                vue pour la première fois {{ formatRelative(group.first_seen_at) }}
              </span>

              <button
                type="button"
                class="chip border-line text-ink-3 transition-colors hover:border-brick hover:text-brick"
                aria-label="Supprimer ce groupe d'erreurs"
                @click="removeGroup(group)"
              >
                <AppIcon name="trash" :size="13" />
              </button>
            </div>

            <div v-if="detailLoading" class="py-4 text-center">
              <BaseSpinner class="mx-auto size-5 text-ink-3" />
            </div>

            <div v-else-if="detail?.events?.length">
              <p class="label-caps mb-2">
                dernière occurrence · {{ formatRelative(detail.events[0].occurred_at) }}
              </p>
              <pre
                class="max-h-56 overflow-auto whitespace-pre-wrap rounded-field border border-line bg-panel p-3 font-mono text-[0.72rem] leading-relaxed text-ink-2"
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
        </li>
      </ul>
    </div>
  </div>
</template>
