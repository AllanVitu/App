import { ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'

import { useLoadMore } from '@/composables/useLoadMore'

/**
 * « Charger la suite ».
 *
 * Quatre modules chargent leurs lignes d'un bloc, avec un plafond, pour garder
 * un filtrage local et instantané. Ce composable est le chemin vers ce qui
 * dépasse le plafond — et surtout le garde-fou contre les doublons que ce
 * chemin provoque : ces listes sont triées sur une clé MUTABLE, une ligne
 * modifiée entre deux tranches remonte en tête et revient dans la suivante.
 *
 * Ces tests valent surtout pour ce que l'écran ne montre pas : le
 * dédoublonnage sur l'état du moment où la réponse arrive, et le double clic
 * qui n'envoie qu'une requête.
 */

/** Une ligne, réduite à ce que le composable regarde. */
const ligne = (id) => ({ id, titre: `Ligne ${id}` })

describe('useLoadMore', () => {
  it('demande la suite à partir du nombre de lignes déjà affichées', async () => {
    const rows = ref([ligne(1), ligne(2), ligne(3)])
    const fetch = vi.fn().mockResolvedValue([ligne(4)])

    const { loadMore } = useLoadMore({ rows, fetch })
    await loadMore()

    // Le décalage est déduit de l'affichage, pas d'un compteur tenu à part :
    // un compteur se désynchronise dès qu'une ligne est créée ou supprimée
    // sans passer par ici.
    expect(fetch).toHaveBeenCalledWith(3)
    expect(rows.value.map((row) => row.id)).toEqual([1, 2, 3, 4])
  })

  it('écarte une ligne déjà affichée au lieu de la montrer deux fois', async () => {
    const rows = ref([ligne(1), ligne(2)])

    // Le cas réel : entre le premier chargement et celui-ci, la ligne 2 a été
    // modifiée. Triée sur « updated_at », elle est remontée en tête et revient
    // dans la tranche suivante.
    const fetch = vi.fn().mockResolvedValue([ligne(2), ligne(3)])

    const { loadMore } = useLoadMore({ rows, fetch })
    await loadMore()

    expect(rows.value.map((row) => row.id)).toEqual([1, 2, 3])
  })

  it('dédoublonne sur l’état du moment où la réponse arrive', async () => {
    const rows = ref([ligne(1)])

    // La requête est en vol quand l'utilisateur crée la ligne 2 depuis un
    // autre coin de l'écran ; la tranche qui revient la contient déjà.
    let repondre
    const fetch = vi.fn(() => new Promise((resolve) => (repondre = resolve)))

    const { loadMore } = useLoadMore({ rows, fetch })
    const promesse = loadMore()

    rows.value = [...rows.value, ligne(2)]
    repondre([ligne(2), ligne(3)])
    await promesse

    // Comparer aux lignes d'AVANT l'appel laisserait passer le doublon : la
    // liste des connus est établie après l'attente, et c'est ce qui le rend
    // correct.
    expect(rows.value.map((row) => row.id)).toEqual([1, 2, 3])
  })

  it('n’envoie qu’une requête quand on clique deux fois', async () => {
    const rows = ref([ligne(1)])

    let repondre
    const fetch = vi.fn(() => new Promise((resolve) => (repondre = resolve)))

    const { loadingMore, loadMore } = useLoadMore({ rows, fetch })

    const premier = loadMore()
    expect(loadingMore.value).toBe(true)

    // Le bouton est désactivé pendant le chargement, mais un double clic passe
    // avant le rendu — et une deuxième requête au même décalage rapporterait
    // exactement la même tranche.
    await loadMore()
    expect(fetch).toHaveBeenCalledTimes(1)

    repondre([ligne(2)])
    await premier

    expect(loadingMore.value).toBe(false)
    expect(rows.value.map((row) => row.id)).toEqual([1, 2])
  })

  it('rend la main après un échec, sans toucher aux lignes affichées', async () => {
    const rows = ref([ligne(1)])
    const fetch = vi.fn().mockRejectedValue(new Error('Service indisponible.'))
    const onError = vi.fn()

    const { loadingMore, loadMore } = useLoadMore({ rows, fetch, onError })
    await loadMore()

    expect(onError).toHaveBeenCalledWith('Service indisponible.')
    expect(rows.value.map((row) => row.id)).toEqual([1])

    // Le point qui compte : le drapeau retombe. S'il restait levé, le bouton
    // demeurerait désactivé et l'écran serait sans issue jusqu'au rechargement
    // — une panne réseau passagère condamnerait la liste.
    expect(loadingMore.value).toBe(false)

    fetch.mockResolvedValue([ligne(2)])
    await loadMore()

    expect(rows.value.map((row) => row.id)).toEqual([1, 2])
  })

  it('reste silencieux si l’appelant n’a pas fourni de rapporteur d’erreur', async () => {
    const rows = ref([ligne(1)])
    const fetch = vi.fn().mockRejectedValue(new Error('Service indisponible.'))

    // `onError` est optionnel : son absence ne doit pas transformer une erreur
    // réseau en promesse rejetée que personne n'attrape.
    const { loadMore } = useLoadMore({ rows, fetch })

    await expect(loadMore()).resolves.toBeUndefined()
    expect(rows.value).toHaveLength(1)
  })
})
