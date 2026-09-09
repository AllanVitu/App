<script setup>
/**
 * Module « Design » : fichiers et historique de versions.
 *
 * Des cartes plutôt qu'une liste : les fichiers de design se reconnaissent à
 * leur allure avant leur nom, et une vignette colorée retrouve plus vite
 * qu'une ligne de texte.
 *
 * Elles sont rangées en colonnes par TYPE — maquette, prototype, système. Ces
 * colonnes ne portent pas un cycle de vie, contrairement à celles des tickets
 * ou de la supervision : on ne fait pas « avancer » une maquette vers un
 * système de composants. C'est une classification. Le glissement reste
 * néanmoins autorisé, parce qu'il correspond à un geste réel — reclasser un
 * fichier — et que le type est un champ modifiable comme un autre.
 *
 * Une version s'AJOUTE ; elle ne se modifie ni ne se supprime. Pouvoir
 * réécrire une version passée reviendrait à effacer une décision de
 * conception après coup, et l'intérêt du module — voir comment le travail a
 * évolué — disparaîtrait avec elle.
 */
import { computed, nextTick, onMounted, ref } from 'vue'

import AppIcon from '@/components/AppIcon.vue'
import BoardColumns from '@/components/board/BoardColumns.vue'
import DesignCard from '@/components/modules/DesignCard.vue'
import ModuleHeader from '@/components/modules/ModuleHeader.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import FilterChip from '@/components/ui/FilterChip.vue'
import SearchField from '@/components/ui/SearchField.vue'
import TruncationNotice from '@/components/ui/TruncationNotice.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { appEnter, prefersReducedMotion } from '@/animations/motion'
import { createLayout } from '@/animations/layout'
import { designApi } from '@/services/api'
import { play } from '@/services/sound'
import { useWriteQueue } from '@/composables/useWriteQueue'
import { useUnsavedGuard } from '@/composables/useUnsavedGuard'
import { useLoadMore } from '@/composables/useLoadMore'
import { useQuerySync } from '@/composables/useQuerySync'
import { useRevalidate } from '@/composables/useRevalidate'
import { useUiStore } from '@/stores/ui'
import { formatRelative } from '@/utils/format'

const ui = useUiStore()
const { enqueue } = useWriteQueue()

const files = ref([])
const stats = ref(null)
// Compte SERVEUR, filtres compris : il voit au-delà du plafond de chargement,
// contrairement à la liste reçue (cf. ui/TruncationNotice.vue).
const total = ref(null)

// « Charger la suite » : le plafond de chargement reste, mais il cesse d'être
// une impasse (cf. composables/useLoadMore.js).
const { loadingMore, loadMore } = useLoadMore({
  rows: files,
  fetch: async (offset) => (await designApi.list({ offset })).files,
  onError: (message) => ui.notify(message, 'error'),
})
const loading = ref(true)
const busy = ref(false)

const search = ref('')
const kindFilter = ref(null)

// L'écran vit dans son adresse : un filtre posé se partage, se met en favori,
// et le bouton « précédent » le rend au lieu de le perdre.
useQuerySync({ q: search, type: kindFilter })
const openId = ref(null)
const board = ref(null)
const detail = ref(null)
const detailLoading = ref(false)

const composing = ref(false)
const draft = ref({ name: '', kind: 'maquette', description: '', accent: '#7ee2a8' })

// Ni « kind » ni « accent » ne comptent : ils ont une valeur par défaut, et
// ouvrir le formulaire ne constitue pas une saisie.
useUnsavedGuard(() => composing.value && Boolean(draft.value.name || draft.value.description))
const errors = ref({})

const versionLabel = ref('')
const versionNotes = ref('')

const KINDS = [
  { value: 'maquette', label: 'maquette' },
  { value: 'prototype', label: 'prototype' },
  { value: 'systeme', label: 'système' },
]

/** Palette proposée : des teintes qui tiennent sur les deux thèmes. */
const ACCENTS = ['#7ee2a8', '#d8b26a', '#8ab4f8', '#c98a7a', '#b39ddb', '#7ecfd8']

async function load({ silent = false } = {}) {
  if (!silent) loading.value = true

  try {
    const { files: rows, meta } = await designApi.list()

    files.value = rows
    stats.value = meta.stats
    total.value = meta.total ?? null
  } catch (error) {
    ui.notify(error.message, 'error')
  } finally {
    loading.value = false
  }
}

const filtered = computed(() => {
  const needle = search.value.trim().toLowerCase()

  return files.value.filter((file) => {
    if (kindFilter.value && file.kind !== kindFilter.value) return false

    if (!needle) return true

    return (
      file.name.toLowerCase().includes(needle) ||
      (file.description ?? '').toLowerCase().includes(needle)
    )
  })
})

