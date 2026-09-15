<script setup>
/**
 * Barre du haut : où l'on est, de quoi chercher, et le compte.
 *
 * Deux choses en sont parties. L'horloge, qui doublait celle du système. Le
 * chemin brut (« /modules/tickets »), qui parlait la langue du routeur : le
 * fil d'Ariane dit l'espace et l'écran, dans la langue de l'écran.
 *
 * Le champ de recherche n'en est pas un : c'est le bouton de la palette de
 * commandes, qui cherche dans tous les modules à la fois. Il affiche son
 * raccourci, pour qu'on apprenne à se passer de lui.
 *
 * L'indicateur « hors ligne » vivait dans une barre d'état en pied de page,
 * qui a disparu. Il apparaît ici, et seulement quand il a quelque chose à
 * dire : un voyant vert permanent est un voyant qu'on cesse de regarder.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import AppIcon from '@/components/AppIcon.vue'
import SoundToggle from '@/components/SoundToggle.vue'
import ThemeToggle from '@/components/ThemeToggle.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { moduleLine } from '@/utils/modules'

defineProps({
  title: { type: String, default: '' },
})

defineEmits(['search'])

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()

const menuOpen = ref(false)
const loggingOut = ref(false)

/** Sur l'écran d'un module, la couleur de sa ligne précède son nom. */
const ligne = computed(() => {
  const trouve = route.path.match(/^\/modules\/([a-z0-9-]+)/)

  return trouve ? moduleLine(trouve[1]) : null
})

const online = ref(navigator.onLine)

function sync() {
  online.value = navigator.onLine
}

onMounted(() => {
  window.addEventListener('online', sync)
  window.addEventListener('offline', sync)
})

onBeforeUnmount(() => {
  window.removeEventListener('online', sync)
  window.removeEventListener('offline', sync)
})

async function logout() {
  loggingOut.value = true

  try {
    await auth.logout()
    ui.notify('Session fermée.', 'info')
    router.push({ name: 'login' })
  } finally {
    loggingOut.value = false
    menuOpen.value = false
  }
}
</script>

<template>
  <header class="flex h-16 shrink-0 items-center gap-3 border-b border-line bg-paper px-3 lg:px-8">
    <button
      type="button"
      class="flex size-9 items-center justify-center rounded-card text-ink-2 transition-colors hover:text-ink lg:hidden"
      aria-label="Ouvrir le menu"
      @click="ui.toggleSidebar()"
    >
      <AppIcon name="menu" :size="18" />
    </button>

    <nav
      class="flex min-w-0 flex-1 items-center gap-2 text-[0.8125rem] text-ink-3"
      aria-label="Fil d'Ariane"
    >
      <template v-if="auth.organization">
        <span class="hidden truncate sm:inline">{{ auth.organization.name }}</span>
        <span class="hidden sm:inline" aria-hidden="true">/</span>
      </template>
      <span v-if="ligne" class="ligne h-4" :class="ligne" aria-hidden="true" />
      <span class="truncate font-semibold text-ink" aria-current="page">{{
        title || 'Relais'
      }}</span>
    </nav>

    <span
      v-if="!online"
      class="flex shrink-0 items-center gap-1.5 text-xs text-brick"
      role="status"
    >
      <span class="size-1.75 rounded-pill bg-brick" aria-hidden="true" />
      Hors ligne
    </span>

    <button
      type="button"
      class="hidden h-9 w-95 shrink-0 items-center gap-2.5 rounded-card border border-line-2 bg-panel pl-3 pr-2 text-[0.8125rem] text-ink-3 transition-colors hover:border-ink-3 hover:text-ink-2 md:flex"
      @click="$emit('search')"
    >
      <AppIcon name="search" :size="16" class="shrink-0" />
      <span class="flex-1 truncate text-left">Rechercher ou lancer une commande</span>
      <kbd class="rounded-card border border-line-2 px-1.5 font-mono text-[0.6875rem] text-ink-2">
        Ctrl K
      </kbd>
    </button>

    <button
      type="button"
      class="flex size-9 items-center justify-center rounded-card text-ink-2 transition-colors hover:text-ink md:hidden"
      aria-label="Rechercher"
      @click="$emit('search')"
    >
      <AppIcon name="search" :size="17" />
    </button>

    <div class="flex shrink-0 items-center gap-1">
      <SoundToggle />
      <ThemeToggle />
    </div>

    <div class="relative shrink-0">
      <button
        type="button"
        class="flex size-9 items-center justify-center rounded-card border border-line-2 bg-raised text-[0.75rem] font-semibold transition-colors hover:border-ink"
        aria-haspopup="menu"
        :aria-expanded="menuOpen"
        aria-label="Menu du compte"
        @click="menuOpen = !menuOpen"
      >
        {{ auth.initials }}
      </button>

      <div v-if="menuOpen" class="fixed inset-0 z-10" @click="menuOpen = false" />

      <Transition name="fade">
        <div v-if="menuOpen" class="floating absolute right-0 z-20 mt-2 w-60" role="menu">
          <div class="border-b border-line px-3.5 py-3">
            <p class="truncate text-[0.8125rem] font-semibold">{{ auth.user?.full_name }}</p>
            <p class="truncate text-[0.75rem] text-ink-3">{{ auth.user?.email }}</p>
          </div>

          <RouterLink
            :to="{ name: 'profile' }"
            class="flex items-center gap-2.5 px-3.5 py-2.5 text-[0.8125rem] text-ink-2 transition-colors hover:bg-raised hover:text-ink"
            role="menuitem"
            @click="menuOpen = false"
          >
            <AppIcon name="user" :size="15" />
            Profil
          </RouterLink>

          <RouterLink
            :to="{ name: 'settings' }"
            class="flex items-center gap-2.5 px-3.5 py-2.5 text-[0.8125rem] text-ink-2 transition-colors hover:bg-raised hover:text-ink"
            role="menuitem"
            @click="menuOpen = false"
          >
            <AppIcon name="settings" :size="15" />
            Paramètres
          </RouterLink>

          <button
            type="button"
            class="flex w-full items-center gap-2.5 border-t border-line px-3.5 py-2.5 text-left text-[0.8125rem] text-brick transition-colors hover:bg-brick-bg disabled:opacity-50"
            role="menuitem"
            :disabled="loggingOut"
            @click="logout"
          >
            <AppIcon name="logout" :size="15" />
            Se déconnecter
          </button>
        </div>
      </Transition>
    </div>
  </header>
</template>
