<script setup>
/**
 * Module « Backend » : schémas de données et clés d'API.
 *
 * Deux ressources aux règles opposées, réunies sur un écran :
 *
 *  — un SCHÉMA se conçoit et se remanie librement ;
 *  — une CLÉ se crée puis se révoque, jamais ne se modifie. Son secret a
 *    déjà été distribué ; laisser croire qu'on peut en changer la portée
 *    après coup serait un piège.
 *
 * La clé en clair n'est affichée QU'UNE FOIS, à sa création. Le serveur n'en
 * garde que l'empreinte : elle n'est pas récupérable, et l'écran doit le dire
 * plutôt que de laisser l'utilisateur la perdre.
 */
import { computed, onMounted, ref } from 'vue'

import AppIcon from '@/components/AppIcon.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import ModuleHeader from '@/components/modules/ModuleHeader.vue'
import TableEndpoints from '@/components/modules/TableEndpoints.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SearchField from '@/components/ui/SearchField.vue'
import { backendApi } from '@/services/api'
import { play } from '@/services/sound'
import { useWriteQueue } from '@/composables/useWriteQueue'
import { useUiStore } from '@/stores/ui'
import { modulePath } from '@/utils/modules'
import { formatRelative } from '@/utils/format'

const ui = useUiStore()
const { enqueue } = useWriteQueue()

const tables = ref([])
const keys = ref([])
const stats = ref(null)
const types = ref([])
const loading = ref(true)
const busy = ref(false)

const search = ref('')
const tab = ref('tables')
const openId = ref(null)
const errors = ref({})

const composing = ref(false)
const draftName = ref('')

const keyLabel = ref('')
const keyScope = ref('anon')
/** Jeton fraîchement créé : affiché une fois, puis oublié. */
const freshToken = ref(null)

async function load({ silent = false } = {}) {
  if (!silent) loading.value = true

  try {
    const { tables: rows, meta } = await backendApi.list()

    tables.value = rows
    keys.value = meta.keys
    stats.value = meta.stats
    types.value = meta.types
  } catch (error) {
    ui.notify(error.message, 'error')
  } finally {
    loading.value = false
  }
}

/**
 * Rafraîchissement des COMPTEURS et des clés après une écriture — pas des
 * lignes. Un rechargement complet lit un instantané pris avant l'écriture
 * suivante ; s'il revient en dernier, il écrase une donnée plus récente. Les
 * lignes n'en ont pas besoin : chaque écriture renvoie déjà sa version.
 */
async function refresh() {
  try {
    const { meta } = await backendApi.list()

    keys.value = meta.keys
    stats.value = meta.stats
  } catch {
    // Un compteur périmé n'est pas une raison d'alerter : l'écriture, elle,
    // a bien abouti.
  }
}

const filtered = computed(() => {
  const needle = search.value.trim().toLowerCase()

  if (!needle) return tables.value

  return tables.value.filter(
    (table) =>
      table.name.toLowerCase().includes(needle) ||
      (table.description ?? '').toLowerCase().includes(needle),
  )
})

const headerStats = computed(() => {
  if (!stats.value) return []

  const list = [
    { label: 'tables', value: stats.value.tables, tone: 'neutral' },
    { label: 'colonnes', value: stats.value.columns, tone: 'neutral' },
  ]

  // Le seul chiffre du module qui décrit un risque plutôt qu'un volume.
  if (stats.value.unprotected > 0) {
    list.push({ label: 'sans RLS', value: stats.value.unprotected, tone: 'alert' })
  }

  list.push({ label: 'clés actives', value: stats.value.active_keys, tone: 'neutral' })

  return list
})

const opened = computed(() => tables.value.find((table) => table.id === openId.value) ?? null)

function toggle(table) {
  openId.value = openId.value === table.id ? null : table.id
  if (openId.value) play('open')
}

async function createTable() {
  errors.value = {}
  busy.value = true

  try {
    const created = await backendApi.create({
      name: draftName.value.trim(),
      columns: [{ name: 'id', type: 'uuid', nullable: false }],
    })

    tables.value.unshift(created)
    openId.value = created.id
    draftName.value = ''
    composing.value = false
    play('success')
    load({ silent: true })
  } catch (error) {
    errors.value = error.errors ?? {}

    if (!Object.keys(errors.value).length) ui.notify(error.message, 'error')
  } finally {
    busy.value = false
  }
}

