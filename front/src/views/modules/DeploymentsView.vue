<script setup>
/**
 * Module « Déploiement ».
 *
 * Un déploiement est un ÉVÉNEMENT : il a eu lieu, on ne le réécrit pas. On
 * peut le relancer — ce qui le remet en file d'attente et efface sa date de
 * fin, par trigger — ou consulter son journal, mais pas retoucher sa durée
 * ni prétendre qu'il a réussi.
 *
 * La liste est chargée d'un bloc et filtrée localement : les filtres d'un
 * tableau de déploiements se manipulent en rafale, ils ne doivent pas
 * attendre le réseau. Les compteurs, eux, viennent du serveur, qui voit
 * au-delà du plafond de chargement.
 */
import { computed, onMounted, ref } from 'vue'

import AppIcon from '@/components/AppIcon.vue'
import BoardColumns from '@/components/board/BoardColumns.vue'
import BranchPreviews from '@/components/modules/BranchPreviews.vue'
import DeploymentCard from '@/components/modules/DeploymentCard.vue'
import ModuleHeader from '@/components/modules/ModuleHeader.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import FilterChip from '@/components/ui/FilterChip.vue'
import SearchField from '@/components/ui/SearchField.vue'
import TruncationNotice from '@/components/ui/TruncationNotice.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { deploymentsApi } from '@/services/api'
import { play } from '@/services/sound'
import { useLoadMore } from '@/composables/useLoadMore'
import { useQuerySync } from '@/composables/useQuerySync'
import { useRevalidate } from '@/composables/useRevalidate'
import { useUiStore } from '@/stores/ui'
import { formatRelative } from '@/utils/format'

const ui = useUiStore()

const deployments = ref([])
const stats = ref(null)
// Compte SERVEUR, filtres compris : il voit au-delà du plafond de chargement,
// contrairement à la liste reçue (cf. ui/TruncationNotice.vue).
const total = ref(null)

// « Charger la suite » : le plafond de chargement reste, mais il cesse d'être
// une impasse (cf. composables/useLoadMore.js).
const { loadingMore, loadMore } = useLoadMore({
  rows: deployments,
  fetch: async (offset) => (await deploymentsApi.list({ offset })).deployments,
  onError: (message) => ui.notify(message, 'error'),
})
const branches = ref([])
const loading = ref(true)
const busy = ref(false)

const search = ref('')
const envFilter = ref(null)
const statusFilter = ref(null)

// L'écran vit dans son adresse : un filtre posé se partage, se met en favori,
// et le bouton « précédent » le rend au lieu de le perdre.
useQuerySync({ q: search, env: envFilter, statut: statusFilter })
const openId = ref(null)

const composing = ref(false)
const draft = ref({ branch: '', commit_sha: '', commit_message: '', environment: 'preview' })
const errors = ref({})

/** Vocabulaire du module : libellé et ton, définis une fois. */
const STATUSES = [
  { value: 'queued', label: 'en file', tone: 'text-ink-3', dot: 'bg-ink-3' },
  { value: 'building', label: 'en cours', tone: 'text-ochre', dot: 'bg-ochre' },
  { value: 'ready', label: 'en ligne', tone: 'text-moss', dot: 'bg-moss' },
  { value: 'error', label: 'en échec', tone: 'text-brick', dot: 'bg-brick' },
  { value: 'canceled', label: 'annulé', tone: 'text-ink-3', dot: 'bg-ink-3' },
]

const ENVIRONMENTS = [
  { value: 'production', label: 'production' },
  { value: 'preview', label: 'prévisualisation' },
]

const statusOf = (value) => STATUSES.find((s) => s.value === value) ?? STATUSES[0]

async function load({ silent = false } = {}) {
  if (!silent) loading.value = true

  try {
    const { deployments: rows, meta } = await deploymentsApi.list()

    deployments.value = rows
    stats.value = meta.stats
    total.value = meta.total ?? null
    branches.value = meta.branches
  } catch (error) {
    ui.notify(error.message, 'error')
  } finally {
    loading.value = false
  }
}

const filtered = computed(() => {
  const needle = search.value.trim().toLowerCase()

  return deployments.value.filter((row) => {
    if (envFilter.value && row.environment !== envFilter.value) return false
    if (statusFilter.value && row.status !== statusFilter.value) return false

    if (!needle) return true

    return (
      row.branch.toLowerCase().includes(needle) ||
      row.commit_sha.toLowerCase().includes(needle) ||
      (row.commit_message ?? '').toLowerCase().includes(needle)
    )
  })
})

const headerStats = computed(() => {
  if (!stats.value) return []

  const list = [{ label: 'déploiements', value: stats.value.total, tone: 'neutral' }]

  if (stats.value.failed > 0) {
    list.push({ label: 'en échec', value: stats.value.failed, tone: 'alert' })
  }

  if (stats.value.running > 0) {
    list.push({ label: 'en cours', value: stats.value.running, tone: 'neutral' })
  }

  if (stats.value.median_ms > 0) {
    // Médiane et non moyenne : un déploiement anormalement long fausserait
    // durablement une moyenne (cf. DeploymentRepository).
    list.push({ label: 'médiane', value: formatDuration(stats.value.median_ms), tone: 'neutral' })
  }

  return list
})

