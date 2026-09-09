<script setup>
/**
 * Barre de chemin.
 *
 * Reprend la logique d'un explorateur de fichiers : à gauche le chemin
 * courant, à droite l'heure. C'est ce qui donne à l'application son
 * caractère de poste de travail plutôt que de site web — on sait en
 * permanence *où* l'on est.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import AppIcon from '@/components/AppIcon.vue'
import SoundToggle from '@/components/SoundToggle.vue'
import ThemeToggle from '@/components/ThemeToggle.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()

const menuOpen = ref(false)
const loggingOut = ref(false)

/** Chemin lisible : la racine s'écrit « /accueil » plutôt que « / ». */
const path = computed(() => (route.path === '/' ? '/accueil' : route.path))

// --- Horloge ----------------------------------------------------------------
const now = ref(new Date())
let timer = null

const time = computed(() => now.value.toLocaleTimeString('fr-FR', { hour12: false }))

const day = computed(() =>
  now.value.toLocaleDateString('fr-FR', { weekday: 'short', day: '2-digit', month: 'short' }),
)

function startClock() {
  stopClock()
  timer = setInterval(() => (now.value = new Date()), 1000)
}

function stopClock() {
  clearInterval(timer)
  timer = null
}

/** Onglet masqué : inutile de réveiller le navigateur chaque seconde. */
function onVisibility() {
  if (document.hidden) {
    stopClock()
  } else {
    now.value = new Date()
    startClock()
  }
}

onMounted(() => {
  startClock()
  document.addEventListener('visibilitychange', onVisibility)
})

onBeforeUnmount(() => {
  stopClock()
  document.removeEventListener('visibilitychange', onVisibility)
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
  <header class="flex h-11 shrink-0 items-center gap-2 border-b border-line bg-panel pl-2 pr-1.5">
    <button
      type="button"
      class="p-1.5 text-ink-2 transition-colors hover:text-ink lg:hidden"
      aria-label="Ouvrir le menu"
      @click="ui.toggleSidebar()"
    >
      <AppIcon name="menu" :size="17" />
    </button>

    <!-- Chemin courant -->
    <p class="min-w-0 flex-1 truncate text-[0.78rem] tracking-tight">
      <span class="text-ink-3">{{ path.slice(0, path.lastIndexOf('/') + 1) }}</span
      ><span class="text-ink">{{ path.slice(path.lastIndexOf('/') + 1) }}</span>
    </p>

    <!-- Horloge -->
    <p
      class="hidden shrink-0 items-baseline gap-2 text-[0.75rem] text-ink-2 tabular-nums sm:flex"
      aria-hidden="true"
    >
      <span>{{ time }}</span>
      <span class="text-ink-3">{{ day }}</span>
    </p>

    <div class="mx-1 hidden h-4 w-px bg-line sm:block" />

    <SoundToggle />
    <ThemeToggle />

    <!-- Menu du compte -->
    <div class="relative">
      <button
        type="button"
        class="flex size-7 items-center justify-center border border-line bg-raised text-[0.7rem] font-semibold transition-colors hover:border-ink"
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
          class="panel absolute right-0 z-20 mt-1.5 w-56 border-ink-3"
          role="menu"
        >
          <div class="border-b border-line px-3 py-2.5">
            <p class="truncate text-[0.78rem] font-semibold">{{ auth.user?.full_name }}</p>
            <p class="truncate text-[0.72rem] text-ink-3">{{ auth.user?.email }}</p>
          </div>

          <RouterLink
            :to="{ name: 'profile' }"
            class="flex items-center gap-2.5 px-3 py-2 text-[0.78rem] text-ink-2 transition-colors hover:bg-raised hover:text-ink"
            role="menuitem"
            @click="menuOpen = false"
          >
            <AppIcon name="user" :size="15" />
            profil
          </RouterLink>

          <RouterLink
            :to="{ name: 'settings' }"
            class="flex items-center gap-2.5 px-3 py-2 text-[0.78rem] text-ink-2 transition-colors hover:bg-raised hover:text-ink"
            role="menuitem"
            @click="menuOpen = false"
          >
            <AppIcon name="settings" :size="15" />
            paramètres
          </RouterLink>

          <button
            type="button"
            class="flex w-full items-center gap-2.5 border-t border-line px-3 py-2 text-left text-[0.78rem] text-brick transition-colors hover:bg-brick-bg disabled:opacity-50"
            role="menuitem"
            :disabled="loggingOut"
            @click="logout"
          >
            <AppIcon name="logout" :size="15" />
            se déconnecter
          </button>
        </div>
      </Transition>
    </div>
  </header>
</template>
