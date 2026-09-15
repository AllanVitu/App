<script setup>
/**
 * Tableau de bord.
 *
 * Il répond à quatre questions, dans l'ordre où on se les pose en arrivant :
 *
 *   1. Où en est-on ?                       -> la phrase d'accueil, les chiffres
 *   2. Que s'est-il passé en production ?   -> la ligne de production
 *   3. Qu'est-ce qui m'attend ?             -> demande attention, ma journée
 *   4. Où en est chaque module ?            -> vos lignes
 *
 * Ce n'est PAS un annuaire des modules — le menu latéral l'est déjà. « Vos
 * lignes » montre un ÉTAT, et ne navigue qu'accessoirement.
 *
 * Les deux courbes quotidiennes et l'activité récente sont parties. Les
 * courbes sont remplacées par la ligne de production, qui pose déploiements
 * et erreurs sur le même axe au lieu de deux cadres à comparer ; l'activité a
 * son écran, l'Historique, où elle se filtre au lieu de défiler.
 *
 * Un seul appel (`GET /api/dashboard`) fournit le tout : l'écran s'affiche en
 * un aller-retour réseau.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'

import {
  DURATION,
  animate,
  appEnter,
  createScope,
  prefersReducedMotion,
  stagger,
} from '@/animations/motion'
import AppIcon from '@/components/AppIcon.vue'
import ProductionLine from '@/components/dashboard/ProductionLine.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { useRevalidate } from '@/composables/useRevalidate'
import { dashboardApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { availabilityKpi } from '@/utils/availability'
import {
  PRIORITIES,
  attentionParts,
  attentionSentence,
  dayLabel,
  delta,
  dueLabel,
  moduleStatus,
  reasonOf,
} from '@/utils/dashboard'
import { formatRelative } from '@/utils/format'
import { moduleLine, modulePath } from '@/utils/modules'

const auth = useAuthStore()
const ui = useUiStore()

const overview = ref(null)
const loading = ref(true)

/** Relu à chaque chargement : un onglet laissé ouvert la nuit change de jour. */
const now = ref(new Date())

const firstName = computed(() => auth.user?.full_name?.split(' ')[0] ?? '')
const greeting = computed(() => (firstName.value ? `Bonjour ${firstName.value}.` : 'Bonjour.'))
const today = computed(() => dayLabel(now.value))

const attention = computed(() => overview.value?.attention ?? [])
const sentence = computed(() => attentionSentence(attention.value))

const alerts = computed(() =>
  attention.value.map((item) => ({
    ...item,
    parts: attentionParts(item, formatRelative),
    tone: reasonOf(item),
  })),
)

const myDay = computed(() => overview.value?.my_day ?? { total: 0, tickets: [] })

const tasks = computed(() =>
  myDay.value.tickets.map((ticket) => ({
    ...ticket,
    priorityInfo: PRIORITIES[ticket.priority] ?? null,
    due: dueLabel(ticket.due_date, now.value),
  })),
)

const lines = computed(() =>
  (overview.value?.modules ?? []).map((module) => ({ ...module, status: moduleStatus(module) })),
)

/**
 * Les quatre chiffres.
 *
 * « Tickets ouverts » ne porte aucune comparaison : c'est un ÉTAT, pas un
 * flux. « 34, +3 » laisserait croire qu'il s'en est créé trois, alors que le
 * nombre peut monter parce qu'on en a fermé moins. Il dit plutôt la part qui
 * revient à la personne qui regarde.
 */
const kpis = computed(() => {
  const summary = overview.value?.summary

  if (!summary) return []

  const assignes = myDay.value.total

  return [
    {
      label: 'Tickets ouverts',
      value: summary.open_tickets.value,
      note: assignes ? { text: `dont ${assignes} pour vous`, tone: 'text-ink-3' } : null,
    },
    {
      label: 'Mises en production · 7 j',
      value: summary.deployments.value,
      note: delta(summary.deployments.value, summary.deployments.previous),
    },
    {
      label: 'Erreurs · 7 j',
      value: summary.errors.value,
      note: delta(summary.errors.value, summary.errors.previous, { goodWhen: 'down' }),
    },
    // La disponibilité quand des sondes l'ont mesurée ; sinon le taux de
    // RÉUSSITE des mises en production — qui monte quand la situation
    // s'améliore, et varie en POINTS, « +5 % » d'un taux étant ambigu.
    availabilityKpi(overview.value?.availability) ?? {
      label: 'Réussite · 7 j',
      value: summary.success_rate.value === null ? '—' : `${summary.success_rate.value} %`,
      note: delta(summary.success_rate.value, summary.success_rate.previous, {
        goodWhen: 'up',
        unit: ' pts',
      }),
    },
  ]
})

const nombre = (value) => (typeof value === 'number' ? value.toLocaleString('fr-FR') : value)

/**
 * L'entrée n'est jouée qu'UNE FOIS par session.
 *
 * Le tableau de bord est remonté à chaque retour dessus — c'est-à-dire
 * souvent. Rejouée à chaque fois, la cascade cesse d'aider le regard à entrer
 * dans la page et devient un péage : on attend qu'elle finisse pour lire.
 */
