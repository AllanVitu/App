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
import WorkspaceSwitcher from '@/components/WorkspaceSwitcher.vue'
import UserAvatar from '@/components/ui/UserAvatar.vue'
import { useDrawerGestures } from '@/composables/useDrawerGestures'
import { useAuthStore } from '@/stores/auth'
import { useModulesStore } from '@/stores/modules'
import { useUiStore } from '@/stores/ui'
import { moduleLine, modulePath } from '@/utils/modules'

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

/**
 * LA BARRE REMPLACE LA BORDURE.
 *
 * Une bordure gauche marquait déjà l'entrée active. Elle devient un vrai
 * BLOC — un élément à part, coloré par la ligne du module — parce que c'est
 * ce que la règle de forme exige : la couleur d'identité n'existe qu'en
 * aplat. Une bordure teintée aurait fait le même effet à l'œil et aurait
 * demandé cinq classes « border-l-mod-* » de plus, pour un dispositif qu'on
 * veut voir grossir ailleurs (en-tête d'écran, fil d'ariane).
 *
 * Elle est présente sur TOUTES les entrées de module, allumée sur l'active et
 * atténuée sur les autres : c'est ce qui en fait un plan de lignes plutôt
 * qu'un simple curseur de sélection.
 */
const linkBase = 'flex items-center gap-2.5 py-1.5 pl-2 pr-3 text-[0.8rem] transition-colors'
const linkIdle = 'text-ink-2 hover:bg-raised hover:text-ink'
const linkActive = 'bg-raised text-ink font-semibold'

/** Les entrées hors modules n'ont pas de ligne : leur repère prend l'encre. */
const repere = (actif) => (actif ? 'bg-ink' : 'bg-transparent')
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
    class="fixed inset-y-0 left-0 z-40 flex w-60 shrink-0 flex-col border-r border-line bg-panel transition-transform duration-200 lg:static lg:z-auto lg:w-52 lg:translate-x-0"
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
  >
    <!-- Marque -->
    <div class="shrink-0 border-b border-line px-3 py-3">
      <div class="flex items-center gap-2">
        <!-- Les cinq lignes réunies : le plan du réseau, en miniature. -->
        <span class="flex h-5 gap-px" aria-hidden="true">
          <span class="ligne w-0.75 bg-mod-backend" />
          <span class="ligne w-0.75 bg-mod-deploiement" />
          <span class="ligne w-0.75 bg-mod-tickets" />
          <span class="ligne w-0.75 bg-mod-supervision" />
          <span class="ligne w-0.75 bg-mod-design" />
        </span>

        <div class="min-w-0">
          <p class="text-[0.86rem] font-extrabold tracking-[0.06em]">SAAS OS</p>
          <p class="text-[0.66rem] text-ink-3">v1.0.0</p>
        </div>
      </div>
    </div>

    <!-- L'ESPACE DE TRAVAIL, JUSTE SOUS LA MARQUE.
         Tout ce qui suit lui appartient : les modules, leurs compteurs, le
         tableau de bord. Le nommer avant de les lister, c'est l'ordre dans
         lequel la question se pose. -->
    <WorkspaceSwitcher class="shrink-0" />

    <!-- Navigation -->
    <nav class="flex-1 space-y-5 overflow-y-auto py-4" aria-label="Navigation principale">
      <div>
        <RouterLink
          :to="{ name: 'dashboard' }"
          :class="[linkBase, $route.name === 'dashboard' ? linkActive : linkIdle]"
          @click="ui.toggleSidebar(false)"
        >
          <span class="ligne h-5" :class="repere($route.name === 'dashboard')" aria-hidden="true" />
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
            <!-- LA LIGNE ACTIVE SE DRESSE, les autres restent des repères.
                 Toutes présentes — c'est ce qui fait un plan de lignes plutôt
                 qu'un curseur de sélection — mais celle où l'on se trouve
                 occupe toute la hauteur de sa ligne et retrouve sa
                 saturation. La différence se lit sans avoir à comparer les
                 teintes entre elles. -->
            <span
              class="ligne transition-all"
              :class="[
                moduleLine(module.slug),
                $route.path === modulePath(module.slug) ? 'h-7' : 'h-3.5 opacity-60',
              ]"
              aria-hidden="true"
            />
            <AppIcon :name="module.icon" :size="15" />
            <span class="flex-1 truncate">{{ module.name.toLowerCase() }}</span>
            <span v-if="module.items_count" class="text-[0.7rem] text-ink-3 tabular-nums">
              {{ module.items_count }}
            </span>
          </RouterLink>
        </div>
      </div>

      <div>
        <p class="label-caps px-3 pb-1.5">espace</p>

        <RouterLink
          :to="{ name: 'team' }"
          :class="[linkBase, $route.name === 'team' ? linkActive : linkIdle]"
          @click="ui.toggleSidebar(false)"
        >
          <span class="ligne h-5" :class="repere($route.name === 'team')" aria-hidden="true" />
          <AppIcon name="users" :size="15" />
          équipe
        </RouterLink>

        <RouterLink
          :to="{ name: 'history' }"
          :class="[linkBase, $route.name === 'history' ? linkActive : linkIdle]"
          @click="ui.toggleSidebar(false)"
        >
          <span class="ligne h-5" :class="repere($route.name === 'history')" aria-hidden="true" />
          <AppIcon name="clock" :size="15" />
          historique
        </RouterLink>
      </div>

      <div>
        <p class="label-caps px-3 pb-1.5">compte</p>

        <RouterLink
          :to="{ name: 'profile' }"
          :class="[linkBase, $route.name === 'profile' ? linkActive : linkIdle]"
          @click="ui.toggleSidebar(false)"
        >
          <span class="ligne h-5" :class="repere($route.name === 'profile')" aria-hidden="true" />
          <AppIcon name="user" :size="15" />
          profil
        </RouterLink>

        <RouterLink
          :to="{ name: 'settings' }"
          :class="[linkBase, $route.name === 'settings' ? linkActive : linkIdle]"
          @click="ui.toggleSidebar(false)"
        >
          <span class="ligne h-5" :class="repere($route.name === 'settings')" aria-hidden="true" />
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
      <UserAvatar :name="auth.user?.full_name ?? ''" :src="auth.user?.avatar_url" size="sm" />
      <span class="min-w-0 flex-1">
        <span class="block truncate text-[0.76rem]">{{ auth.user?.full_name }}</span>
        <span class="block truncate text-[0.68rem] text-ink-3">{{ auth.user?.email }}</span>
      </span>
    </RouterLink>
  </aside>
</template>
