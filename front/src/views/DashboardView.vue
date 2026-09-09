<script setup>
/**
 * Tableau de bord.
 *
 * Il répond à cinq questions, dans l'ordre où on se les pose :
 *
 *   1. Où en est-on, en quatre chiffres ?   -> les tuiles de tête
 *   2. Qu'est-ce qui demande une action ?   -> demande attention
 *   3. Quelle tendance sur deux semaines ?  -> les deux courbes
 *   4. Où en est chaque module ?            -> la grille
 *   5. Que s'est-il passé récemment ?       -> activité récente
 *
 * Ce n'est PAS un annuaire des modules — cette fonction appartient au menu
 * latéral, présent sur toutes les pages. La répéter ici ferait deux menus
 * concurrents et laisserait l'écran d'accueil sans contenu propre. La grille
 * ne navigue qu'accessoirement : ce qu'elle montre, c'est un état.
 *
 * Un seul appel (`GET /api/dashboard`) fournit le tout : l'écran s'affiche
 * en un aller-retour réseau.
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
import ModuleGrid from '@/components/dashboard/ModuleGrid.vue'
import StatTile from '@/components/dashboard/StatTile.vue'
import TrendChart from '@/components/dashboard/TrendChart.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useRevalidate } from '@/composables/useRevalidate'
import { dashboardApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { formatRelative } from '@/utils/format'
import { modulePath } from '@/utils/modules'

const auth = useAuthStore()
const ui = useUiStore()

const overview = ref(null)
const loading = ref(true)

const firstName = computed(() => auth.user?.full_name?.split(' ')[0] ?? '')

const attention = computed(() => overview.value?.attention ?? [])
const summary = computed(() => overview.value?.summary ?? null)

/** Séries quotidiennes, séparées : deux mesures, deux cadres, deux échelles. */
const deployments = computed(() =>
  (overview.value?.trends ?? []).map((day) => ({ date: day.date, value: day.deployments })),
)

const errors = computed(() =>
  (overview.value?.trends ?? []).map((day) => ({ date: day.date, value: day.errors })),
)

/**
 * Formulation du motif.
 *
 * L'ordre d'affichage est décidé par le SERVEUR (cf. AttentionFeed) : les
 * faits — une production cassée, une exception fatale, une échéance
 * dépassée — passent avant l'intention qu'est une priorité déclarée. Ce
 * composant ne fait que nommer ce qu'il reçoit.
 *
 * La pastille porte sa classe EN TOUTES LETTRES : Tailwind compile en
 * scannant les noms de classe littéralement présents dans les sources ; une
 * classe fabriquée à l'exécution (« text- » remplacé par « bg- ») ne serait
 * pas générée et la pastille resterait invisible.
 */
const REASONS = {
  failed: { label: 'déploiement en échec', tone: 'text-brick', dot: 'bg-brick' },
  fatal: { label: 'erreur fatale', tone: 'text-brick', dot: 'bg-brick' },
  overdue: { label: 'en retard', tone: 'text-brick', dot: 'bg-brick' },
  error: { label: 'erreur non résolue', tone: 'text-ochre', dot: 'bg-ochre' },
  urgent: { label: 'urgent', tone: 'text-ochre', dot: 'bg-ochre' },
}

const reasonOf = (item) => REASONS[item.reason] ?? REASONS.urgent

/** Nom lisible d'un module, pour situer une ligne du fil. */
const MODULE_NAMES = {
  backend: 'backend',
  deploiement: 'déploiement',
  tickets: 'tickets',
  supervision: 'supervision',
  design: 'design',
}

const moduleName = (slug) => MODULE_NAMES[slug] ?? slug

/**
 * L'entrée n'est jouée qu'UNE FOIS par session.
 *
 * Le tableau de bord est remonté à chaque retour dessus — c'est-à-dire
 * souvent. Rejouée à chaque fois, la cascade cesse d'aider le regard à entrer
 * dans la page et devient un péage : on attend qu'elle finisse pour lire.
 *
 * Le témoin vit au niveau du MODULE, pas du composant : il survit donc au
 * démontage, mais pas au rechargement de la page — ce qui correspond bien à
 * « la première fois de cette visite ».
 */
let introPlayed = false

const root = ref(null)
let scope = null

/**
 * Entrée, jouée APRÈS l'arrivée des données.
 *
 * C'est pour cela qu'elle n'est pas confiée à `useMotion`, qui déclenche au
 * montage : à ce moment-là l'écran ne contient qu'un indicateur de
 * chargement, et les blocs à animer n'existent pas encore. Animer un
 * squelette vide puis y remplacer le contenu produirait deux mouvements
 * successifs, illisibles.
 *
 * UNE animation, sur les cinq blocs de premier niveau — pas sur chacune de
 * leurs lignes. Trente éléments qui entrent en cascade, c'est une page qu'on
 * regarde se construire au lieu de la lire.
 */
