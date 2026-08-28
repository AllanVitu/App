<script setup>
/**
 * Barre supérieure : ouverture du menu sur mobile, bascule de thème et
 * menu utilisateur.
 */
import { ref } from 'vue'
import { useRouter } from 'vue-router'

import AppIcon from '@/components/AppIcon.vue'
import ThemeToggle from '@/components/ThemeToggle.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

defineProps({
  title: { type: String, default: '' },
})

const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()

const menuOpen = ref(false)
const loggingOut = ref(false)

async function logout() {
  loggingOut.value = true

  try {
    await auth.logout()
    ui.notify('Vous êtes déconnecté.', 'info')
    router.push({ name: 'login' })
  } finally {
    loggingOut.value = false
    menuOpen.value = false
  }
}
</script>

<template>
  <header
    class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/80 px-4 backdrop-blur-sm lg:px-8 dark:border-slate-800 dark:bg-slate-900/80"
  >
    <button
      type="button"
      class="rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 lg:hidden dark:hover:bg-slate-800"
      aria-label="Ouvrir le menu"
      @click="ui.toggleSidebar()"
    >
      <AppIcon name="menu" />
    </button>

    <h1 class="flex-1 truncate text-lg font-semibold">{{ title }}</h1>

    <ThemeToggle />

    <!-- Menu utilisateur -->
    <div class="relative">
      <button
        type="button"
        class="flex size-9 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700 transition hover:bg-brand-200 dark:bg-brand-500/15 dark:text-brand-300"
        aria-haspopup="menu"
        :aria-expanded="menuOpen"
        aria-label="Menu du compte"
        @click="menuOpen = !menuOpen"
      >
        {{ auth.initials }}
      </button>

      <!-- Zone transparente : un clic n'importe où referme le menu -->
      <div v-if="menuOpen" class="fixed inset-0 z-10" @click="menuOpen = false" />

      <Transition name="fade">
        <div
          v-if="menuOpen"
          class="card absolute right-0 z-20 mt-2 w-56 overflow-hidden py-1"
          role="menu"
        >
          <div class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
            <p class="truncate text-sm font-medium">{{ auth.user?.full_name }}</p>
            <p class="truncate text-xs text-slate-500">{{ auth.user?.email }}</p>
          </div>

          <RouterLink
            :to="{ name: 'profile' }"
            class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-slate-700 transition hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
            role="menuitem"
            @click="menuOpen = false"
          >
            <AppIcon name="user" :size="16" />
            Mon profil
          </RouterLink>

          <RouterLink
            :to="{ name: 'settings' }"
            class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-slate-700 transition hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
            role="menuitem"
            @click="menuOpen = false"
          >
            <AppIcon name="settings" :size="16" />
            Paramètres
          </RouterLink>

          <button
            type="button"
            class="flex w-full items-center gap-2.5 border-t border-slate-200 px-4 py-2.5 text-left text-sm text-red-600 transition hover:bg-red-50 disabled:opacity-60 dark:border-slate-800 dark:text-red-400 dark:hover:bg-red-500/10"
            role="menuitem"
            :disabled="loggingOut"
            @click="logout"
          >
            <AppIcon name="logout" :size="16" />
            Se déconnecter
          </button>
        </div>
      </Transition>
    </div>
  </header>
</template>
