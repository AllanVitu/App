<script setup>
/**
 * Barre d'état, en pied de fenêtre.
 *
 * Affiche qui est connecté, l'état de la liaison avec l'API et un raccourci
 * de recherche. Le rôle est le même que dans un éditeur de code : des
 * informations qu'on ne lit pas, mais qu'on est rassuré de voir.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

import { useAuthStore } from '@/stores/auth'
import { useModulesStore } from '@/stores/modules'

const auth = useAuthStore()
const modules = useModulesStore()

/** Coupure réseau : l'utilisateur doit comprendre pourquoi plus rien ne répond. */
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

const handle = computed(() => auth.user?.email?.split('@')[0] ?? 'invité')

/**
 * Total du travail ouvert, tous modules confondus.
 *
 * Chaque module fournit le chiffre qui a un sens chez lui — tickets ouverts
 * ici, éléments là — et c'est leur somme qui est affichée. L'étiquette dit
 * donc « en cours » et non « éléments » : depuis que les modules ne partagent
 * plus une table unique, « éléments » désignait une addition de choses de
 * natures différentes.
 */
const openCount = computed(() =>
  modules.items.reduce((total, module) => total + (module.items_count ?? 0), 0),
)
</script>

<template>
  <footer
    class="flex h-8 shrink-0 items-center gap-3 border-t border-line bg-panel px-3 text-[0.7rem] tracking-wide text-ink-3"
  >
    <span class="flex items-center gap-1.5">
      <span class="size-1.5" :class="online ? 'bg-moss' : 'bg-brick'" aria-hidden="true" />
      <span class="text-ink-2">{{ handle }}</span>
      <span class="hidden sm:inline">@saas</span>
    </span>

    <span v-if="!online" class="text-brick">hors ligne</span>

    <span class="ml-auto hidden items-center gap-4 md:flex">
      <span>{{ modules.items.length }} modules</span>
      <span>{{ openCount }} en cours</span>
    </span>

    <span class="ml-auto md:ml-0">v1.0.0</span>
  </footer>
</template>
