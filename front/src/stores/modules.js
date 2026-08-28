import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

import { modulesApi } from '@/services/api'

/**
 * Catalogue des modules accessibles à l'utilisateur.
 *
 * Chargé une seule fois puis mis en cache : le menu latéral, le tableau de
 * bord et les vues de module s'appuient tous dessus sans multiplier les
 * appels réseau.
 */
export const useModulesStore = defineStore('modules', () => {
  const items = ref([])
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref(null)

  /** Retrouve un module par son slug d'URL. */
  const bySlug = computed(() => (slug) => items.value.find((module) => module.slug === slug) ?? null)

  async function load({ force = false } = {}) {
    if (loaded.value && !force) return items.value

    loading.value = true
    error.value = null

    try {
      items.value = await modulesApi.list()
      loaded.value = true
    } catch (err) {
      error.value = err.message
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Met à jour le compteur d'éléments d'un module après une création ou une
   * suppression, sans recharger toute la liste.
   */
  function adjustCount(slug, delta) {
    const module = items.value.find((entry) => entry.slug === slug)

    if (module) {
      module.items_count = Math.max(0, (module.items_count ?? 0) + delta)
    }
  }

  function reset() {
    items.value = []
    loaded.value = false
    error.value = null
  }

  return { items, loading, loaded, error, bySlug, load, adjustCount, reset }
})
