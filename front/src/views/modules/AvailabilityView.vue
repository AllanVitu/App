<script setup>
/**
 * Module Disponibilité : les sondes d'un espace.
 *
 * L'écran répond d'abord à « est-ce que tout répond ? ». Les sondes en panne
 * remontent en tête — le serveur trie —, et chacune dit son état en mots, son
 * dernier temps de réponse, ses trente derniers appels et sa disponibilité
 * sur trente jours.
 *
 * Tout membre lit ; seuls les administrateurs créent, règlent, suspendent ou
 * suppriment. Les boutons n'apparaissent qu'à eux, et le serveur refuse de
 * toute façon (cf. ProbeController) : masquer un bouton est un confort, pas
 * une protection.
 *
 * Les appels n'arrivent pas par le flux temps réel : le worker n'écrit au
 * journal que les pannes et les retours, pas un « toujours bon » par minute.
 * L'écran se relit donc toutes les trente secondes tant qu'il est visible.
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'

import AppIcon from '@/components/AppIcon.vue'
import ModuleHeader from '@/components/modules/ModuleHeader.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useRevalidate } from '@/composables/useRevalidate'
import { probesApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import {
  INTERVALS,
  durationLabel,
  intervalLabel,
  outcomeInfo,
  recentBars,
  responseLabel,
  uptimeLabel,
} from '@/utils/availability'
import { formatDateTime, formatRelative } from '@/utils/format'

const auth = useAuthStore()
const ui = useUiStore()

const probes = ref([])
const quota = ref(25)
const loading = ref(true)

const canManage = computed(() => ['owner', 'admin'].includes(auth.organization?.role))

/** Des paliers, comme pour l'intervalle : un délai de 3 417 ms n'aide personne. */
const TIMEOUTS = [
  { value: 2000, label: '2 secondes' },
  { value: 5000, label: '5 secondes' },
  { value: 10000, label: '10 secondes' },
]

const SLOW = [
  { value: 300, label: '300 ms' },
  { value: 800, label: '800 ms' },
  { value: 1000, label: '1 seconde' },
  { value: 1500, label: '1,5 seconde' },
]

const rows = computed(() =>
  probes.value.map((probe) => ({
    ...probe,
    info: outcomeInfo(probe),
    bars: recentBars(probe.recent ?? []),
  })),
)

const headerStats = computed(() => {
  const actives = probes.value.filter((probe) => !probe.is_paused)
  const down = actives.filter((probe) => probe.last_outcome === 'down').length
  const slow = actives.filter((probe) => probe.last_outcome === 'slow').length

  return [
    {
      label: probes.value.length > 1 ? 'sondes' : 'sonde',
      value: probes.value.length,
      tone: 'neutral',
    },
    ...(down ? [{ label: 'en panne', value: down, tone: 'alert' }] : []),
    ...(slow ? [{ label: slow > 1 ? 'lentes' : 'lente', value: slow, tone: 'neutral' }] : []),
  ]
})

// --- Lecture -----------------------------------------------------------------

let controller = null

async function load({ silent = false } = {}) {
  controller?.abort()
  controller = new AbortController()

  if (!silent) loading.value = true

  try {
    const { probes: lignes, meta } = await probesApi.list(controller.signal)

    probes.value = lignes
    quota.value = meta?.quota ?? 25
  } catch (error) {
    // Une relecture annulée par la suivante est le cas normal, pas une panne.
    if (!error.canceled) ui.notify(error.message, 'error')
  } finally {
    loading.value = false
  }
}

let timer = null

onMounted(() => {
  load()

  timer = setInterval(() => {
    if (!document.hidden) load({ silent: true })
  }, 30_000)
})

onBeforeUnmount(() => {
  clearInterval(timer)
  controller?.abort()
})

/** Revenu sur l'onglet : relire sans indicateur, l'écran reste en place. */
useRevalidate(() => load({ silent: true }))

// --- Détail --------------------------------------------------------------------

const openId = ref(null)
const detail = ref(null)
const loadingDetail = ref(false)

async function toggle(probe) {
  if (openId.value === probe.id) {
    openId.value = null
    return
  }

  openId.value = probe.id
  detail.value = null
  loadingDetail.value = true

  try {
    detail.value = await probesApi.find(probe.id)
  } catch (error) {
    ui.notify(error.message, 'error')
  } finally {
    loadingDetail.value = false
  }
}

