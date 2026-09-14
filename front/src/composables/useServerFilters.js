import { onScopeDispose, ref, watch } from 'vue'

/**
 * Quand la liste est tronquée, les filtres partent au serveur.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  LE PLAFOND CACHAIT CE QU'ON CHERCHAIT                                  │
 * │                                                                         │
 * │  Ces écrans chargent leurs lignes d'un bloc, avec un plafond, pour que  │
 * │  les filtres soient locaux et instantanés. Au-delà du plafond, une      │
 * │  recherche ne voyait que ce qui était chargé : on cherchait un ticket   │
 * │  qui existe, sans le trouver — et l'avertissement conseillait même de   │
 * │  « chercher pour atteindre le reste », ce qu'aucune recherche ne        │
 * │  faisait.                                                               │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * La règle est double, et la première moitié compte autant que la seconde :
 *
 *   — tant que la liste de départ est COMPLÈTE, rien ne part : le filtrage
 *     local reste instantané, et c'est l'atout de ces écrans ;
 *   — dès qu'elle est TRONQUÉE, un filtre actif interroge le serveur, qui voit
 *     tout. Effacer les filtres rend la liste de départ.
 *
 * « enabled » doit donc dire si la liste DE DÉPART déborde — pas si la liste
 * affichée déborde. Une réponse filtrée tient presque toujours sous le
 * plafond ; s'y fier ferait sortir du mode serveur au premier résultat, et
 * boucler.
 *
 * Une frappe ne vaut pas une requête (délai), et une réponse lente n'écrase
 * jamais une plus récente : elle est jetée à son arrivée, qu'elle ait été
 * annulée ou non — un serveur n'est pas tenu de s'arrêter quand on le lui
 * demande.
 *
 * @param {object}   options
 * @param {() => boolean} options.enabled   la liste de départ dépasse le plafond
 * @param {() => object}  options.params    filtres actifs, au format de l'API ; {} si aucun
 * @param {(params: object, signal: AbortSignal) => Promise<any>} options.fetch
 * @param {(resultat: any) => void} options.apply      pose les lignes et le total reçus
 * @param {() => Promise<void>}     options.restore    relit la liste de départ
 * @param {number}   [options.delay]    attente après le dernier changement, en ms
 * @param {(message: string) => void} [options.onError]
 */
export function useServerFilters({ enabled, params, fetch, apply, restore, delay = 250, onError }) {
  /** Les lignes affichées viennent d'une requête filtrée. */
  const active = ref(false)

  /** Une requête filtrée est en vol. */
  const searching = ref(false)

  let minuterie = null
  let enVol = null

  const aucunFiltre = (filtres) => Object.keys(filtres).length === 0

  function oublierRequete() {
    enVol?.abort()
    enVol = null
    searching.value = false
  }

  async function interroger(filtres) {
    enVol?.abort()

    const controleur = new AbortController()
    enVol = controleur
    searching.value = true

    try {
      const resultat = await fetch(filtres, controleur.signal)

      if (enVol !== controleur) return

      apply(resultat)
      active.value = true
    } catch (erreur) {
      // Annulée, ou dépassée par une plus récente : ce n'est pas un échec.
      if (enVol !== controleur || erreur?.canceled || controleur.signal.aborted) return

      onError?.(erreur?.message ?? 'La recherche n’a pas abouti.')
    } finally {
      if (enVol === controleur) {
        enVol = null
        searching.value = false
      }
    }
  }

  async function synchroniser() {
    const filtres = params()

    // Liste complète : le filtrage local suffit, et il est instantané.
    if (!enabled()) {
      active.value = false

      return
    }

    if (aucunFiltre(filtres)) {
      if (!active.value) return

      oublierRequete()
      active.value = false
      await restore()

      return
    }

    await interroger(filtres)
  }

  // Une clé en TEXTE plutôt qu'un objet : « panier » et « panier » tapés deux
  // fois, ou un espace ajouté puis retiré, ne doivent pas relancer de requête.
  watch(
    () => `${enabled()}|${JSON.stringify(params())}`,
    () => {
      clearTimeout(minuterie)
      minuterie = setTimeout(synchroniser, delay)
    },
  )

  /**
   * Relecture — retour sur l'onglet, flux temps réel, restauration.
   *
   * Elle suit le mode COURANT. Sans elle, ces trois chemins remplaceraient une
   * recherche en cours par les premières lignes de la liste brute, sans que
   * rien à l'écran ne dise pourquoi le résultat a changé.
   */
  function reload() {
    clearTimeout(minuterie)

    const filtres = params()

    if (active.value && enabled() && !aucunFiltre(filtres)) return interroger(filtres)

    return restore()
  }

  onScopeDispose(() => {
    clearTimeout(minuterie)
    oublierRequete()
  })

  return { active, searching, reload }
}