/** Place une table à jour dans la liste, où qu'elle se trouve désormais. */
function replaceRow(id, row) {
  const index = tables.value.findIndex((entry) => entry.id === id)

  if (index !== -1) tables.value[index] = row
}

/**
 * Enregistrement partiel : seuls les champs modifiés partent.
 *
 * L'appel passe par la FILE D'ÉCRITURES (cf. useWriteQueue). Ajouter une
 * colonne puis en renommer une autre envoie deux requêtes rapprochées ; chaque
 * réponse contenant la table ENTIÈRE, celle qui revient en dernier gagne — et
 * ce n'est pas forcément la dernière partie. La colonne ajoutée disparaîtrait
 * alors de l'écran, tout en restant en base.
 */
async function patch(table, changes) {
  const index = tables.value.findIndex((entry) => entry.id === table.id)

  if (index === -1) return

  const previous = tables.value[index]

  tables.value[index] = { ...previous, ...changes }

  try {
    replaceRow(table.id, await enqueue(table.id, () => backendApi.update(table.id, changes)))
    refresh()
  } catch (error) {
    replaceRow(table.id, previous)
    ui.notify(error.errors?.columns ?? error.errors?.name ?? error.message, 'error')
  }
}

function addColumn(table) {
  patch(table, {
    columns: [
      ...table.columns,
      { name: `colonne_${table.columns.length + 1}`, type: 'text', nullable: true },
    ],
  })
}

function updateColumn(table, index, changes) {
  const columns = table.columns.map((column, i) =>
    i === index ? { ...column, ...changes } : column,
  )

  patch(table, { columns })
}

function removeColumn(table, index) {
  patch(table, { columns: table.columns.filter((_, i) => i !== index) })
}

/**
 * Table à supprimer, en attente de confirmation.
 *
 * La confirmation est NOUVELLE, et elle est due : supprimer une table
 * détruisait jusqu'ici une description. Elle détruit désormais une VRAIE table
 * PostgreSQL et tout ce qu'elle contient — un geste irréversible mérite qu'on
 * s'arrête une seconde.
 */
const toRemove = ref(null)

async function removeTable(table) {
  toRemove.value = null

  const index = tables.value.findIndex((entry) => entry.id === table.id)
  const previous = tables.value[index]

  tables.value.splice(index, 1)
  openId.value = null

  try {
    await backendApi.remove(table.id)
    load({ silent: true })
  } catch (error) {
    tables.value.splice(index, 0, previous)
    ui.notify(error.message, 'error')
  }
}

async function createKey() {
  busy.value = true

  try {
    const created = await backendApi.createKey({
      label: keyLabel.value.trim(),
      scope: keyScope.value,
    })

    freshToken.value = created.token
    keys.value.unshift(created.key)
    keyLabel.value = ''
    play('success')
    load({ silent: true })
  } catch (error) {
    ui.notify(error.errors?.label ?? error.message, 'error')
  } finally {
    busy.value = false
  }
}

async function revokeKey(key) {
  try {
    await backendApi.revokeKey(key.id)
    await load({ silent: true })
    ui.notify(`Clé « ${key.label} » révoquée.`, 'info')
  } catch (error) {
    ui.notify(error.message, 'error')
  }
}

async function copyToken() {
  try {
    await navigator.clipboard.writeText(freshToken.value)
    ui.notify('Clé copiée dans le presse-papiers.', 'success')
  } catch {
    // Le presse-papiers peut être refusé (contexte non sécurisé, permission) :
    // la clé reste affichée, elle est sélectionnable à la main.
    ui.notify('Copie impossible : sélectionnez la clé pour la copier.', 'error')
  }
}

onMounted(load)
</script>