// --- Écriture ------------------------------------------------------------------

const DEFAUTS = {
  name: '',
  url: 'https://',
  method: 'GET',
  interval_seconds: 300,
  timeout_ms: 5000,
  slow_ms: 1000,
}

/** null : fenêtre fermée ; { id: null } : création ; une sonde : réglage. */
const editing = ref(null)
const form = reactive({ ...DEFAUTS })
const errors = ref({})
const saving = ref(false)

function openCreate() {
  Object.assign(form, DEFAUTS)
  errors.value = {}
  editing.value = { id: null }
}

function openEdit(probe) {
  Object.assign(form, {
    name: probe.name,
    url: probe.url,
    method: probe.method,
    interval_seconds: probe.interval_seconds,
    timeout_ms: probe.timeout_ms,
    slow_ms: probe.slow_ms,
  })
  errors.value = {}
  editing.value = probe
}

async function save() {
  saving.value = true
  errors.value = {}

  try {
    if (editing.value.id) {
      // La version part avec l'écriture : si quelqu'un d'autre a réglé le
      // même champ entre-temps, le serveur le dit au lieu d'écraser.
      await probesApi.update(editing.value.id, { ...form, version: editing.value.version })
      ui.notify('Sonde enregistrée.')
    } else {
      await probesApi.create({ ...form })
      ui.notify('Sonde créée : le premier appel part dans la minute.')
    }

    editing.value = null
    load({ silent: true })
  } catch (error) {
    // Les règles vivent sur le serveur ; l'écran place ses messages sous les
    // bons champs, sans les dupliquer.
    errors.value = error.errors ?? {}

    if (!Object.keys(errors.value).length) ui.notify(error.message, 'error')
  } finally {
    saving.value = false
  }
}

async function togglePause(probe) {
  try {
    await probesApi.update(probe.id, { is_paused: !probe.is_paused, version: probe.version })
    load({ silent: true })
  } catch (error) {
    ui.notify(error.message, 'error')
  }
}

async function checkSoon(probe) {
  try {
    await probesApi.checkSoon(probe.id)
    ui.notify('Vérification demandée : le résultat arrive dans la minute.', 'info')
  } catch (error) {
    ui.notify(error.message, 'error')
  }
}

async function remove(probe) {
  const index = probes.value.findIndex((entry) => entry.id === probe.id)
  const previous = probes.value[index]

  probes.value.splice(index, 1)

  if (openId.value === probe.id) openId.value = null

  try {
    await probesApi.remove(probe.id)

    // Suppression logique, donc réversible — comme dans les autres modules.
    ui.notifyUndo('Sonde supprimée.', async () => {
      try {
        await probesApi.restore(probe.id)
        ui.notify('Sonde restaurée.')
      } catch (error) {
        ui.notify(error.message, 'error')
      } finally {
        load({ silent: true })
      }
    })
  } catch (error) {
    probes.value.splice(index, 0, previous)
    ui.notify(error.message, 'error')
  }
}

const echeance = (probe) =>
  probe.last_checked_at
    ? formatRelative(probe.last_checked_at)
    : intervalLabel(probe.interval_seconds).toLowerCase()

const chipAction =
  'chip border-line-2 text-ink-2 transition-colors hover:border-ink hover:text-ink disabled:opacity-40'
</script>