/** Durée lisible. Au-delà de la minute, les millisecondes ne disent plus rien. */
function formatDuration(ms) {
  if (ms === null || ms === undefined) return '—'
  if (ms < 1000) return `${ms} ms`

  const seconds = Math.round(ms / 1000)

  return seconds < 60 ? `${seconds} s` : `${Math.floor(seconds / 60)} min ${seconds % 60} s`
}

const opened = computed(() => deployments.value.find((row) => row.id === openId.value) ?? null)

/**
 * Colonnes affichées.
 *
 * Le filtre de statut réduit le tableau à une colonne, il ne vide plus les
 * autres : quatre colonnes vides se lisent comme une panne, pas comme un
 * filtre actif.
 */
const visibleColumns = computed(() =>
  statusFilter.value ? STATUSES.filter((status) => status.value === statusFilter.value) : STATUSES,
)

function openDetail(row) {
  openId.value = row.id
  play('open')
}

/**
 * Relance : le statut repart à « en file », et la base efface d'elle-même la
 * date de fin et la durée. Les envoyer depuis ici les ferait diverger.
 */
async function relaunch(row) {
  busy.value = true

  try {
    const updated = await deploymentsApi.update(row.id, { status: 'queued' })
    const index = deployments.value.findIndex((entry) => entry.id === row.id)

    deployments.value[index] = updated
    play('success')
    load({ silent: true })
  } catch (error) {
    ui.notify(error.message, 'error')
  } finally {
    busy.value = false
  }
}

async function createDeployment() {
  errors.value = {}
  busy.value = true

  try {
    const created = await deploymentsApi.create(draft.value)

    deployments.value.unshift(created)
    composing.value = false
    draft.value = { branch: '', commit_sha: '', commit_message: '', environment: 'preview' }
    play('success')
    load({ silent: true })
  } catch (error) {
    // Les erreurs de champ viennent du serveur : le front n'en duplique pas
    // les règles, il se contente de les placer sous les bons champs.
    errors.value = error.errors ?? {}

    if (!Object.keys(errors.value).length) ui.notify(error.message, 'error')
  } finally {
    busy.value = false
  }
}

async function removeDeployment(row) {
  const index = deployments.value.findIndex((entry) => entry.id === row.id)
  const previous = deployments.value[index]

  deployments.value.splice(index, 1)
  openId.value = null

  try {
    await deploymentsApi.remove(row.id)
    load({ silent: true })
  } catch (error) {
    deployments.value.splice(index, 0, previous)
    ui.notify(error.message, 'error')
  }
}

/**
 * Revenu sur l'onglet après une absence : les données ont pu changer
 * ailleurs. On relit SANS indicateur de chargement — remplacer l'écran par un
 * rond qui tourne au retour donnerait l'impression d'avoir tout perdu.
 */
useRevalidate(() => load({ silent: true }))

onMounted(load)
</script>

