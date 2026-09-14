<script setup>
/**
 * Menu latéral : la marque, l'espace, les lignes, puis ce qui n'est pas une
 * ligne.
 *
 * Les modules y sont des « lignes », le vocabulaire de Relais : chacun a sa
 * couleur, qui dit OÙ l'on est et jamais comment ça va (cf. utils/modules).
 * La ligne active occupe toute sa hauteur ; les autres restent présentes,
 * plus courtes et plus pâles, comme sur un plan où l'on repère sa ligne sans
 * effacer les autres.
 *
 * Sous 1024 px, le menu devient un tiroir : il s'ouvre depuis la barre du
 * haut, se ferme d'un glissement ou d'un toucher à côté, et se referme de
 * lui-même quand on choisit une destination.
 */
import { ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useRoute } from 'vue-router'

import AppIcon from '@/components/AppIcon.vue'
import RelaisMark from '@/components/RelaisMark.vue'
import WorkspaceSwitcher from '@/components/WorkspaceSwitcher.vue'
import UserAvatar from '@/components/ui/UserAvatar.vue'
import { useDrawerGestures } from '@/composables/useDrawerGestures'
import { useAuthStore } from '@/stores/auth'
import { useModulesStore } from '@/stores/modules'
import { useUiStore } from '@/stores/ui'
import { moduleLine, modulePath } from '@/utils/modules'

const route = useRoute()
const auth = useAuthStore()
const ui = useUiStore()
const modulesStore = useModulesStore()

const { items: modules, loading } = storeToRefs(modulesStore)
const { sidebarOpen } = storeToRefs(ui)

const aside = ref(null)

useDrawerGestures(aside, {
  isOpen: () => sidebarOpen.value,
  setOpen: (open) => ui.toggleSidebar(open),
})

const linkBase = 'flex h-9 items-center gap-3 rounded-card px-2.5 text-[0.875rem] transition-colors'
const linkIdle = 'text-ink-2 hover:bg-raised hover:text-ink'
const linkActive = 'bg-raised font-semibold text-ink'

/** Ce qui n'est pas une ligne : l'équipe, le journal, les réglages. */
const ESPACE = [
  { name: 'team', label: 'Équipe', icon: 'users' },
  { name: 'history', label: 'Historique', icon: 'clock' },
  { name: 'settings', label: 'Paramètres', icon: 'settings' },
]

const close = () => ui.toggleSidebar(false)
const onModule = (slug) => route.path === modulePath(slug)
</script>

<template>
  <div v-if="sidebarOpen" class="fixed inset-0 z-30 bg-ink/40 lg:hidden" @click="close" />

  <aside
    ref="aside"
    class="fixed inset-y-0 left-0 z-40 flex w-62 shrink-0 flex-col border-r border-line bg-panel transition-transform duration-200 lg:static lg:z-auto lg:translate-x-0"
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
  >
    <!-- Même hauteur que la barre du haut : les deux filets se prolongent. -->
    <RouterLink
      :to="{ name: 'dashboard' }"
      class="flex h-16 shrink-0 items-center gap-3 border-b border-line px-4.5"
      aria-label="Relais, tableau de bord"
      @click="close"
    >
      <RelaisMark class="size-8" />
      <span class="text-base font-extrabold tracking-[-0.01em]">Relais</span>
    </RouterLink>

    <!-- L'espace AVANT les lignes : tout ce qui suit lui appartient. Le
         nommer d'abord, c'est l'ordre dans lequel la question se pose. -->
    <WorkspaceSwitcher class="shrink-0" />

    <nav class="flex-1 overflow-y-auto pb-3 pt-3.5" aria-label="Navigation principale">
      <div class="px-2.5">
        <RouterLink
          :to="{ name: 'dashboard' }"
          :class="[linkBase, route.name === 'dashboard' ? linkActive : linkIdle]"
          @click="close"
        >
          <AppIcon name="layout-grid" :size="16" />
          Tableau de bord
        </RouterLink>
      </div>

      <p class="label-caps px-5 pb-2 pt-5.5">Lignes</p>

      <div v-if="loading && !modules.length" class="flex flex-col gap-1.5 px-5">
        <div v-for="n in 5" :key="n" class="h-6 animate-pulse rounded-card bg-raised" />
      </div>

      <div v-else class="flex flex-col gap-0.5 px-2.5">
        <RouterLink
          v-for="module in modules"
          :key="module.id"
          :to="modulePath(module.slug)"
          :class="[linkBase, onModule(module.slug) ? linkActive : linkIdle]"
          @click="close"
        >
          <span
            class="ligne transition-all"
            :class="[moduleLine(module.slug), onModule(module.slug) ? 'h-5' : 'h-3.5 opacity-60']"
            aria-hidden="true"
          />
          <span class="min-w-0 flex-1 truncate">{{ module.name }}</span>
          <span v-if="module.items_count" class="font-mono text-xs text-ink-3 tabular-nums">
            {{ module.items_count }}
          </span>
        </RouterLink>
      </div>
    </nav>

    <div class="flex shrink-0 flex-col gap-0.5 px-2.5 pb-2.5">
      <RouterLink
        v-for="entry in ESPACE"
        :key="entry.name"
        :to="{ name: entry.name }"
        :class="[linkBase, route.name === entry.name ? linkActive : linkIdle]"
        @click="close"
      >
        <AppIcon :name="entry.icon" :size="16" />
        {{ entry.label }}
      </RouterLink>
    </div>

    <RouterLink
      :to="{ name: 'profile' }"
      class="flex shrink-0 items-center gap-2.5 border-t border-line px-4.5 py-3 transition-colors hover:bg-raised"
      :class="route.name === 'profile' ? 'bg-raised' : ''"
      @click="close"
    >
      <UserAvatar :name="auth.user?.full_name ?? ''" :src="auth.user?.avatar_url" size="sm" />
      <span class="min-w-0 flex-1">
        <span class="block truncate text-[0.8125rem] font-semibold">{{
          auth.user?.full_name
        }}</span>
        <span class="block truncate text-[0.72rem] text-ink-3">{{ auth.user?.email }}</span>
      </span>
    </RouterLink>
  </aside>
</template>