const headerStats = computed(() => {
  if (!stats.value) return []

  const list = [
    { label: 'fichiers', value: stats.value.files, tone: 'neutral' },
    { label: 'versions', value: stats.value.versions, tone: 'neutral' },
  ]

  if (stats.value.updated_this_week > 0) {
    list.push({ label: 'cette semaine', value: stats.value.updated_this_week, tone: 'good' })
  }

  return list
})

/** Le filtre réduit le tableau à une colonne au lieu de vider les autres. */
const visibleColumns = computed(() =>
  kindFilter.value ? KINDS.filter((kind) => kind.value === kindFilter.value) : KINDS,
)

/**
 * Moteur de mise en page : un fichier reclassé GLISSE vers sa nouvelle
 * colonne. Mesuré au moment du changement de type, et pas sur chaque
 * modification de la liste : le faire à chaque frappe dans la recherche
 * forcerait un recalcul de mise en page par caractère.
 */
let layout = null

function recordLayout() {
  const element = board.value?.root

  if (prefersReducedMotion() || !element) return false

  layout ??= createLayout(element, { children: '[role="option"]' })
  layout.record()

  return true
}

/**
 * Reclassement par glissement.
 *
 * Passe par la MÊME file d'écritures que le panneau de détail : on peut
 * glisser une carte puis changer son type dans le panneau plus vite qu'un
 * aller-retour, et chaque réponse contient le fichier entier. Sans file,
 * c'est la réponse la plus lente qui gagnerait, pas le dernier geste.
 */
async function reclassify(file, kind) {
  const index = files.value.findIndex((entry) => entry.id === file.id)

  if (index === -1) return

  const previous = files.value[index]
  const measured = recordLayout()

  files.value[index] = { ...previous, kind }

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
    const updated = await enqueue(file.id, () => designApi.update(file.id, { kind }))

    replaceRow(file.id, updated)

    if (openId.value === file.id) detail.value = updated

    play('tick')
    refresh()
  } catch (error) {
    replaceRow(file.id, previous)
    ui.notify(error.message, 'error')
  }
}

/** L'historique est chargé à l'ouverture : il n'intéresse que le fichier
 *  qu'on regarde, et le charger pour toute la grille serait du gâchis. */
async function open(file) {
  openId.value = file.id
  detail.value = null
  detailLoading.value = true
  play('open')

  try {
    detail.value = await designApi.find(file.id)
  } catch (error) {
    ui.notify(error.message, 'error')
    openId.value = null
  } finally {
    detailLoading.value = false
  }
}

function close() {
  openId.value = null
  detail.value = null
  versionLabel.value = ''
  versionNotes.value = ''
}

async function createFile() {
  errors.value = {}
  busy.value = true

  try {
    const created = await designApi.create({
      ...draft.value,
      description: draft.value.description.trim() || null,
    })

    files.value.unshift(created)
    composing.value = false
    draft.value = { name: '', kind: 'maquette', description: '', accent: '#7ee2a8' }
    play('success')
    load({ silent: true })
  } catch (error) {
    errors.value = error.errors ?? {}

    if (!Object.keys(errors.value).length) ui.notify(error.message, 'error')
  } finally {
    busy.value = false
  }
}

/**
 * Rafraîchissement des COMPTEURS après une écriture — pas de la grille. Un
 * rechargement complet lit un instantané pris avant l'écriture suivante ; s'il
 * revient en dernier, il écrase une donnée plus récente.
 */
async function refresh() {
  try {
    stats.value = (await designApi.list()).meta.stats
  } catch {
    // Un compteur périmé n'est pas une raison d'alerter.
  }
}

/** Place un fichier à jour dans la grille, où qu'il se trouve désormais. */
function replaceRow(id, row) {
  const index = files.value.findIndex((entry) => entry.id === id)

  if (index !== -1) files.value[index] = row
}

/**
 * Enregistrement partiel. L'appel passe par la FILE D'ÉCRITURES : changer le
 * type puis la couleur envoie deux requêtes rapprochées, et chaque réponse
 * contient le fichier ENTIER — celle qui revient en dernier gagne, sans être
 * forcément la dernière partie.
 */
async function patch(changes) {
  const id = openId.value
  const index = files.value.findIndex((entry) => entry.id === id)

  if (index === -1) return

  const previous = files.value[index]

  files.value[index] = { ...previous, ...changes }

  try {
    const updated = await enqueue(id, () => designApi.update(id, changes))

    replaceRow(id, updated)

    // Le panneau ne suit que s'il montre TOUJOURS ce fichier : l'utilisateur
    // a pu en ouvrir un autre pendant l'aller-retour.
    if (openId.value === id) detail.value = updated

    refresh()
  } catch (error) {
    replaceRow(id, previous)
    ui.notify(error.message, 'error')
  }
}