let introPlayed = false

const root = ref(null)
let scope = null

/**
 * Entrée, jouée APRÈS l'arrivée des données : au montage, l'écran ne contient
 * qu'un indicateur de chargement, et les blocs à animer n'existent pas encore.
 *
 * UNE animation, sur les blocs de premier niveau — pas sur chacune de leurs
 * lignes. Trente éléments qui entrent en cascade, c'est une page qu'on
 * regarde se construire au lieu de la lire.
 */
function playIntro() {
  if (introPlayed || !root.value) return

  introPlayed = true

  // Rien à poser en mouvement réduit : sans animation, les blocs sont déjà
  // opaques et à leur place.
  if (prefersReducedMotion()) return

  scope = createScope({ root: root.value }).add(() => {
    animate('[data-anim="block"]', {
      translateY: [10, 0],
      opacity: [0, 1],
      duration: DURATION.base,
      delay: stagger(60),
      ease: appEnter,
    })
  })
}

async function load({ silent = false } = {}) {
  if (!silent) loading.value = true

  try {
    overview.value = await dashboardApi.overview()
    now.value = new Date()
  } catch (error) {
    ui.notify(error.message, 'error')
  } finally {
    loading.value = false
  }
}

/**
 * L'écran d'accueil est celui qu'on laisse ouvert. Un tableau d'alertes vieux
 * d'une heure ne dit pas ce qui demande attention, il dit ce qui le demandait.
 */
useRevalidate(() => load({ silent: true }))

onMounted(async () => {
  await load()

  // Le DOM doit exister avant d'être ciblé par les sélecteurs.
  await nextTick()

  if (overview.value) playIntro()
})

// Une animation en cours survivrait à la vue et toucherait des nœuds retirés
// du document.
onBeforeUnmount(() => {
  scope?.revert()
  scope = null
})
</script>

