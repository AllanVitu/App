import { onBeforeUnmount, onMounted } from 'vue'

/**
 * Relit les données quand l'écran redevient visible après une absence.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  UN ÉCRAN OUVERT NE VIEILLIT PAS TOUT SEUL                          │
 * │                                                                     │
 * │  Rien, jusqu'ici, ne rafraîchissait quoi que ce soit. Deux onglets  │
 * │  ouverts sur les tickets divergeaient en silence ; un onglet laissé │
 * │  ouvert pendant une réunion affichait l'état d'il y a une heure     │
 * │  sans le dire. Sur un module qui s'appelle « supervision », c'est   │
 * │  la promesse même de l'écran qui tombe.                            │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * DEUX DÉCLENCHEURS, ET PAS DE SONDAGE.
 *
 *   Le RETOUR SUR L'ONGLET, quand l'absence a duré. Interroger le serveur
 *   toutes les trente secondes coûterait à tout le monde pour servir un
 *   utilisateur qui, la plupart du temps, ne regarde pas.
 *
 *   Le RETOUR DU RÉSEAU. Une coupure laisse l'écran figé sur ce qu'il avait ;
 *   la connexion revenue, il n'y a aucune raison d'attendre en plus un
 *   changement d'onglet.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  LE SEUIL EXISTE POUR LE VA-ET-VIENT                                │
 * │                                                                     │
 * │  Copier une empreinte de commit depuis un autre onglet, revenir :   │
 * │  deux secondes. Recharger à ce moment-là ferait sauter la liste     │
 * │  sous les yeux pour ne rien apprendre. En dessous du seuil, on      │
 * │  considère que l'utilisateur n'est jamais vraiment parti.          │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Le rechargement est SILENCIEUX : c'est à l'appelant de passer une fonction
 * qui n'allume pas d'indicateur de chargement. Remplacer l'écran par un
 * rond qui tourne au retour sur l'onglet donnerait l'impression d'avoir tout
 * perdu.
 *
 * @param {() => unknown} recharger appel de rechargement, sans indicateur
 * @param {{ apres?: number, actif?: () => boolean }} [options]
 *   `apres` : absence minimale, en millisecondes.
 *   `actif` : consulté au dernier moment ; renvoyer faux annule ce passage.
 */
export function useRevalidate(recharger, { apres = 30_000, actif } = {}) {
  /** Instant du passage en arrière-plan, ou null si l'onglet est au premier plan. */
  let masqueDepuis = null

  function autorise() {
    // Hors ligne, la requête échouerait et l'écran afficherait une erreur
    // pour un rafraîchissement que personne n'a demandé.
    if (!navigator.onLine) return false

    return actif ? actif() !== false : true
  }

  function surVisibilite() {
    if (document.visibilityState === 'hidden') {
      masqueDepuis = Date.now()

      return
    }

    // Sans marque de départ, l'onglet n'a jamais été caché pendant notre vie :
    // c'est un montage, et la vue vient déjà de charger.
    if (masqueDepuis === null) return

    const absence = Date.now() - masqueDepuis
    masqueDepuis = null

    if (absence < apres) return
    if (!autorise()) return

    recharger()
  }

  function surRetourReseau() {
    // L'onglet caché sera traité à son retour, avec son propre seuil.
    if (document.visibilityState !== 'visible') return
    if (!autorise()) return

    recharger()
  }

  onMounted(() => {
    document.addEventListener('visibilitychange', surVisibilite)
    window.addEventListener('online', surRetourReseau)
  })

  onBeforeUnmount(() => {
    document.removeEventListener('visibilitychange', surVisibilite)
    window.removeEventListener('online', surRetourReseau)
  })
}
