<script setup>
/**
 * Menu latéral.
 *
 * Les entrées « Modules » proviennent de l'API : elles ne sont pas codées en
 * dur. Ajouter une ligne dans la table `modules` fait apparaître un module
 * ici, sans modification du front.
 *
 * Sur mobile, le menu devient un tiroir superposé piloté par ui.sidebarOpen.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'

import { Draggable, Observer, gsap } from '@/animations/gsap'
import AppIcon from '@/components/AppIcon.vue'
import { useAuthStore } from '@/stores/auth'
import { useModulesStore } from '@/stores/modules'
import { useUiStore } from '@/stores/ui'

const auth = useAuthStore()
const ui = useUiStore()
const modulesStore = useModulesStore()

const { items: modules, loading } = storeToRefs(modulesStore)
const { sidebarOpen } = storeToRefs(ui)

const aside = ref(null)

/**
 * Gestes tactiles du tiroir (mobile uniquement).
 *
 * L'ouverture/fermeture reste pilotée par les classes CSS : si ce code ne
 * s'exécute pas, le menu fonctionne toujours au bouton. Les gestes ne sont
 * qu'une couche supplémentaire.
 */
let matchMedia = null

onMounted(() => {
  matchMedia = gsap.matchMedia()

  matchMedia.add('(max-width: 1023px)', () => {
    // --- Fermeture par glissement du tiroir lui-même -----------------------
    const [draggable] = Draggable.create(aside.value, {
      type: 'x',
      // Vers la gauche uniquement : tirer vers la droite n'aurait aucun sens.
      bounds: { minX: -320, maxX: 0 },
      inertia: true,
      dragResistance: 0.15,
      allowNativeTouchScrolling: true,
      onDragEnd() {
        // Fermeture si le tiroir a été suffisamment tiré OU lancé vivement :
        // le geste rapide doit suffire, sans exiger d'aller au bout.
        const flung = this.getDirection('velocity') === 'left' && Math.abs(this.deltaX) > 20

        if (this.x < -70 || flung) {
          ui.toggleSidebar(false)
        }

        // On rend la main aux classes CSS, seule source de vérité de la position.
        gsap.set(aside.value, { clearProps: 'transform' })
      },
    })

    // --- Ouverture par glissement depuis le bord gauche --------------------
    const observer = Observer.create({
      type: 'touch',
      onRight: (self) => {
        // Seul un geste amorcé sur les 28 premiers pixels ouvre le menu :
        // ailleurs, l'utilisateur fait défiler ou navigue.
        if (!sidebarOpen.value && self.startX < 28) {
          ui.toggleSidebar(true)
        }
      },
      tolerance: 40,
    })

    return () => {
      draggable.kill()
      observer.kill()
      gsap.set(aside.value, { clearProps: 'transform' })
    }
  })
})

onBeforeUnmount(() => {
  matchMedia?.revert()
  matchMedia = null
})

const linkBase =
  'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors'
const linkIdle =
  'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100'
const linkActive = 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300'
</script>

<template>
  <!-- Voile d'arrière-plan, mobile uniquement -->
  <div
    v-if="sidebarOpen"
    class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden"
    @click="ui.toggleSidebar(false)"
  />

  <aside
    ref="aside"
    class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-slate-200 bg-white transition-transform duration-200 lg:translate-x-0 dark:border-slate-800 dark:bg-slate-900"
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
  >
    <!-- Marque -->
    <div class="flex h-16 shrink-0 items-center gap-2.5 border-b border-slate-200 px-5 dark:border-slate-800">
      <div class="flex size-8 items-center justify-center rounded-lg bg-brand-600 text-white">
        <AppIcon name="sparkles" :size="18" />
      </div>
      <span class="text-base font-semibold tracking-tight">SaaS App</span>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-5" aria-label="Navigation principale">
      <div class="space-y-1">
        <RouterLink
          :to="{ name: 'dashboard' }"
          :class="[linkBase, $route.name === 'dashboard' ? linkActive : linkIdle]"
          @click="ui.toggleSidebar(false)"
        >
          <AppIcon name="home" :size="18" />
          Accueil
        </RouterLink>
      </div>

      <div>
        <p class="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">
          Modules
        </p>

        <div v-if="loading && !modules.length" class="space-y-1 px-3">
          <div
            v-for="n in 4"
            :key="n"
            class="h-9 animate-pulse rounded-lg bg-slate-100 dark:bg-slate-800"
          />
        </div>

        <div v-else class="space-y-1">
          <RouterLink
            v-for="module in modules"
            :key="module.id"
            :to="{ name: 'module', params: { slug: module.slug } }"
            :class="[
              linkBase,
              $route.params.slug === module.slug ? linkActive : linkIdle,
            ]"
            @click="ui.toggleSidebar(false)"
          >
            <AppIcon :name="module.icon" :size="18" />
            <span class="flex-1 truncate">{{ module.name }}</span>
            <span
              v-if="module.items_count"
              class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-400"
            >
              {{ module.items_count }}
            </span>
          </RouterLink>
        </div>
      </div>

      <div>
        <p class="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">
          Compte
        </p>
        <div class="space-y-1">
          <RouterLink
            :to="{ name: 'profile' }"
            :class="[linkBase, $route.name === 'profile' ? linkActive : linkIdle]"
            @click="ui.toggleSidebar(false)"
          >
            <AppIcon name="user" :size="18" />
            Profil
          </RouterLink>

          <RouterLink
            :to="{ name: 'settings' }"
            :class="[linkBase, $route.name === 'settings' ? linkActive : linkIdle]"
            @click="ui.toggleSidebar(false)"
          >
            <AppIcon name="settings" :size="18" />
            Paramètres
          </RouterLink>
        </div>
      </div>
    </nav>

    <!-- Utilisateur courant -->
    <div class="shrink-0 border-t border-slate-200 p-3 dark:border-slate-800">
      <RouterLink
        :to="{ name: 'profile' }"
        class="flex items-center gap-3 rounded-lg p-2 transition-colors hover:bg-slate-100 dark:hover:bg-slate-800"
        @click="ui.toggleSidebar(false)"
      >
        <div
          class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300"
        >
          {{ auth.initials }}
        </div>
        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-medium">{{ auth.user?.full_name }}</p>
          <p class="truncate text-xs text-slate-500">{{ auth.user?.email }}</p>
        </div>
      </RouterLink>
    </div>
  </aside>
</template>
