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
import { ref } from 'vue'
import { storeToRefs } from 'pinia'

import AppIcon from '@/components/AppIcon.vue'
import { useDrawerGestures } from '@/composables/useDrawerGestures'
import { useAuthStore } from '@/stores/auth'
import { useModulesStore } from '@/stores/modules'
import { useUiStore } from '@/stores/ui'
import { modulePath } from '@/utils/modules'

const auth = useAuthStore()
const ui = useUiStore()
const modulesStore = useModulesStore()

const { items: modules, loading } = storeToRefs(modulesStore)
const { sidebarOpen } = storeToRefs(ui)

const aside = ref(null)

// Glissement pour ouvrir depuis le bord, glissement pour fermer le tiroir.
// L'ouverture/fermeture reste pilotée par les classes CSS : ces gestes ne
// sont qu'une couche de plus, et le bouton fonctionne sans eux.
useDrawerGestures(aside, {
  isOpen: () => sidebarOpen.value,
  setOpen: (open) => ui.toggleSidebar(open),
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
            :to="modulePath(module.slug)"
            :class="[linkBase, $route.path === modulePath(module.slug) ? linkActive : linkIdle]"
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
