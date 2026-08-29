<script setup>
/**
 * Tableau de bord.
 *
 * Un seul appel (`GET /api/dashboard`) fournit les indicateurs, les modules
 * et l'activité récente : l'écran s'affiche en un aller-retour réseau.
 */
import { computed, nextTick, onMounted, ref } from 'vue'

import { gsap } from '@/animations/gsap'
import AppIcon from '@/components/AppIcon.vue'
import ModuleGallery from '@/components/ModuleGallery.vue'
import TechnicalDiagram from '@/components/TechnicalDiagram.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useGsapContext } from '@/composables/useGsap'
import { dashboardApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { formatRelative } from '@/utils/format'

const auth = useAuthStore()
const ui = useUiStore()

const overview = ref(null)
const loading = ref(true)

const firstName = computed(() => auth.user?.full_name?.split(' ')[0] ?? '')

const cards = computed(() => {
  const stats = overview.value?.stats

  if (!stats) return []

  return [
    {
      key: 'total',
      label: 'Éléments',
      value: stats.total,
      icon: 'inbox',
      tone: 'text-ink bg-raised',
    },
    {
      key: 'active',
      label: 'Actifs',
      value: stats.active,
      icon: 'check',
      tone: 'text-moss bg-moss-bg',
    },
    {
      key: 'draft',
      label: 'Brouillons',
      value: stats.draft,
      icon: 'pencil',
      tone: 'text-ink-2 bg-raised',
    },
    {
      key: 'due_soon',
      label: 'Échéances 7 j',
      value: stats.due_soon,
      icon: 'clock',
      tone: 'text-ochre bg-ochre-bg',
    },
  ]
})

const { root, run } = useGsapContext()

/**
 * Entrée du tableau de bord, jouée APRÈS l'arrivée des données : animer un
 * squelette vide puis remplacer le contenu produirait deux mouvements
 * successifs, illisibles.
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
      '[data-anim="stat"]',
      { y: 18, opacity: 0 },
      { y: 0, opacity: 1, stagger: 0.06, overwrite: 'auto' },
      0.1,
    )
    timeline.fromTo(
      '[data-anim="recent"]',
      { x: 16, opacity: 0 },
      { x: 0, opacity: 1, stagger: 0.05, overwrite: 'auto' },
      0.35,
    )

    // Compteurs : l'ease « slow » passe vite sur les valeurs intermédiaires
    // et s'attarde sur le chiffre final, seul réellement lisible.
    gsap.utils.toArray('[data-count]').forEach((element) => {
      const target = Number(element.dataset.count)
      const counter = { value: 0 }

      gsap.to(counter, {
        value: target,
        duration: 1.2,
        ease: 'slow(0.5, 0.8, false)',
        snap: { value: 1 },
        onUpdate: () => {
          element.textContent = String(counter.value)
        },
      })
    })
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
      <!-- Indicateurs -->
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div v-for="card in cards" :key="card.key" data-anim="stat" class="card p-5">
          <div class="flex items-center justify-between">
            <p class="text-sm font-medium text-ink-2">{{ card.label }}</p>
            <div class="flex size-9 items-center justify-center" :class="card.tone">
              <AppIcon :name="card.icon" :size="18" />
            </div>
          </div>
          <p :data-count="card.value" class="mt-3 text-3xl font-bold tabular-nums">
            {{ card.value }}
          </p>
        </div>
      </div>

      <!-- Modules : la galerie gère ses deux dispositions et son entrée.
           Pleine largeur — la spirale est un carré, et une colonne étroite
           l'étirait sur toute la hauteur de la page. -->
      <ModuleGallery :modules="overview.modules" />

      <div>
        <!-- Activité récente -->
        <section>
          <h3 class="mb-4 text-base font-semibold">Activité récente</h3>

          <div class="card overflow-hidden">
            <EmptyState
              v-if="!overview.recent.length"
              title="Aucune activité"
              description="Les éléments que vous créerez apparaîtront ici."
            />

            <ul v-else class="divide-y divide-line">
              <li v-for="item in overview.recent" :key="item.id" data-anim="recent">
                <RouterLink
                  :to="{ name: 'module', params: { slug: item.module_slug } }"
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
      </div>
    </template>
  </div>
</template>