<template>
  <div class="flex h-full min-h-0 flex-col gap-5">
    <ModuleHeader title="déploiement" :stats="headerStats">
      <template #filters>
        <SearchField
          v-model="search"
          placeholder="Branche, empreinte ou message"
          label="Rechercher un déploiement"
        />

        <FilterChip :active="envFilter === null" @click="envFilter = null"> tous </FilterChip>

        <FilterChip
          v-for="env in ENVIRONMENTS"
          :key="env.value"
          :active="envFilter === env.value"
          @click="envFilter = envFilter === env.value ? null : env.value"
        >
          {{ env.label }}
        </FilterChip>

        <span class="mx-1 h-4 w-px bg-line" aria-hidden="true" />

        <FilterChip
          v-for="status in STATUSES"
          :key="status.value"
          :active="statusFilter === status.value"
          @click="statusFilter = statusFilter === status.value ? null : status.value"
        >
          <span class="size-1.5 rounded-pill" :class="status.dot" aria-hidden="true" />
          {{ status.label }}
        </FilterChip>

        <span class="flex-1" />

        <button
          type="button"
          class="chip border-ink bg-ink text-paper transition-opacity hover:opacity-90"
          @click="composing = !composing"
        >
          <AppIcon name="plus" :size="13" />
          déployer
        </button>
      </template>
    </ModuleHeader>

    <!-- L'état par branche AVANT le tableau : « où est ma branche » est la
         question qu'on se pose en arrivant ; « que s'est-il passé » vient
         ensuite. -->
    <BranchPreviews :deployments="deployments" :statuses="STATUSES" />

    <TruncationNotice
      :loading="loadingMore"
      @more="loadMore"
      :loaded="deployments.length"
      :total="total"
      unit="déploiements"
      hint="Filtrez par environnement, par statut, ou cherchez une branche."
    />

    <!-- Nouveau déploiement -->
    <form v-if="composing" class="card shrink-0 p-4" @submit.prevent="createDeployment">
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <label class="block">
          <span class="label-caps mb-1.5 block">branche</span>
          <input v-model="draft.branch" class="input-field py-2 text-[0.82rem]" list="branches" />
          <datalist id="branches">
            <option v-for="branch in branches" :key="branch" :value="branch" />
          </datalist>
          <span v-if="errors.branch" class="mt-1 block text-[0.7rem] text-brick">
            {{ errors.branch }}
          </span>
        </label>

        <label class="block">
          <span class="label-caps mb-1.5 block">empreinte du commit</span>
          <input
            v-model="draft.commit_sha"
            class="input-field py-2 font-mono text-[0.82rem]"
            placeholder="a3f9c1d"
          />
          <span v-if="errors.commit_sha" class="mt-1 block text-[0.7rem] text-brick">
            {{ errors.commit_sha }}
          </span>
        </label>

        <label class="block">
          <span class="label-caps mb-1.5 block">message</span>
          <input v-model="draft.commit_message" class="input-field py-2 text-[0.82rem]" />
        </label>

        <label class="block">
          <span class="label-caps mb-1.5 block">environnement</span>
          <select v-model="draft.environment" class="input-field py-2 text-[0.82rem]">
            <option v-for="env in ENVIRONMENTS" :key="env.value" :value="env.value">
              {{ env.label }}
            </option>
          </select>
        </label>
      </div>

      <div class="mt-3 flex justify-end gap-2">
        <button
          type="button"
          class="chip border-line text-ink-2 hover:border-ink-3 hover:text-ink"
          @click="composing = false"
        >
          annuler
        </button>
        <button
          type="submit"
          class="chip border-ink bg-ink text-paper transition-opacity hover:opacity-90"
          :disabled="busy"
        >
          lancer
        </button>
      </div>
    </form>

    <div v-if="loading" class="flex flex-1 items-center justify-center py-20">
      <BaseSpinner class="size-7 text-ink" />
    </div>

    <div v-else class="flex min-h-0 flex-1 flex-col overflow-hidden">
      <EmptyState
        v-if="filtered.length === 0"
        class="flex-1"
        :title="deployments.length ? 'Aucun déploiement ne correspond' : 'Aucun déploiement'"
        :description="
          deployments.length
            ? 'Modifiez la recherche ou les filtres.'
            : 'Lancez le premier depuis le bouton « déployer ».'
        "
      />

      <!-- Le tableau. NON glissable, et c'est le point : un déploiement est un
           ÉVÉNEMENT constaté, pas une tâche qu'on fait avancer. Le tirer de
           « en échec » vers « en ligne » réécrirait l'histoire au lieu de la
           corriger. Pour repartir, il y a « relancer » — qui crée un vrai
           nouveau passage en file (cf. BoardColumns). -->
      <div v-else class="flex min-h-0 flex-1 flex-col overflow-hidden p-2">
        <BoardColumns
          label="Déploiements"
          id-prefix="deploiement"
          :columns="visibleColumns"
          :items="filtered"
          @select="openDetail"
        >
          <template #card="{ item }">
            <DeploymentCard :deployment="item" :duration="formatDuration(item.duration_ms)" />
          </template>
        </BoardColumns>
      </div>
    </div>

    <!-- Détail : le journal, et les seules actions légitimes. En fenêtre, car
         un journal de compilation est large par nature. -->
    <BaseModal
      :open="Boolean(opened)"
      :title="opened?.commit_message ?? 'Déploiement'"
      size="lg"
      @close="openId = null"
    >
      <div v-if="opened" class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
          <span class="chip border-line" :class="statusOf(opened.status).tone">
            <span
              class="size-1.5 rounded-pill"
              :class="statusOf(opened.status).dot"
              aria-hidden="true"
            />
            {{ statusOf(opened.status).label }}
          </span>

          <span class="font-mono text-[0.72rem] text-ink-3">
            {{ opened.branch }}@{{ opened.commit_sha.slice(0, 7) }}
          </span>

          <span class="text-[0.72rem] text-ink-3">
            {{ formatDuration(opened.duration_ms) }} · {{ formatRelative(opened.created_at) }}
          </span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <a
            v-if="opened.url"
            :href="opened.url"
            target="_blank"
            rel="noopener noreferrer"
            class="chip border-line text-ink-2 transition-colors hover:border-ink-3 hover:text-ink"
          >
            ouvrir l'URL
          </a>

          <button
            type="button"
            class="chip border-line text-ink-2 transition-colors hover:border-ink-3 hover:text-ink"
            :disabled="busy"
            @click="relaunch(opened)"
          >
            relancer
          </button>

          <span class="flex-1" />

          <button
            type="button"
            class="chip border-line text-ink-3 transition-colors hover:border-brick hover:text-brick"
            @click="removeDeployment(opened)"
          >
            <AppIcon name="trash" :size="13" />
            supprimer
          </button>
        </div>

        <pre
          v-if="opened.log"
          class="max-h-72 overflow-auto whitespace-pre-wrap rounded-field border border-line bg-raised p-3 font-mono text-[0.72rem] leading-relaxed text-ink-2"
          >{{ opened.log }}</pre>
        <p v-else class="text-[0.76rem] text-ink-3">Aucun journal pour ce déploiement.</p>
      </div>
    </BaseModal>
  </div>
</template>
