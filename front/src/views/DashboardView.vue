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
import ShaderBackground from '@/components/ShaderBackground.vue'
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
    { key: 'total', label: 'Éléments', value: stats.total, icon: 'inbox', tone: 'text-brand-600 bg-brand-50 dark:bg-brand-500/10 dark:text-brand-400' },
    { key: 'active', label: 'Actifs', value: stats.active, icon: 'check', tone: 'text-emerald-600 bg-emerald-50 dark:bg-emerald-500/10 dark:text-emerald-400' },
    { key: 'draft', label: 'Brouillons', value: stats.draft, icon: 'pencil', tone: 'text-slate-600 bg-slate-100 dark:bg-slate-800 dark:text-slate-300' },
    { key: 'due_soon', label: 'Échéances 7 j', value: stats.due_soon, icon: 'clock', tone: 'text-amber-600 bg-amber-50 dark:bg-amber-500/10 dark:text-amber-400' },
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
      '[data-anim="module-card"]',
      { y: 22, opacity: 0 },
      { y: 0, opacity: 1, stagger: 0.07, overwrite: 'auto' },
      0.25,
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
    <!-- Bandeau d'accueil -->
    <div class="relative overflow-hidden rounded-xl bg-brand-900 px-6 py-8 text-white sm:px-8">
      <ShaderBackground
        color1="#207bd6"
        color2="#910aff"
        color3="#af38ff"
        :brightness="1.35"
        :u-speed="0.22"
        :u-density="0.7"
        :u-frequency="5.5"
        :u-amplitude="7"
        :u-strength="0.35"
        :rotation-z="140"
        :camera-zoom="9"
        :c-distance="1.5"
        grain="on"
      />
      <div class="pointer-events-none absolute inset-0 bg-slate-950/35" />

      <div class="relative">
        <h2 class="text-2xl font-bold tracking-tight">
          Bonjour <span data-anim="greeting">{{ firstName }}</span> 👋
        </h2>
        <p class="mt-1 text-sm text-brand-50/90">Voici l'état de votre espace de travail.</p>
      </div>
    </div>

    <div v-if="loading" class="flex justify-center py-20">
      <BaseSpinner class="size-8 text-brand-600" />
    </div>

    <template v-else-if="overview">
      <!-- Indicateurs -->
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div v-for="card in cards" :key="card.key" data-anim="stat" class="card p-5">
          <div class="flex items-center justify-between">
            <p class="text-sm font-medium text-slate-500">{{ card.label }}</p>
            <div class="flex size-9 items-center justify-center rounded-lg" :class="card.tone">
              <AppIcon :name="card.icon" :size="18" />
            </div>
          </div>
          <p :data-count="card.value" class="mt-3 text-3xl font-bold tabular-nums">{{ card.value }}</p>
        </div>
      </div>

      <div class="grid gap-6 lg:grid-cols-3">
        <!-- Modules -->
        <section class="lg:col-span-2">
          <h3 class="mb-4 text-base font-semibold">Vos modules</h3>

          <div class="grid gap-4 sm:grid-cols-2">
            <RouterLink
              v-for="module in overview.modules"
              :key="module.id"
              :to="{ name: 'module', params: { slug: module.slug } }"
              data-anim="module-card"
              class="card group p-5 transition hover:border-brand-300 hover:shadow-md dark:hover:border-brand-500/50"
            >
              <div class="flex items-start justify-between">
                <div
                  class="flex size-10 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400"
                >
                  <AppIcon :name="module.icon" />
                </div>
                <AppIcon
                  name="arrow-right"
                  :size="18"
                  class="text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-brand-500"
                />
              </div>

              <p class="mt-4 font-semibold">{{ module.name }}</p>
              <p class="mt-1 line-clamp-2 text-sm text-slate-500">{{ module.description }}</p>
              <p class="mt-3 text-xs font-medium text-slate-400">
                {{ module.items_count }} élément{{ module.items_count > 1 ? 's' : '' }}
              </p>
            </RouterLink>
          </div>
        </section>

        <!-- Activité récente -->
        <section>
          <h3 class="mb-4 text-base font-semibold">Activité récente</h3>

          <div class="card overflow-hidden">
            <EmptyState
              v-if="!overview.recent.length"
              title="Aucune activité"
              description="Les éléments que vous créerez apparaîtront ici."
            />

            <ul v-else class="divide-y divide-slate-200 dark:divide-slate-800">
              <li v-for="item in overview.recent" :key="item.id" data-anim="recent">
                <RouterLink
                  :to="{ name: 'module', params: { slug: item.module_slug } }"
                  class="flex items-start gap-3 px-4 py-3 transition hover:bg-slate-50 dark:hover:bg-slate-800/50"
                >
                  <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">{{ item.title }}</p>
                    <p class="mt-0.5 text-xs text-slate-500">
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
