<script setup>
/**
 * Layout des pages authentifiées : menu latéral + barre supérieure.
 *
 * Le catalogue des modules est chargé ici, une fois pour toutes les pages
 * enfants ; le titre affiché suit la route courante.
 */
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'

import AppSidebar from '@/components/AppSidebar.vue'
import AppTopbar from '@/components/AppTopbar.vue'
import EmailVerificationBanner from '@/components/EmailVerificationBanner.vue'
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
  <div class="min-h-screen">
    <AppSidebar />

    <div class="lg:pl-64">
      <AppTopbar :title="title" />

      <main class="px-4 py-6 lg:px-8 lg:py-8">
        <EmailVerificationBanner />

        <RouterView v-slot="{ Component }">
          <Transition name="fade" mode="out-in">
            <component :is="Component" />
          </Transition>
        </RouterView>
      </main>
    </div>
  </div>
</template>