function playIntro() {
  if (introPlayed || !root.value) return

  introPlayed = true

  // Rien à poser en mouvement réduit : sans animation, les blocs sont déjà
  // opaques et à leur place. C'est ici l'animation qui les rend invisibles,
  // pas une feuille de style.
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

/**
 * Un seul appel fournit tout l'écran : les chiffres, les alertes, les
 * courbes, la grille et l'activité. Le relire, c'est tout rafraîchir.
 */
async function load({ silent = false } = {}) {
  if (!silent) loading.value = true

  try {
    overview.value = await dashboardApi.overview()
  } catch (error) {
    ui.notify(error.message, 'error')
  } finally {
    loading.value = false
  }
}

/**
 * L'écran d'accueil est celui qu'on laisse ouvert. C'est aussi celui qui
 * annonce ce qui « demande attention » — un tableau d'alertes vieux d'une
 * heure ne dit pas ce qui demande attention, il dit ce qui le demandait.
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
  <div ref="root" class="space-y-6">
    <!-- Accueil. Le chevron d'invite et le curseur clignotant qui l'ornaient
         ont été retirés : ils imitaient un terminal, alors que rien ici ne se
         tape au clavier. Un curseur qui clignote sans qu'on puisse écrire est
         une promesse que l'interface ne tient pas. -->
    <div class="min-w-0">
      <p class="label-caps">session ouverte</p>
      <h2 class="mt-1 text-xl font-bold">bonjour {{ firstName }}</h2>
    </div>

    <div v-if="loading" class="flex justify-center py-20">
      <BaseSpinner class="size-8 text-ink" />
    </div>

    <template v-else-if="overview">
      <!-- ========================= LES QUATRE CHIFFRES ========================= -->
      <section v-if="summary" data-anim="block" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatTile
          label="déploiements"
          :value="summary.deployments.value"
          :previous="summary.deployments.previous"
        />
        <!-- Taux de RÉUSSITE et non d'échec : la valeur monte quand la
             situation s'améliore. La variation est en POINTS, pas en pourcent
             — « +5 % » d'un taux serait ambigu. -->
        <StatTile
          label="réussite"
          suffix="%"
          delta-suffix=" pts"
          good-when="up"
          :value="summary.success_rate.value"
          :previous="summary.success_rate.previous"
        />
        <StatTile
          label="erreurs"
          good-when="down"
          :value="summary.errors.value"
          :previous="summary.errors.previous"
        />
        <!-- Un ÉTAT : aucune comparaison, cf. StatTile. -->
        <StatTile label="tickets ouverts" :value="summary.open_tickets.value" />
      </section>

      <!-- ========================== DEMANDE ATTENTION ========================== -->
      <section data-anim="block">
        <h3 class="mb-3 text-[0.95rem] font-semibold">demande attention</h3>

        <!-- L'absence d'alerte est une information, pas un vide : la dire
             explicitement évite de laisser croire au chargement en cours. -->
        <div
          v-if="attention.length === 0"
          class="card flex items-center gap-3 px-4 py-3 text-[0.82rem] text-ink-2"
        >
          <AppIcon name="check" :size="16" class="shrink-0 text-moss" />
          Rien ne demande d'action : aucun déploiement en échec, aucune erreur non résolue, aucune
          échéance dépassée.
        </div>

        <ul v-else class="card divide-y divide-line overflow-hidden">
          <li v-for="item in attention" :key="item.id">
            <RouterLink
              :to="modulePath(item.module)"
              class="flex items-center gap-3 px-4 py-2 transition-colors hover:bg-raised"
            >
              <!-- Une pastille de la couleur du motif : l'identité passe par
                   la marque, jamais par la couleur du texte seule. -->
              <span
                class="size-2 shrink-0 rounded-pill"
                :class="reasonOf(item).dot"
                aria-hidden="true"
              />

              <span class="hidden w-24 shrink-0 text-[0.72rem] text-ink-3 sm:block">
                {{ moduleName(item.module) }}
              </span>

              <!-- La référence dit QUOI sans ouvrir : une branche Git, un
                   numéro de ticket, un nombre d'occurrences. -->
              <span
                class="hidden w-32 shrink-0 truncate font-mono text-[0.72rem] tabular-nums text-ink-3 md:block"
              >
                {{ item.ref }}
              </span>

              <span class="min-w-0 flex-1 truncate text-[0.84rem]">{{ item.title }}</span>

              <span class="shrink-0 text-[0.72rem]" :class="reasonOf(item).tone">
                {{ reasonOf(item).label }}
              </span>
            </RouterLink>
          </li>
        </ul>
      </section>

      <!-- ============================= TENDANCES ============================== -->
      <!-- DEUX cadres, jamais deux axes dans un seul : cf. TrendChart. -->
      <section data-anim="block" class="grid gap-3 md:grid-cols-2">
        <TrendChart
          title="déploiements"
          unit="déploiements"
          color="chart-1"
          :points="deployments"
        />
        <TrendChart title="erreurs" unit="occurrences" color="chart-2" :points="errors" />
      </section>

      <!-- ========================= ÉTAT DES MODULES =========================== -->
      <div data-anim="block">
        <ModuleGrid :modules="overview.modules" />
      </div>

      <!-- ========================== ACTIVITÉ RÉCENTE ========================== -->
      <section data-anim="block">
        <h3 class="mb-3 text-[0.95rem] font-semibold">activité récente</h3>

        <div class="card overflow-hidden">
          <EmptyState
            v-if="!overview.recent.length"
            title="Aucune activité"
            description="Les éléments que vous créerez apparaîtront ici."
          />

          <ul v-else class="divide-y divide-line">
            <li v-for="item in overview.recent" :key="`${item.module}-${item.id}`">
              <RouterLink
                :to="modulePath(item.module)"
                class="flex items-center gap-3 px-4 py-2 transition-colors hover:bg-raised"
              >
                <span class="hidden w-24 shrink-0 text-[0.72rem] text-ink-3 sm:block">
                  {{ moduleName(item.module) }}
                </span>

                <span class="w-24 shrink-0 truncate font-mono text-[0.72rem] text-ink-3">
                  {{ item.ref }}
                </span>

                <span class="min-w-0 flex-1 truncate text-[0.84rem]">{{ item.title }}</span>

                <span class="shrink-0 text-[0.72rem] text-ink-3">
                  {{ formatRelative(item.happened_at) }}
                </span>
              </RouterLink>
            </li>
          </ul>
        </div>
      </section>
    </template>
  </div>
</template>
