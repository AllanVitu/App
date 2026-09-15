import { onScopeDispose, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

/**
 * Met l'état d'un écran dans son adresse.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  CE QU'UN ÉCRAN SANS ADRESSE FAIT PERDRE                            │
 * │                                                                     │
 * │  Filtrer les erreurs sur « non résolues », copier le lien,          │
 * │  l'envoyer : le destinataire voit autre chose. Recharger la page :  │
 * │  tout repart de zéro. Aller voir un autre module et revenir : les   │
 * │  filtres sont perdus, alors que le bouton « précédent » promet      │
 * │  exactement le contraire.                                          │
 * │                                                                     │
 * │  Ce n'est pas un confort. Une adresse qui ne décrit pas ce qu'on    │
 * │  regarde rend l'écran incitable : on ne peut ni le partager, ni le  │
 * │  mettre en favori, ni y revenir.                                   │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  « REPLACE » ET NON « PUSH »                                        │
 * │                                                                     │
 * │  Taper « quota » dans une recherche produirait cinq entrées         │
 * │  d'historique, et « précédent » effacerait les lettres une à une au │
 * │  lieu de revenir à la page d'avant. L'adresse suit l'écran, elle    │
 * │  ne raconte pas la frappe.                                         │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Les valeurs par défaut n'apparaissent JAMAIS dans l'adresse : un écran
 * qu'on n'a pas touché garde une URL nue. Sans cette règle, ouvrir un module
 * afficherait aussitôt « ?q=&statut= », et le lien copié porterait un état
 * que personne n'a choisi.
 *
 * @example
 *   // Le défaut de chaque champ est la valeur qu'il a au moment de l'appel.
 *   useQuerySync({ q: search, statut: statusFilter })
 *
 *   // Champ non textuel : « lire » convertit ce qui vient de l'adresse.
 *   useQuerySync({ page: { ref: page, lire: (texte) => Number(texte) || 1 } })
 *
 * @param {Record<string, import('vue').Ref | {ref: import('vue').Ref, defaut?: unknown, lire?: (texte: string) => unknown}>} champs
 * @param {{ delai?: number, surRetour?: () => void }} [options]
 *   `delai` : attente avant écriture, en millisecondes.
 *   `surRetour` : appelé quand l'adresse a changé SANS nous — bouton
 *   « précédent », lien collé — et que les champs viennent d'être remis à ce
 *   qu'elle dit. Les écrans qui filtrent localement n'en ont pas besoin :
 *   remettre les champs suffit à refiltrer. Ceux qui filtrent CÔTÉ SERVEUR
 *   doivent y relancer leur requête, sinon l'adresse dit une chose et la
 *   liste en montre une autre.
 */
export function useQuerySync(champs, { delai = 300, surRetour } = {}) {
  const route = useRoute()
  const router = useRouter()

  const entrees = Object.entries(champs).map(([cle, valeur]) => {
    const champ = valeur && typeof valeur === 'object' && 'ref' in valeur ? valeur : { ref: valeur }

    return {
      cle,
      ref: champ.ref,
      // Le défaut est la valeur DÉCLARÉE par la vue : `ref('')`, `ref(null)`.
      // La redemander à l'appelant inviterait à la contredire.
      defaut: 'defaut' in champ ? champ.defaut : champ.ref.value,
      lire: champ.lire ?? ((texte) => texte),
    }
  })

  /** L'adresse que produirait l'état actuel des champs. */
  function versQuery() {
    const query = { ...route.query }

    for (const { cle, ref, defaut } of entrees) {
      const valeur = ref.value

      if (valeur === defaut || valeur === null || valeur === undefined || valeur === '') {
        delete query[cle]
      } else {
        query[cle] = String(valeur)
      }
    }

    return query
  }

  /** Reporte l'adresse sur les champs. Absent de l'adresse = valeur par défaut. */
  function versChamps() {
    for (const { cle, ref, defaut, lire } of entrees) {
      const brut = route.query[cle]

      if (brut === undefined || brut === null) {
        ref.value = defaut

        continue
      }

      // Un paramètre répété (« ?q=a&q=b ») arrive en tableau : on retient le
      // premier plutôt que d'échouer sur une adresse forgée à la main.
      ref.value = lire(Array.isArray(brut) ? (brut[0] ?? '') : brut)
    }
  }

  /**
   * Deux adresses portent-elles la même chose ?
   *
   * C'est ce qui remplace un drapeau « je suis en train d'écrire ». Un
   * drapeau demanderait de savoir QUAND le baisser — après la promesse de
   * navigation, après le déclenchement de l'observateur — et l'ordre des
   * deux n'est pas garanti. La comparaison, elle, est vraie à tout instant :
   * si l'adresse dit déjà ce que disent les champs, il n'y a rien à faire,
   * peu importe qui l'a écrite.
   */
  function memeQuery(a, b) {
    const cles = new Set([...Object.keys(a), ...Object.keys(b)])

    for (const cle of cles) {
      const va = Array.isArray(a[cle]) ? a[cle][0] : a[cle]
      const vb = Array.isArray(b[cle]) ? b[cle][0] : b[cle]

      if (va !== vb) return false
    }

    return true
  }

  let minuteur = null

  function ecrire() {
    const query = versQuery()

    if (memeQuery(query, route.query)) return

    // `catch` obligatoire : vue-router rejette une navigation redondante ou
    // annulée par une autre, et ce n'est pas une erreur applicative.
    router.replace({ query }).catch(() => {})
  }

  // L'ADRESSE EST LUE AVANT QUE QUOI QUE CE SOIT NE L'OBSERVE.
  // La vue construit ses champs, on les remplit, ET SEULEMENT APRÈS on
  // branche les observateurs — sinon le premier remplissage se réécrirait
  // lui-même dans l'adresse au montage.
  versChamps()

  watch(
    entrees.map(({ ref }) => ref),
    () => {
      clearTimeout(minuteur)
      minuteur = setTimeout(ecrire, delai)
    },
  )

  // Retour arrière, lien collé, redirection après connexion : l'adresse a
  // changé sans nous. Si elle dit déjà ce que disent les champs, c'est notre
  // propre écriture qui revient — on ne fait rien.
  watch(
    () => route.query,
    () => {
      if (memeQuery(versQuery(), route.query)) return

      clearTimeout(minuteur)
      versChamps()
      surRetour?.()
    },
  )

  // SANS CECI, UNE ÉCRITURE EN ATTENTE ATTERRIT SUR LA PAGE SUIVANTE.
  // Taper dans la recherche puis changer de module aussitôt : l'observateur
  // est arrêté par Vue, mais le minuteur, lui, court encore et écrirait
  // « ?q=… » dans l'adresse d'un écran qui n'a rien demandé.
  onScopeDispose(() => clearTimeout(minuteur))
}