<template>
  <div class="flex h-full min-h-0 flex-col gap-5">
    <ModuleHeader title="backend" :stats="headerStats">
      <template #filters>
        <div class="flex items-center gap-0.5 rounded-pill border border-line bg-panel p-0.5">
          <button
            v-for="option in [
              { value: 'tables', label: 'schémas' },
              { value: 'keys', label: `clés (${keys.length})` },
            ]"
            :key="option.value"
            type="button"
            class="rounded-pill px-3 py-1 text-[0.75rem] transition-colors"
            :class="
              tab === option.value
                ? 'bg-ink text-paper'
                : 'text-ink-2 hover:bg-raised hover:text-ink'
            "
            :aria-pressed="tab === option.value"
            @click="tab = option.value"
          >
            {{ option.label }}
          </button>
        </div>

        <SearchField
          v-if="tab === 'tables'"
          v-model="search"
          placeholder="Nom ou description"
          label="Rechercher une table"
        />

        <span class="flex-1" />

        <button
          v-if="tab === 'tables'"
          type="button"
          class="chip border-ink bg-ink text-paper transition-opacity hover:opacity-90"
          @click="composing = !composing"
        >
          <AppIcon name="plus" :size="13" />
          nouvelle table
        </button>
      </template>
    </ModuleHeader>

    <div v-if="loading" class="flex flex-1 items-center justify-center py-20">
      <BaseSpinner class="size-7 text-ink" />
    </div>

    <!-- ============================ SCHÉMAS ============================ -->
    <template v-else-if="tab === 'tables'">
      <form v-if="composing" class="card shrink-0 p-4" @submit.prevent="createTable">
        <label class="block">
          <span class="label-caps mb-1.5 block">nom de la table</span>
          <input
            v-model="draftName"
            class="input-field py-2 font-mono text-[0.82rem]"
            placeholder="commandes"
          />
          <span class="mt-1 block text-[0.7rem] text-ink-3">
            Minuscules, chiffres et « _ ». La colonne « id » est créée avec la table.
          </span>
          <span v-if="errors.name" class="mt-1 block text-[0.7rem] text-brick">
            {{ errors.name }}
          </span>
        </label>

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

      <div class="card flex min-h-0 flex-1 flex-col overflow-hidden">
        <EmptyState
          v-if="filtered.length === 0"
          class="flex-1"
          :title="tables.length ? 'Aucune table ne correspond' : 'Aucune table'"
          description="Un schéma décrit vos données : nom, colonnes, et qui peut les lire."
        />

        <ul v-else class="flex-1 divide-y divide-line overflow-y-auto">
          <li v-for="table in filtered" :key="table.id">
            <button
              type="button"
              class="flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors hover:bg-raised"
              :aria-expanded="openId === table.id"
              @click="toggle(table)"
            >
              <AppIcon name="database" :size="15" class="shrink-0 text-ink-3" />

              <span class="w-40 shrink-0 truncate font-mono text-[0.82rem]">{{ table.name }}</span>

              <span class="min-w-0 flex-1 truncate text-[0.78rem] text-ink-2">
                {{ table.description ?? '—' }}
              </span>

              <span class="w-20 shrink-0 text-right text-[0.72rem] tabular-nums text-ink-3">
                {{ table.columns.length }} col.
              </span>

              <!-- L'absence de RLS est un risque : elle se voit dans la
                   liste, pas seulement en ouvrant la table. -->
              <span
                class="w-24 shrink-0 text-right text-[0.7rem]"
                :class="table.rls_enabled ? 'text-ink-3' : 'text-brick'"
              >
                {{ table.rls_enabled ? 'RLS actif' : 'sans RLS' }}
              </span>

              <AppIcon
                name="chevron-down"
                :size="15"
                class="shrink-0 text-ink-3 transition-transform"
                :class="openId === table.id ? 'rotate-180' : ''"
              />
            </button>

            <div
              v-if="openId === table.id && opened"
              class="border-t border-line bg-raised/40 px-4 py-3"
            >
              <div class="mb-3 flex flex-wrap items-center gap-2">
                <button
                  type="button"
                  class="chip transition-colors"
                  :class="opened.rls_enabled ? 'border-moss text-moss' : 'border-brick text-brick'"
                  :aria-pressed="opened.rls_enabled"
                  @click="patch(opened, { rls_enabled: !opened.rls_enabled })"
                >
                  <AppIcon :name="opened.rls_enabled ? 'shield' : 'alert'" :size="13" />
                  sécurité au niveau ligne {{ opened.rls_enabled ? 'activée' : 'désactivée' }}
                </button>

                <span class="text-[0.72rem] tabular-nums text-ink-3">
                  ~{{ opened.row_estimate.toLocaleString('fr-FR') }} lignes
                </span>

                <span class="flex-1" />

                <button
                  type="button"
                  class="chip border-line text-ink-2 transition-colors hover:border-ink-3 hover:text-ink"
                  @click="addColumn(opened)"
                >
                  <AppIcon name="plus" :size="13" />
                  colonne
                </button>

                <button
                  type="button"
                  class="chip border-line text-ink-3 transition-colors hover:border-brick hover:text-brick"
                  :aria-label="`Supprimer la table ${opened.name}`"
                  @click="toRemove = opened"
                >
                  <AppIcon name="trash" :size="13" />
                </button>
              </div>

              <!-- Colonnes : éditables en place. L'ordre est celui du
                   schéma, il porte du sens. -->
              <ul class="divide-y divide-line rounded-field border border-line bg-panel">
                <li
                  v-for="(column, index) in opened.columns"
                  :key="index"
                  class="flex flex-wrap items-center gap-2 px-3 py-2"
                >
                  <input
                    :value="column.name"
                    class="w-44 rounded-field border border-transparent bg-transparent px-2 py-1 font-mono text-[0.78rem] text-ink transition-colors hover:border-line focus:border-ink-3 focus:outline-none"
                    :aria-label="`Nom de la colonne ${index + 1}`"
                    @change="updateColumn(opened, index, { name: $event.target.value })"
                  />

                  <select
                    :value="column.type"
                    class="rounded-field border border-line bg-raised px-2 py-1 text-[0.76rem] text-ink-2 focus:border-ink-3 focus:outline-none"
                    :aria-label="`Type de la colonne ${index + 1}`"
                    @change="updateColumn(opened, index, { type: $event.target.value })"
                  >
                    <option v-for="type in types" :key="type" :value="type">{{ type }}</option>
                  </select>

                  <button
                    type="button"
                    class="chip border-line text-[0.7rem] transition-colors"
                    :class="column.nullable ? 'text-ink-3' : 'text-ink'"
                    :aria-pressed="!column.nullable"
                    @click="updateColumn(opened, index, { nullable: !column.nullable })"
                  >
                    {{ column.nullable ? 'nullable' : 'obligatoire' }}
                  </button>

                  <span class="flex-1" />

                  <button
                    type="button"
                    class="rounded-field p-1 text-ink-3 transition-colors hover:text-brick"
                    :aria-label="`Supprimer la colonne ${column.name}`"
                    @click="removeColumn(opened, index)"
                  >
                    <AppIcon name="close" :size="13" />
                  </button>
                </li>
              </ul>

              <!-- Les adresses de la table. Sans elles, personne ne saurait
                   que ce schéma est devenu une vraie table interrogeable. -->
              <div class="mt-3">
                <TableEndpoints :name="opened.name" :columns="opened.columns" />
              </div>
            </div>
          </li>
        </ul>
      </div>
    </template>

    <!-- ============================== CLÉS ============================== -->
    <template v-else>
      <!-- Le jeton fraîchement créé. Encadré, explicite : il ne réapparaîtra
           pas, et l'utilisateur doit le savoir AVANT de fermer. -->
      <div v-if="freshToken" class="card shrink-0 border-moss p-4">
        <div class="flex items-start gap-3">
          <AppIcon name="lock" :size="16" class="mt-0.5 shrink-0 text-moss" />
          <div class="min-w-0 flex-1">
            <p class="text-[0.84rem] font-semibold">Copiez cette clé maintenant</p>
            <p class="mt-0.5 text-[0.76rem] text-ink-2">
              Le serveur n'en conserve que l'empreinte : elle ne sera plus jamais affichée.
            </p>
            <code
              class="mt-2 block select-all overflow-x-auto rounded-field border border-line bg-raised px-3 py-2 font-mono text-[0.76rem] text-ink"
            >
              {{ freshToken }}
            </code>
          </div>
        </div>

        <div class="mt-3 flex justify-end gap-2">
          <button
            type="button"
            class="chip border-line text-ink-2 hover:border-ink-3 hover:text-ink"
            @click="copyToken"
          >
            copier
          </button>
          <button
            type="button"
            class="chip border-ink bg-ink text-paper transition-opacity hover:opacity-90"
            @click="freshToken = null"
          >
            j'ai noté la clé
          </button>
        </div>
      </div>

      <form class="card shrink-0 p-4" @submit.prevent="createKey">
        <div class="flex flex-wrap items-end gap-3">
          <label class="min-w-52 flex-1">
            <span class="label-caps mb-1.5 block">nom de la clé</span>
            <input
              v-model="keyLabel"
              class="input-field py-2 text-[0.82rem]"
              placeholder="Client web, tâches planifiées…"
            />
          </label>

          <label class="block">
            <span class="label-caps mb-1.5 block">portée</span>
            <select v-model="keyScope" class="input-field py-2 text-[0.82rem]">
              <option value="anon">publique (anon)</option>
              <option value="service">service</option>
            </select>
            <!-- La distinction n'était nulle part expliquée, alors qu'elle
                 décide de ce que la clé peut faire. -->
            <span class="mt-1.5 block text-[0.7rem] text-ink-3">
              {{
                keyScope === 'service'
                  ? 'Écriture autorisée. À garder côté serveur.'
                  : 'Lecture seule. Destinée au navigateur, donc publique.'
              }}
            </span>
          </label>

          <button
            type="submit"
            class="chip border-ink bg-ink text-paper transition-opacity hover:opacity-90"
            :disabled="busy || !keyLabel.trim()"
          >
            <AppIcon name="plus" :size="13" />
            créer la clé
          </button>
        </div>
      </form>

      <!-- CE QU'UNE CLÉ OUVRE, dit ici.
           Les clés se créaient, s'affichaient une fois et se révoquaient sans
           que rien n'indique jamais à quoi elles servaient. Elles authentifient
           l'ingestion d'erreurs du module Supervision — la seule chose qu'un
           serveur puisse faire sans session. -->
      <p
        class="flex flex-wrap items-center gap-2 rounded-field border border-line bg-raised px-3 py-2 text-[0.78rem] text-ink-2"
      >
        <AppIcon name="info" :size="14" class="shrink-0 text-ink-3" />
        Une clé <strong class="font-semibold text-ink">de service</strong> autorise vos applications
        à signaler leurs erreurs.
        <RouterLink
          :to="modulePath('supervision')"
          class="font-medium text-ink underline underline-offset-2 transition-colors hover:text-ink-2"
        >
          Voir comment les envoyer
        </RouterLink>
      </p>

      <div class="card flex min-h-0 flex-1 flex-col overflow-hidden">
        <EmptyState
          v-if="keys.length === 0"
          class="flex-1"
          title="Aucune clé"
          description="Une clé de service authentifie une application qui écrit dans votre compte."
        />

        <ul v-else class="flex-1 divide-y divide-line overflow-y-auto">
          <li
            v-for="key in keys"
            :key="key.id"
            class="flex flex-wrap items-center gap-3 px-4 py-2.5"
            :class="key.revoked_at ? 'opacity-55' : ''"
          >
            <AppIcon name="lock" :size="15" class="shrink-0 text-ink-3" />

            <span class="w-44 shrink-0 truncate text-[0.84rem]">{{ key.label }}</span>

            <code class="font-mono text-[0.74rem] text-ink-3">{{ key.token_prefix }}…</code>

            <span
              class="chip border-line text-[0.68rem]"
              :class="key.scope === 'service' ? 'text-ochre' : 'text-ink-3'"
            >
              {{ key.scope === 'service' ? 'service' : 'publique' }}
            </span>

            <span class="flex-1" />

            <span class="text-[0.72rem] text-ink-3">
              <template v-if="key.revoked_at"
                >révoquée {{ formatRelative(key.revoked_at) }}</template
              >
              <template v-else-if="key.last_used_at">
                utilisée {{ formatRelative(key.last_used_at) }}
              </template>
              <template v-else>jamais utilisée</template>
            </span>

            <button
              v-if="!key.revoked_at"
              type="button"
              class="chip border-line text-ink-3 transition-colors hover:border-brick hover:text-brick"
              @click="revokeKey(key)"
            >
              révoquer
            </button>
          </li>
        </ul>
      </div>
    </template>

    <!-- Une table n'est plus une description : c'est une vraie table
         PostgreSQL. La supprimer efface ses lignes, et ça se dit AVANT. -->
    <ConfirmDialog
      :open="Boolean(toRemove)"
      title="Supprimer la table"
      :message="
        toRemove
          ? `« ${toRemove.name} » et toutes ses lignes seront définitivement supprimées. Cette action ne peut pas être annulée.`
          : ''
      "
      confirm-label="Supprimer définitivement"
      @confirm="removeTable(toRemove)"
      @close="toRemove = null"
    />
  </div>
</template>
