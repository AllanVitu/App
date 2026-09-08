<script setup>
/**
 * Layout des pages authentifiées.
 *
 * Structure de fenêtre : chaque zone est un panneau distinct séparé par une
 * gouttière, plutôt qu'un flux continu. On lit l'application comme un poste
 * de travail — menu, chemin, contenu, état — et non comme une page web.
 *
 * Le catalogue des modules est chargé ici, une fois pour toutes les pages
 * enfants ; le titre affiché suit la route courante.
 */
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'

import AppSidebar from '@/components/AppSidebar.vue'
import CommandPalette from '@/components/CommandPalette.vue'
import AppStatusBar from '@/components/AppStatusBar.vue'
import AppTopbar from '@/components/AppTopbar.vue'
import EmailVerificationBanner from '@/components/EmailVerificationBanner.vue'
import SoundGate from '@/components/SoundGate.vue'
import { useModulesStore } from '@/stores/modules'
import { useUiStore } from '@/stores/ui'

const route = useRoute()
const modulesStore = useModulesStore()
const ui = useUiStore()

const title = computed(() => {
  if (route.name === 'module') {
    return modulesStore.bySlug(route.params.slug)?.name ?? 'Module'
  }

  return route.meta.title ?? ''
})

onMounted(async () => {
  try {
    await modulesStore.load()
  } catch (error) {
    ui.notify(error.message, 'error')
  }
})
</script>

<template>
  <div class="flex h-screen flex-col gap-1.5 bg-paper p-1.5 lg:gap-2 lg:p-2">
    <div class="flex min-h-0 flex-1 gap-1.5 lg:gap-2">
      <AppSidebar />

      <div class="flex min-w-0 flex-1 flex-col gap-1.5 lg:gap-2">
        <AppTopbar :title="title" />

        <!-- Le défilement vit DANS le panneau, pas sur la page : le cadre
             reste fixe, comme une fenêtre d'application. -->
        <main class="panel min-h-0 flex-1 overflow-y-auto px-4 py-5 lg:px-7 lg:py-6">
          <EmailVerificationBanner />

          <RouterView v-slot="{ Component }">
            <Transition name="fade" mode="out-in">
              <component :is="Component" />
            </Transition>
          </RouterView>
        </main>
      </div>
    </div>

    <AppStatusBar />

    <!-- Palette de recherche, montée UNE FOIS pour toute l'application :
         elle écoute Ctrl/⌘ + K sur le document, donc depuis n'importe quel
         écran, et cherche dans les cinq modules à la fois. -->
    <CommandPalette />

    <!-- Le choix sonore n'est proposé qu'une fois entré : les écrans
         d'identification restent muets. -->
    <SoundGate />
  </div>
</template>