<template>
  <div class="flex h-full min-h-0 flex-col gap-5">
    <ModuleHeader slug="disponibilite" title="disponibilité" :stats="headerStats">
      <template #filters>
        <p class="text-[0.8125rem] text-ink-3">
          Chaque sonde appelle son adresse à intervalle régulier, depuis le serveur de Relais.
        </p>

        <span class="flex-1" />

        <button
          v-if="canManage"
          type="button"
          class="chip border-ink bg-ink text-paper transition-opacity hover:opacity-90 disabled:opacity-40"
          :disabled="probes.length >= quota"
          @click="openCreate"
        >
          <AppIcon name="plus" :size="13" />
          nouvelle sonde
        </button>
      </template>
    </ModuleHeader>

    <p v-if="canManage && probes.length >= quota" class="text-[0.8125rem] text-ink-3">
      L'espace compte {{ quota }} sondes, le maximum. Supprimez-en une pour en ajouter une autre.
    </p>

    <div v-if="loading" class="flex justify-center py-16">
      <BaseSpinner class="size-7 text-ink" />
    </div>

    <div v-else-if="!probes.length" class="card">
      <EmptyState
        title="Aucune sonde"
        :description="
          canManage
            ? 'Ajoutez l’adresse d’une page qui doit toujours répondre : l’accueil, la connexion, le paiement.'
            : 'Un administrateur de l’espace peut en ajouter une.'
        "
      />
    </div>

    <ul v-else class="card divide-y divide-line overflow-hidden" aria-label="Sondes">
      <li v-for="probe in rows" :key="probe.id">
        <div class="flex flex-wrap items-center gap-x-5 gap-y-2 px-4.5 py-3.5">
          <button
            type="button"
            class="flex min-w-0 flex-1 items-center gap-3.5 text-left"
            :aria-expanded="openId === probe.id"
            @click="toggle(probe)"
          >
            <span class="size-2 shrink-0 rounded-pill" :class="probe.info.dot" aria-hidden="true" />
            <span class="flex min-w-0 flex-col">
              <span class="truncate text-[0.9375rem] font-semibold">{{ probe.name }}</span>
              <span class="truncate font-mono text-xs text-ink-3">
                {{ probe.method }} {{ probe.url }}
              </span>
            </span>
          </button>

          <span class="flex w-32 shrink-0 flex-col text-[0.8125rem]">
            <span :class="probe.info.tone">{{ probe.info.label }}</span>
            <span v-if="probe.down_since && !probe.is_paused" class="text-xs text-ink-3">
              tombée {{ formatRelative(probe.down_since) }}
            </span>
          </span>

          <span class="hidden w-32 shrink-0 flex-col md:flex">
            <span class="font-mono text-[0.8125rem] tabular-nums">
              {{ responseLabel(probe.last_response_ms) }}
            </span>
            <span class="text-xs text-ink-3">{{ echeance(probe) }}</span>
          </span>

          <!-- Les trente derniers appels : la hauteur dit le temps de réponse,
               dans la couleur de ligne ; un point dit la panne ou la lenteur
               (cf. utils/availability, recentBars). -->
          <span class="hidden h-8 w-44 shrink-0 items-end gap-px lg:flex" aria-hidden="true">
            <span v-for="(bar, index) in probe.bars" :key="index" class="relative h-full flex-1">
              <span
                v-if="bar.height"
                class="absolute inset-x-0 bottom-0 bg-mod-disponibilite"
                :style="{ height: `${bar.height * 100}%` }"
              />
              <span
                v-if="bar.mark"
                class="absolute left-1/2 size-1.5 -translate-x-1/2 rounded-pill"
                :class="bar.mark"
                :style="{ bottom: `calc(${bar.height * 100}% + 2px)` }"
              />
            </span>
          </span>

          <span class="w-20 shrink-0 text-right">
            <span class="block font-mono text-[0.9375rem] tabular-nums">
              {{ uptimeLabel(probe.uptime_30d) }}
            </span>
            <span class="text-xs text-ink-3">sur 30 jours</span>
          </span>

          <div v-if="canManage" class="flex shrink-0 flex-wrap items-center gap-1.5">
            <button
              type="button"
              :class="chipAction"
              :disabled="probe.is_paused"
              @click="checkSoon(probe)"
            >
              vérifier
            </button>
            <button type="button" :class="chipAction" @click="togglePause(probe)">
              {{ probe.is_paused ? 'reprendre' : 'pause' }}
            </button>
            <button type="button" :class="chipAction" @click="openEdit(probe)">régler</button>
            <button
              type="button"
              class="chip border-line-2 text-ink-2 transition-colors hover:border-brick hover:text-brick"
              :aria-label="`Supprimer la sonde ${probe.name}`"
              @click="remove(probe)"
            >
              <AppIcon name="trash" :size="13" />
            </button>
          </div>
        </div>

        <div v-if="openId === probe.id" class="border-t border-line bg-paper px-4.5 py-4">
          <div v-if="loadingDetail" class="flex justify-center py-6">
            <BaseSpinner class="size-5 text-ink" />
          </div>

          <div v-else-if="detail" class="grid gap-6 lg:grid-cols-2">
            <section :aria-labelledby="`appels-${probe.id}`">
              <h3 :id="`appels-${probe.id}`" class="label-caps mb-2">Derniers appels</h3>

              <p v-if="!detail.checks.length" class="text-[0.8125rem] text-ink-3">
                Aucun appel pour l'instant : le premier part dans la minute qui suit la création.
              </p>

              <table v-else class="w-full text-[0.8125rem]">
                <thead class="sr-only">
                  <tr>
                    <th scope="col">Quand</th>
                    <th scope="col">Résultat</th>
                    <th scope="col">Temps de réponse</th>
                    <th scope="col">Détail</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-line">
                  <tr v-for="check in detail.checks.slice(0, 10)" :key="check.checked_at">
                    <td class="py-1.5 pr-3 text-ink-3">{{ formatDateTime(check.checked_at) }}</td>
                    <td
                      class="py-1.5 pr-3"
                      :class="outcomeInfo({ last_outcome: check.outcome }).tone"
                    >
                      {{ outcomeInfo({ last_outcome: check.outcome }).label }}
                    </td>
                    <td class="py-1.5 pr-3 font-mono tabular-nums">
                      {{ responseLabel(check.response_ms) }}
                    </td>
                    <td class="py-1.5 text-ink-3">
                      {{ check.error ?? (check.http_status ? `HTTP ${check.http_status}` : '') }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </section>

            <section :aria-labelledby="`pannes-${probe.id}`">
              <h3 :id="`pannes-${probe.id}`" class="label-caps mb-2">Pannes</h3>

              <p v-if="!detail.incidents.length" class="text-[0.8125rem] text-ink-3">
                Aucune panne enregistrée.
              </p>

              <ul v-else class="divide-y divide-line">
                <li
                  v-for="incident in detail.incidents"
                  :key="incident.id"
                  class="flex justify-between gap-4 py-1.5 text-[0.8125rem]"
                >
                  <span class="min-w-0">
                    {{ formatDateTime(incident.started_at) }}
                    <span v-if="incident.cause" class="text-ink-3">· {{ incident.cause }}</span>
                  </span>
                  <span class="shrink-0" :class="incident.ended_at ? 'text-ink-3' : 'text-brick'">
                    {{ incident.ended_at ? durationLabel(incident.duration_seconds) : 'en cours' }}
                  </span>
                </li>
              </ul>
            </section>
          </div>
        </div>
      </li>
    </ul>

    <BaseModal
      :open="editing !== null"
      :title="editing?.id ? 'Régler la sonde' : 'Nouvelle sonde'"
      @close="editing = null"
    >
      <form class="space-y-4" novalidate @submit.prevent="save">
        <BaseInput
          v-model="form.name"
          label="Nom"
          placeholder="Page de paiement"
          required
          :error="errors.name"
        />

        <BaseInput
          v-model="form.url"
          label="Adresse"
          type="url"
          placeholder="https://exemple.fr/sante"
          required
          :error="errors.url"
          hint="Une adresse publique en http ou https. Les réseaux privés, locaux et réservés sont refusés."
        />

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block">
            <span class="label-field">Fréquence</span>
            <select v-model.number="form.interval_seconds" class="input-field">
              <option v-for="option in INTERVALS" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
            <span v-if="errors.interval_seconds" class="mt-1 block text-[0.72rem] text-brick">
              {{ errors.interval_seconds }}
            </span>
          </label>

          <label class="block">
            <span class="label-field">Méthode</span>
            <select v-model="form.method" class="input-field">
              <option value="GET">GET — lit la page</option>
              <option value="HEAD">HEAD — n'en lit que l'en-tête</option>
            </select>
          </label>

          <label class="block">
            <span class="label-field">En panne sans réponse après</span>
            <select v-model.number="form.timeout_ms" class="input-field">
              <option v-for="option in TIMEOUTS" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </label>

          <label class="block">
            <span class="label-field">Lente au-delà de</span>
            <select v-model.number="form.slow_ms" class="input-field">
              <option v-for="option in SLOW" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
            <span v-if="errors.slow_ms" class="mt-1 block text-[0.72rem] text-brick">
              {{ errors.slow_ms }}
            </span>
          </label>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <BaseButton type="button" variant="secondary" @click="editing = null">Fermer</BaseButton>
          <BaseButton type="submit" :loading="saving">
            {{ editing?.id ? 'Enregistrer' : 'Créer la sonde' }}
          </BaseButton>
        </div>
      </form>
    </BaseModal>
  </div>
</template>
