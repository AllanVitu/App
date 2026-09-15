import { ref } from 'vue'

/**
 * « Charger la suite » sur les listes plafonnées.
 *
 * Quatre modules chargent d'un bloc avec un plafond, pour garder leur filtrage
 * local et instantané. On avertissait bien que N lignes étaient masquées, sans
 * jamais donner de chemin vers elles : ce composable est ce chemin.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  LES LIGNES REÇUES SONT DÉDOUBLONNÉES PAR IDENTIFIANT               │
 * │                                                                     │
 * │  Ces listes sont triées sur une clé MUTABLE — « updated_at » pour   │
 * │  les tickets, « last_seen_at » pour les erreurs. Une ligne modifiée │
 * │  entre deux tranches remonte en tête et réapparaît dans la          │
 * │  suivante : sans dédoublonnage, on la verrait deux fois.            │
 * │                                                                     │
 * │  Le cas symétrique — une ligne qui redescend et qu'on saute — reste │
 * │  possible et n'est PAS corrigé ici. Le corriger demanderait une     │
 * │  pagination par curseur sur une clé stable, ce qui changerait le    │
 * │  tri de tous ces écrans. À l'échelle de quelques centaines de       │
 * │  lignes, la dépense ne se justifie pas ; l'omission est assumée,    │
 * │  pas ignorée.                                                       │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * @param {object} options
 * @param {import('vue').Ref<Array>} options.rows   la liste affichée, complétée sur place
 * @param {(offset: number) => Promise<Array>} options.fetch  va chercher la tranche suivante
 * @param {(message: string) => void} [options.onError]
 */
export function useLoadMore({ rows, fetch, onError }) {
  const loadingMore = ref(false)

  async function loadMore() {
    if (loadingMore.value) return

    loadingMore.value = true

    try {
      const suite = await fetch(rows.value.length)
      const connus = new Set(rows.value.map((row) => row.id))

      rows.value = [...rows.value, ...suite.filter((row) => !connus.has(row.id))]
    } catch (error) {
      onError?.(error.message)
    } finally {
      loadingMore.value = false
    }
  }

  return { loadingMore, loadMore }
}
