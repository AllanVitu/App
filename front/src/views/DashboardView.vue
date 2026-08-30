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
import TechnicalDiagram from '@/components/TechnicalDiagram.vue'
import TicketPriorityIcon from '@/components/tickets/TicketPriorityIcon.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useGsapContext } from '@/composables/useGsap'
import { dashboardApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { formatRelative } from '@/utils/format'
import { modulePath } from '@/utils/modules'
import { formatDue } from '@/utils/tickets'

const auth = useAuthStore()
const ui = useUiStore()

const overview = ref(null)
const loading = ref(true)

const firstName = computed(() => auth.user?.full_name?.split(' ')[0] ?? '')

const attention = computed(() => overview.value?.attention ?? [])

/** Formulation du motif — le retard est un fait, l'urgence une intention. */
const REASONS = {
  overdue: { label: 'en retard', tone: 'text-brick' },
  urgent: { label: 'urgent', tone: 'text-ochre' },
}

const reasonOf = (item) => REASONS[item.reason] ?? REASONS.urgent

const { root, run } = useGsapContext()

/**
 * Entrée, jouée APRÈS l'arrivée des données : animer un squelette vide puis
 * remplacer le contenu produirait deux mouvements successifs, illisibles.
 */
function playIntro() {
  run(() => {
    const timeline = gsap.timeline()

    // Le prénom se stabilise : signale que la donnée vient d'être chargée.
    timeline.to('[data-anim="greeting"]', {
      duration: 0.9,
      scrambleText: { text: firstName.value, chars: 'upperAndLowerCase', speed: 0.5 },
    })

    // fromTo plutôt que from : les deux extrémités sont explicites. Un tween
    // `from` déduit son état d'arrivée de la valeur courante au moment du
    // rendu — si l'élément a déjà été touché par une autre animation, il
    // mémorise 0 comme arrivée et reste invisible.
    timeline.fromTo(
      '[data-anim="attention"]',
      { x: -12, opacity: 0 },
      { x: 0, opacity: 1, stagger: 0.05, overwrite: 'auto' },
      0.1,
    )
    timeline.fromTo(
      '[data-anim="recent"]',
      { x: 16, opacity: 0 },
      { x: 0, opacity: 1, stagger: 0.05, overwrite: 'auto' },
      0.3,
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
    <!-- Accueil : l'invite de commande donne le ton, le diagramme occupe le
         vide à droite sans réclamer l'attention. -->
    <div class="flex items-center justify-between gap-8">
      <div class="min-w-0 max-w-lg">
        <p class="label-caps">session ouverte</p>
        <h2 class="mt-1.5 text-xl font-bold">
          <span class="text-ink-3">&gt;</span> bonjour
          <span data-anim="greeting">{{ firstName }}</span
          ><span class="caret" aria-hidden="true" />
        </h2>
        <p class="mt-1.5 text-[0.82rem] text-ink-2">Voici l'état de votre espace de travail.</p>
      </div>

      <!-- Décoratif : masqué sous md, où la largeur doit aller au contenu. -->
      <div class="hidden size-32 shrink-0 opacity-80 md:block lg:size-40" aria-hidden="true">
        <TechnicalDiagram :satellites="5" :speed="0.7" />
      </div>
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
          Rien ne demande d'action : aucune échéance dépassée, aucun ticket urgent.
        </div>

        <ul v-else class="card divide-y divide-line overflow-hidden">
          <li v-for="item in attention" :key="item.id" data-anim="attention">
            <RouterLink
              :to="modulePath('tickets')"
              class="flex items-center gap-3 px-4 py-2.5 transition hover:bg-raised"
            >
              <TicketPriorityIcon :priority="item.priority" class="shrink-0" />

              <span class="w-10 shrink-0 font-mono text-[0.72rem] tabular-nums text-ink-3">
                {{ item.number }}
              </span>

              <span class="min-w-0 flex-1 truncate text-[0.84rem]">{{ item.title }}</span>

              <span class="shrink-0 text-[0.72rem] tabular-nums" :class="reasonOf(item).tone">
                {{ item.reason === 'overdue' ? formatDue(item) : reasonOf(item).label }}
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
            <li v-for="item in overview.recent" :key="item.id" data-anim="recent">
              <RouterLink
                :to="modulePath(item.module_slug)"
                class="flex items-start gap-3 px-4 py-3 transition hover:bg-raised"
              >
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-medium">{{ item.title }}</p>
                  <p class="mt-0.5 text-xs text-ink-2">
                    {{ item.module_name }} · {{ formatRelative(item.updated_at) }}
                  </p>
                </div>
                <BaseBadge :status="item.status" />
              </RouterLink>
            </li>
          </ul>
        </div>
      </section>
    </template>
  </div>
</template>