async function addVersion() {
  busy.value = true

  try {
    await designApi.addVersion(openId.value, {
      label: versionLabel.value.trim() || null,
      notes: versionNotes.value.trim() || null,
    })

    // Rechargé plutôt que complété localement : le numéro est attribué par
    // la base, et le déduire côté client le ferait diverger.
    detail.value = await designApi.find(openId.value)
    versionLabel.value = ''
    versionNotes.value = ''
    play('success')
    load({ silent: true })
  } catch (error) {
    ui.notify(error.message, 'error')
  } finally {
    busy.value = false
  }
}

async function removeFile(file) {
  const index = files.value.findIndex((entry) => entry.id === file.id)
  const previous = files.value[index]

  files.value.splice(index, 1)
  close()

  try {
    await designApi.remove(file.id)

    // Suppression logique, donc réversible — cf. TicketsView. L'historique
    // des versions revient avec le fichier : elles ne sont jamais supprimées,
    // seul l'état du fichier les masque.
    ui.notifyUndo(`« ${file.name} » supprimé.`, async () => {
      try {
        await designApi.restore(file.id)
        ui.notify(`« ${file.name} » restauré.`)
      } catch (error) {
        ui.notify(error.message, 'error')
      } finally {
        load({ silent: true })
      }
    })

    load({ silent: true })
  } catch (error) {
    files.value.splice(index, 0, previous)
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
    <ModuleHeader slug="design" title="design" :stats="headerStats">
      <template #filters>
        <SearchField
          v-model="search"
          placeholder="Nom ou description"
          label="Rechercher un fichier"
        />

        <FilterChip :active="kindFilter === null" @click="kindFilter = null"> tous </FilterChip>

        <FilterChip
          v-for="kind in KINDS"
          :key="kind.value"
          :active="kindFilter === kind.value"
          @click="kindFilter = kindFilter === kind.value ? null : kind.value"
        >
          {{ kind.label }}
        </FilterChip>

        <span class="flex-1" />

        <button
          type="button"
          class="chip border-ink bg-ink text-paper transition-opacity hover:opacity-90"
          @click="composing = !composing"
        >
          <AppIcon name="plus" :size="13" />
          nouveau fichier
        </button>
      </template>
    </ModuleHeader>

    <TruncationNotice
      :loading="loadingMore"
      @more="loadMore"
      :loaded="files.length"
      :total="total"
      unit="fichiers"
      hint="Filtrez par type, ou cherchez un nom."
    />

    <form v-if="composing" class="card shrink-0 p-4" @submit.prevent="createFile">
      <div class="grid gap-3 sm:grid-cols-3">
        <label class="block sm:col-span-2">
          <span class="label-caps mb-1.5 block">nom</span>
          <input v-model="draft.name" class="input-field py-2 text-[0.82rem]" />
          <span v-if="errors.name" class="mt-1 block text-[0.7rem] text-brick">
            {{ errors.name }}
          </span>
        </label>

        <label class="block">
          <span class="label-caps mb-1.5 block">type</span>
          <select v-model="draft.kind" class="input-field py-2 text-[0.82rem]">
            <option v-for="kind in KINDS" :key="kind.value" :value="kind.value">
              {{ kind.label }}
            </option>
          </select>
        </label>

        <label class="block sm:col-span-2">
          <span class="label-caps mb-1.5 block">description</span>
          <input v-model="draft.description" class="input-field py-2 text-[0.82rem]" />
        </label>

        <div>
          <span class="label-caps mb-1.5 block">couleur</span>
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="accent in ACCENTS"
              :key="accent"
              type="button"
              class="size-7 rounded-pill border-2 transition-transform hover:scale-110"
              :class="draft.accent === accent ? 'border-ink' : 'border-transparent'"
              :style="{ background: accent }"
              :aria-label="`Couleur ${accent}`"
              :aria-pressed="draft.accent === accent"
              @click="draft.accent = accent"
            />
          </div>
        </div>
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
          créer
        </button>
      </div>
    </form>

    <div v-if="loading" class="flex flex-1 items-center justify-center py-20">
      <BaseSpinner class="size-7 text-ink" />
    </div>

    <div v-else class="flex min-h-0 flex-1 gap-5">
      <!-- Le tableau, en colonnes par TYPE.
           Ici les colonnes ne portent pas un cycle de vie mais une
           CLASSIFICATION : on ne fait pas « avancer » une maquette vers un
           système de composants. Le glissement reste néanmoins autorisé,
           parce qu'il correspond à un geste réel — reclasser un fichier — et
           que le type est un champ modifiable comme un autre. -->
      <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
        <EmptyState
          v-if="filtered.length === 0"
          :title="files.length ? 'Aucun fichier ne correspond' : 'Aucun fichier'"
          description="Une maquette, un prototype ou un système de composants."
        />

        <BoardColumns
          v-else
          ref="board"
          label="Fichiers de design"
          id-prefix="fichier"
          status-key="kind"
          draggable
          :columns="visibleColumns"
          :items="filtered"
          :active-id="openId"
          @select="open"
          @move="reclassify"
        >
          <template #card="{ item }">
            <DesignCard :file="item" />
          </template>
        </BoardColumns>
      </div>

      <!-- Historique -->
      <aside
        v-if="openId"
        class="panel hidden w-88 shrink-0 flex-col overflow-hidden lg:flex"
        aria-label="Historique du fichier"
      >
        <header class="flex shrink-0 items-center gap-2 border-b border-line px-4 py-3">
          <span
            class="size-3 shrink-0 rounded-pill"
            :style="{ background: detail?.accent ?? '#7ee2a8' }"
            aria-hidden="true"
          />
          <span class="min-w-0 flex-1 truncate text-[0.88rem] font-semibold">
            {{ detail?.name ?? '…' }}
          </span>

          <button
            v-if="detail"
            type="button"
            class="rounded-field p-1.5 text-ink-3 transition-colors hover:bg-raised hover:text-brick"
            aria-label="Supprimer le fichier"
            @click="removeFile(detail)"
          >
            <AppIcon name="trash" :size="15" />
          </button>

          <button
            type="button"
            class="rounded-field p-1.5 text-ink-3 transition-colors hover:bg-raised hover:text-ink"
            aria-label="Fermer l'historique"
            @click="close"
          >
            <AppIcon name="close" :size="15" />
          </button>
        </header>

        <div v-if="detailLoading" class="flex flex-1 items-center justify-center">
          <BaseSpinner class="size-6 text-ink-3" />
        </div>

        <div v-else-if="detail" class="flex-1 space-y-5 overflow-y-auto p-4">
          <div>
            <p class="label-caps mb-2">type</p>
            <div class="flex flex-wrap gap-1">
              <FilterChip
                v-for="kind in KINDS"
                :key="kind.value"
                :active="detail.kind === kind.value"
                @click="patch({ kind: kind.value })"
              >
                {{ kind.label }}
              </FilterChip>
            </div>
          </div>

          <div>
            <p class="label-caps mb-2">couleur</p>
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="accent in ACCENTS"
                :key="accent"
                type="button"
                class="size-7 rounded-pill border-2 transition-transform hover:scale-110"
                :class="detail.accent === accent ? 'border-ink' : 'border-transparent'"
                :style="{ background: accent }"
                :aria-label="`Couleur ${accent}`"
                :aria-pressed="detail.accent === accent"
                @click="patch({ accent })"
              />
            </div>
          </div>

          <!-- Nouvelle version -->
          <form class="rounded-card border border-line p-3" @submit.prevent="addVersion">
            <p class="label-caps mb-2">nouvelle version</p>
            <input
              v-model="versionLabel"
              class="input-field py-2 text-[0.8rem]"
              placeholder="Intitulé"
            />
            <textarea
              v-model="versionNotes"
              rows="2"
              class="input-field mt-2 resize-y py-2 text-[0.8rem]"
              placeholder="Ce qui change"
            />
            <button
              type="submit"
              class="chip mt-2 w-full justify-center border-ink bg-ink text-paper transition-opacity hover:opacity-90"
              :disabled="busy"
            >
              enregistrer la version
            </button>
          </form>

          <!-- Historique : le plus récent en haut, numéro attribué par la
               base. -->
          <div>
            <p class="label-caps mb-2">historique</p>
            <ol class="space-y-0">
              <li
                v-for="(version, index) in detail.history"
                :key="version.id"
                class="relative flex gap-3 pb-3 pl-1"
              >
                <!-- Filet vertical : relie les versions entre elles, sauf
                     après la dernière. -->
                <span
                  v-if="index < detail.history.length - 1"
                  class="absolute left-[0.9rem] top-5 h-full w-px bg-line"
                  aria-hidden="true"
                />

                <span
                  class="relative z-10 flex size-6 shrink-0 items-center justify-center rounded-pill border border-line bg-panel font-mono text-[0.62rem] tabular-nums text-ink-2"
                >
                  {{ version.number }}
                </span>

                <span class="min-w-0 flex-1">
                  <span class="block truncate text-[0.8rem]">
                    {{ version.label ?? `Version ${version.number}` }}
                  </span>
                  <span v-if="version.notes" class="mt-0.5 block text-[0.72rem] text-ink-2">
                    {{ version.notes }}
                  </span>
                  <span class="mt-0.5 block text-[0.68rem] text-ink-3">
                    {{ formatRelative(version.created_at) }}
                  </span>
                </span>
              </li>
            </ol>
          </div>
        </div>
      </aside>
    </div>
  </div>
</template>
