<script setup>
/**
 * Tableau de bord.
 *
 * Il répond à trois questions, dans cet ordre :
 *
 *   1. Qu'est-ce qui demande une action maintenant ?
 *   2. Où en est chaque module ?
 *   3. Que s'est-il passé récemment ?
 *
 * Ce n'est PAS un annuaire des modules — cette fonction appartient au menu
 * latéral, qui est présent sur toutes les pages. La répéter ici ferait deux
 * menus concurrents et laisserait l'écran d'accueil sans contenu propre.
 *
 * Les quatre compteurs globaux qui occupaient le haut de page ne lisaient
 * qu'une seule table ; depuis que « tickets » a la sienne, ils affichaient
 * « 3 éléments » à un compte qui en avait dix-sept. Ils sont remplacés par
 * l'état par module, qui a une source par module.
 *
 * Un seul appel (`GET /api/dashboard`) fournit le tout : l'écran s'affiche
 * en un aller-retour réseau.
 */
import { computed, nextTick, onMounted, ref } from 'vue'

import { gsap } from '@/animations/gsap'
import AppIcon from '@/components/AppIcon.vue'
import ModuleGallery from '@/components/ModuleGallery.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useGsapContext } from '@/composables/useGsap'
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

/**
 * Formulation du motif.
 *
 * L'ordre d'affichage est décidé par le SERVEUR (cf. AttentionFeed) : les
 * faits — une production cassée, une exception fatale, une échéance
 * dépassée — passent avant l'intention qu'est une priorité déclarée. Ce
 * composant ne fait que nommer ce qu'il reçoit.
 */
// La pastille porte sa classe EN TOUTES LETTRES : Tailwind compile en
// scannant les noms de classe littéralement présents dans les sources, une
// classe fabriquée à l'exécution (« text- » remplacé par « bg- ») ne serait
// pas générée et la pastille resterait invisible.
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

const { root, run } = useGsapContext()

/**
 * L'entrée n'est jouée qu'UNE FOIS par session.
 *
 * Le tableau de bord est remonté à chaque retour dessus — c'est-à-dire
 * souvent. Rejouée à chaque fois, la cascade cesse d'aider le regard à entrer
 * dans la page et devient un péage : on attend qu'elle finisse pour lire.
 * Utile la première fois, pénible les suivantes.
 *
 * Le témoin vit au niveau du MODULE, pas du composant : il survit donc au
 * démontage, mais pas au rechargement de la page — ce qui correspond bien à
 * « la première fois de cette visite ».
 */
let introPlayed = false

/**
 * Entrée, jouée APRÈS l'arrivée des données : animer un squelette vide puis
 * remplacer le contenu produirait deux mouvements successifs, illisibles.
 */
function playIntro() {
  if (introPlayed) return

  introPlayed = true

  run(() => {
    // fromTo plutôt que from : les deux extrémités sont explicites. Un tween
    // `from` déduit son état d'arrivée de la valeur courante au moment du
    // rendu — si l'élément a déjà été touché par une autre animation, il
    // mémorise 0 comme arrivée et reste invisible.
    const timeline = gsap.timeline()

    timeline.fromTo(
      '[data-anim="attention"]',
      { x: -12, opacity: 0 },
      { x: 0, opacity: 1, stagger: 0.05, overwrite: 'auto' },
    )
    timeline.fromTo(
      '[data-anim="recent"]',
      { x: 16, opacity: 0 },
      { x: 0, opacity: 1, stagger: 0.05, overwrite: 'auto' },
      0.2,
    )
  })
}

onMounted(async () => {
  try {
    overview.value = await dashboardApi.overview()
  } catch (error) {
    ui.notify(error.message, 'error')
  } finally {
    loading.value = false
  }

  // Le DOM doit exister avant d'être ciblé par les sélecteurs GSAP.
  await nextTick()

  if (overview.value) playIntro()
})
</script>

<template>
  <div ref="root" class="space-y-8">
    <!-- Accueil.
         Le diagramme décoratif qui occupait la droite a été retiré : il
         n'encodait rien, et la place vaut mieux pour l'état des modules.
         Le prénom ne se brouille plus non plus à l'arrivée — joli une fois,
         coûteux à chacune des dizaines de visites quotidiennes. -->
    <div class="min-w-0 max-w-lg">
      <p class="label-caps">session ouverte</p>
      <h2 class="mt-1.5 text-xl font-bold">
        <span class="text-ink-3">&gt;</span> bonjour {{ firstName
        }}<span class="caret" aria-hidden="true" />
      </h2>
      <p class="mt-1.5 text-[0.82rem] text-ink-2">Voici l'état de votre espace de travail.</p>
    </div>

    <div v-if="loading" class="flex justify-center py-20">
      <BaseSpinner class="size-8 text-ink" />
    </div>

    <template v-else-if="overview">
      <!-- ====================== DEMANDE ATTENTION ====================== -->
      <section>
        <h3 class="mb-4 text-[0.95rem] font-semibold">demande attention</h3>

        <!-- L'absence d'alerte est une information, pas un vide : la dire
             explicitement évite de laisser croire au chargement en cours. -->
        <div
          v-if="attention.length === 0"
          class="card flex items-center gap-3 px-4 py-3.5 text-[0.82rem] text-ink-2"
        >
          <AppIcon name="check" :size="16" class="shrink-0 text-moss" />
          Rien ne demande d'action : aucun déploiement en échec, aucune erreur non résolue, aucune
          échéance dépassée.
        </div>

        <ul v-else class="card divide-y divide-line overflow-hidden">
          <li v-for="item in attention" :key="item.id" data-anim="attention">
            <RouterLink
              :to="modulePath(item.module)"
              class="flex items-center gap-3 px-4 py-2.5 transition hover:bg-raised"
            >
              <!-- Une pastille de la couleur du motif : l'identité passe par
                   la marque, jamais par la couleur du texte. -->
              <span
                class="size-2 shrink-0 rounded-pill"
                :class="reasonOf(item).dot"
                aria-hidden="true"
              />

              <span class="hidden w-24 shrink-0 text-[0.72rem] text-ink-3 sm:block">
                {{ moduleName(item.module) }}
              </span>

              <!-- La référence dit QUOI sans ouvrir : une branche Git, un
                   numéro de ticket, un nombre d'occurrences. Trop étroite,
                   elle ne dirait plus rien — d'où la largeur qui suit
                   l'espace disponible. -->
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

      <!-- ======================= ÉTAT DES MODULES ======================= -->
      <!-- Pleine largeur : la spirale est un carré, une colonne étroite
           l'étirerait sur toute la hauteur de la page. -->
      <ModuleGallery :modules="overview.modules" />

      <!-- ======================= ACTIVITÉ RÉCENTE ======================= -->
      <section>
        <h3 class="mb-4 text-[0.95rem] font-semibold">activité récente</h3>

        <div class="card overflow-hidden">
          <EmptyState
            v-if="!overview.recent.length"
            title="Aucune activité"
            description="Les éléments que vous créerez apparaîtront ici."
          />

          <ul v-else class="divide-y divide-line">
            <li
              v-for="item in overview.recent"
              :key="`${item.module}-${item.id}`"
              data-anim="recent"
            >
              <RouterLink
                :to="modulePath(item.module)"
                class="flex items-center gap-3 px-4 py-2.5 transition hover:bg-raised"
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