<template>
  <div ref="root" class="flex flex-col gap-5">
    <div class="min-w-0">
      <p class="label-caps">{{ today }}</p>
      <h1
        class="mt-1.5 text-[1.875rem] font-bold leading-[1.1] tracking-[-0.015em] [font-stretch:85%]"
      >
        {{ greeting }}
      </h1>
      <p v-if="overview" class="mt-1.5 text-[0.9375rem] text-ink-2">{{ sentence }}</p>
    </div>

    <div v-if="loading" class="flex justify-center py-20">
      <BaseSpinner class="size-8 text-ink" />
    </div>

    <template v-else-if="overview">
      <!-- ========================= LES QUATRE CHIFFRES ========================= -->
      <!-- Filets d'un pixel par l'écart de la grille sur un fond de filet :
           ils tombent juste en deux colonnes comme en quatre, là où des
           bordures par cellule se doubleraient au passage à la ligne. -->
      <section
        data-anim="block"
        class="grid grid-cols-2 gap-px overflow-hidden rounded-card border border-line bg-line lg:grid-cols-4"
        aria-label="Chiffres clés"
      >
        <div
          v-for="kpi in kpis"
          :key="kpi.label"
          class="flex flex-col gap-1 bg-panel px-4.5 py-3.5"
        >
          <span class="label-caps">{{ kpi.label }}</span>
          <span
            class="font-mono text-[1.75rem] font-medium leading-[1.15] tracking-[-0.02em] tabular-nums"
          >
            {{ nombre(kpi.value) }}
          </span>
          <span v-if="kpi.note" class="text-[0.78rem]" :class="kpi.note.tone">{{
            kpi.note.text
          }}</span>
        </div>
      </section>

      <!-- ======================== LA LIGNE DE PRODUCTION ======================= -->
      <div v-if="overview.line" data-anim="block">
        <ProductionLine :line="overview.line" />
      </div>

      <div data-anim="block" class="grid gap-5 xl:grid-cols-[1.15fr_1fr_1fr]">
        <!-- ========================= DEMANDE ATTENTION ======================== -->
        <section
          class="card flex min-w-0 flex-col overflow-hidden"
          aria-labelledby="attention-titre"
        >
          <header class="flex items-center justify-between border-b border-line px-4.5 py-3">
            <h2 id="attention-titre" class="label-caps">Demande attention</h2>
            <span class="font-mono text-xs text-ink-3 tabular-nums">{{ alerts.length }}</span>
          </header>

          <!-- L'absence d'alerte est une information, pas un vide : la dire
               explicitement évite de laisser croire au chargement en cours. -->
          <p
            v-if="!alerts.length"
            class="flex items-start gap-3 px-4.5 py-4 text-[0.84rem] text-ink-2"
          >
            <AppIcon name="check" :size="16" class="mt-0.5 shrink-0 text-moss" />
            Rien ne demande d'action : aucun déploiement en échec, aucune erreur non résolue, aucune
            échéance dépassée.
          </p>

          <ul v-else class="divide-y divide-line">
            <li v-for="item in alerts" :key="`${item.module}-${item.id}`">
              <RouterLink
                :to="modulePath(item.module)"
                class="flex gap-3.5 px-4.5 py-3 transition-colors hover:bg-raised"
              >
                <!-- La BARRE, en aplat, dit sur quelle ligne ça se passe ; le
                     POINT, à droite, dit à quel point c'est grave. Deux formes,
                     deux questions — cf. utils/modules. -->
                <span
                  class="ligne self-stretch"
                  :class="moduleLine(item.module)"
                  aria-hidden="true"
                />

                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                  <span class="truncate text-[0.9rem] font-semibold">{{
                    item.parts.headline
                  }}</span>
                  <span class="truncate font-mono text-xs text-ink-3">{{ item.parts.meta }}</span>
                  <span v-if="item.parts.detail" class="truncate text-[0.8125rem] text-ink-2">
                    {{ item.parts.detail }}
                  </span>
                </span>

                <span
                  class="flex h-5 shrink-0 items-center gap-1.5 text-xs"
                  :class="item.tone.tone"
                >
                  <span class="size-1.75 rounded-pill" :class="item.tone.dot" aria-hidden="true" />
                  {{ item.tone.label }}
                </span>
              </RouterLink>
            </li>
          </ul>

          <RouterLink
            :to="{ name: 'history' }"
            class="mt-auto flex items-center gap-2 border-t border-line px-4.5 py-3 text-[0.8125rem] text-ink-2 transition-colors hover:text-ink"
          >
            Tout voir dans l'historique
            <AppIcon name="arrow-right" :size="14" />
          </RouterLink>
        </section>

        <!-- ============================ MA JOURNÉE ============================ -->
        <section class="card flex min-w-0 flex-col overflow-hidden" aria-labelledby="journee-titre">
          <header class="flex items-center justify-between border-b border-line px-4.5 py-3">
            <h2 id="journee-titre" class="label-caps">Ma journée</h2>
            <span class="font-mono text-xs text-ink-3 tabular-nums">
              {{ myDay.total }} {{ myDay.total > 1 ? 'assignés' : 'assigné' }}
            </span>
          </header>

          <p v-if="!tasks.length" class="px-4.5 py-4 text-[0.84rem] text-ink-2">
            Aucun ticket ne vous est assigné. Ceux qui attendent preneur sont dans Tickets.
          </p>

          <ul v-else class="divide-y divide-line">
            <li v-for="ticket in tasks" :key="ticket.id">
              <RouterLink
                :to="modulePath('tickets')"
                class="flex flex-col gap-1 px-4.5 py-3 transition-colors hover:bg-raised"
              >
                <span class="truncate text-[0.875rem]">{{ ticket.title }}</span>
                <span class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5 text-xs text-ink-3">
                  <span class="font-mono">#{{ ticket.number }}</span>
                  <span
                    v-if="ticket.priorityInfo"
                    class="flex items-center gap-1.5"
                    :class="ticket.priorityInfo.tone"
                  >
                    <span class="size-1.5 rounded-pill bg-current" aria-hidden="true" />
                    {{ ticket.priorityInfo.label }}
                  </span>
                  <span v-if="ticket.due" :class="ticket.due.late ? 'text-brick' : ''">
                    {{ ticket.due.text }}
                  </span>
                  <span v-if="ticket.project" class="truncate">{{ ticket.project }}</span>
                </span>
              </RouterLink>
            </li>
          </ul>

          <RouterLink
            :to="modulePath('tickets')"
            class="mt-auto flex items-center gap-2 border-t border-line px-4.5 py-3 text-[0.8125rem] text-ink-2 transition-colors hover:text-ink"
          >
            Ouvrir les tickets
            <AppIcon name="arrow-right" :size="14" />
          </RouterLink>
        </section>

        <!-- ============================ VOS LIGNES ============================ -->
        <section class="card flex min-w-0 flex-col overflow-hidden" aria-labelledby="lignes-titre">
          <header class="flex items-center justify-between border-b border-line px-4.5 py-3">
            <h2 id="lignes-titre" class="label-caps">Vos lignes</h2>
            <span class="font-mono text-xs text-ink-3 tabular-nums">{{ lines.length }}</span>
          </header>

          <ul class="grid grid-cols-2 gap-2.5 p-3.5">
            <li v-for="module in lines" :key="module.id">
              <RouterLink
                :to="modulePath(module.slug)"
                class="flex h-full flex-col overflow-hidden rounded-card border border-line bg-paper transition-colors hover:border-line-2"
              >
                <span class="h-1 shrink-0" :class="moduleLine(module.slug)" aria-hidden="true" />
                <span class="flex min-w-0 flex-col px-3 py-2.5">
                  <span class="truncate text-[0.84rem] font-semibold">{{ module.name }}</span>
                  <span class="truncate font-mono text-[0.72rem]" :class="module.status.tone">
                    {{ module.status.text }}
                  </span>
                </span>
              </RouterLink>
            </li>
          </ul>
        </section>
      </div>
    </template>
  </div>
</template>
