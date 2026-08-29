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

const linkBase = 'flex items-center gap-2.5 px-3 py-1.5 text-[0.8rem] transition-colors border-l-2'
const linkIdle = 'border-l-transparent text-ink-2 hover:bg-raised hover:text-ink'
const linkActive = 'border-l-ink bg-raised text-ink font-semibold'
</script>

<template>
  <!-- Voile d'arrière-plan, mobile uniquement -->
  <div
    v-if="sidebarOpen"
    class="fixed inset-0 z-30 bg-ink/40 lg:hidden"
    @click="ui.toggleSidebar(false)"
  />

  <aside
    ref="aside"
    class="panel fixed inset-y-0 left-0 z-40 flex w-60 shrink-0 flex-col transition-transform duration-200 lg:static lg:z-auto lg:w-52 lg:translate-x-0"
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
  >
    <!-- Marque -->
    <div class="shrink-0 border-b border-line px-3 py-3">
      <p class="text-[0.9rem] font-bold tracking-[0.08em]">SAAS OS</p>
      <p class="text-[0.68rem] text-ink-3">v1.0.0</p>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 space-y-5 overflow-y-auto py-4" aria-label="Navigation principale">
      <div>
        <RouterLink
          :to="{ name: 'dashboard' }"
          :class="[linkBase, $route.name === 'dashboard' ? linkActive : linkIdle]"
          @click="ui.toggleSidebar(false)"
        >
          <AppIcon name="home" :size="15" />
          accueil
        </RouterLink>
      </div>

      <div>
        <p class="label-caps px-3 pb-1.5">modules</p>

        <div v-if="loading && !modules.length" class="space-y-1 px-3">
          <div v-for="n in 4" :key="n" class="h-6 animate-pulse bg-raised" />
        </div>

        <div v-else>
          <RouterLink
            v-for="module in modules"
            :key="module.id"
            :to="{ name: 'module', params: { slug: module.slug } }"
            :class="[linkBase, $route.params.slug === module.slug ? linkActive : linkIdle]"
            @click="ui.toggleSidebar(false)"
          >
            <AppIcon :name="module.icon" :size="15" />
            <span class="flex-1 truncate">{{ module.name.toLowerCase() }}</span>
            <span v-if="module.items_count" class="text-[0.7rem] text-ink-3 tabular-nums">
              {{ module.items_count }}
            </span>
          </RouterLink>
        </div>
      </div>

      <div>
        <p class="label-caps px-3 pb-1.5">compte</p>

        <RouterLink
          :to="{ name: 'profile' }"
          :class="[linkBase, $route.name === 'profile' ? linkActive : linkIdle]"
          @click="ui.toggleSidebar(false)"
        >
          <AppIcon name="user" :size="15" />
          profil
        </RouterLink>

        <RouterLink
          :to="{ name: 'settings' }"
          :class="[linkBase, $route.name === 'settings' ? linkActive : linkIdle]"
          @click="ui.toggleSidebar(false)"
        >
          <AppIcon name="settings" :size="15" />
          paramètres
        </RouterLink>
      </div>
    </nav>

    <!-- Utilisateur courant -->
    <RouterLink
      :to="{ name: 'profile' }"
      class="flex shrink-0 items-center gap-2.5 border-t border-line px-3 py-2.5 transition-colors hover:bg-raised"
      @click="ui.toggleSidebar(false)"
    >
      <span
        class="flex size-7 shrink-0 items-center justify-center border border-line bg-raised text-[0.68rem] font-semibold"
      >
        {{ auth.initials }}
      </span>
      <span class="min-w-0 flex-1">
        <span class="block truncate text-[0.76rem]">{{ auth.user?.full_name }}</span>
        <span class="block truncate text-[0.68rem] text-ink-3">{{ auth.user?.email }}</span>
      </span>
    </RouterLink>
  </aside>
</template>
