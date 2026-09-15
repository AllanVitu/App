import { onBeforeUnmount, onMounted } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'

/**
 * Prévient avant d'abandonner une saisie en cours.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  RIEN NE PROTÉGEAIT CE QU'ON ÉTAIT EN TRAIN D'ÉCRIRE                │
 * │                                                                     │
 * │  Aucun « onBeforeRouteLeave » nulle part. Une description de ticket │
 * │  à moitié rédigée disparaissait sur un clic à côté, sur un « g » +  │
 * │  lettre tapé par réflexe, ou sur un onglet fermé par habitude.      │
 * │                                                                     │
 * │  Ce n'est pas une perte de données au sens technique — rien n'avait │
 * │  été enregistré. C'est une perte de TRAVAIL, ce qui revient au même │
 * │  pour celui qui vient de passer dix minutes à écrire.               │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * DEUX SORTIES, DEUX MÉCANISMES, ET ILS NE SE RESSEMBLENT PAS.
 *
 *   La navigation INTERNE — un lien du menu, un raccourci — passe par
 *   vue-router : on peut poser notre propre question, dans notre langue,
 *   avec nos mots.
 *
 *   La fermeture de l'ONGLET passe par « beforeunload », que le navigateur
 *   contrôle entièrement. Le message est le sien, on ne peut ni le choisir
 *   ni le traduire : la spécification l'impose pour empêcher les pages de
 *   retenir les gens par le chantage. On ne peut que DÉCLARER qu'il y a
 *   quelque chose à perdre.
 *
 * L'écouteur n'est posé QUE lorsqu'il y a réellement quelque chose à perdre.
 * Un « beforeunload » enregistré en permanence désactive le cache de retour
 * arrière du navigateur (bfcache) sur toute la page — un coût réel, pour une
 * question qu'on ne poserait pas.
 *
 * @param {() => boolean} estModifie  vrai quand une saisie non enregistrée existe
 * @param {{ message?: string }} [options]
 */
export function useUnsavedGuard(estModifie, { message } = {}) {
  const question = message ?? 'Des modifications ne sont pas enregistrées. Quitter cette page ?'

  function surFermeture(event) {
    if (!estModifie()) return

    // Les deux formes sont nécessaires : « preventDefault » est la manière
    // normalisée, « returnValue » celle que certains navigateurs attendent
    // encore. Aucune des deux ne choisit le texte affiché.
    event.preventDefault()
    event.returnValue = ''
  }

  onMounted(() => window.addEventListener('beforeunload', surFermeture))
  onBeforeUnmount(() => window.removeEventListener('beforeunload', surFermeture))

  onBeforeRouteLeave(() => {
    if (!estModifie()) return true

    // « confirm » plutôt qu'une fenêtre maison : la navigation est
    // SYNCHRONE, la garde doit répondre vrai ou faux tout de suite. Une
    // fenêtre applicative demanderait de suspendre la navigation, d'attendre
    // un clic, puis de la rejouer — beaucoup de mécanique pour une question
    // à deux réponses, et un risque de laisser l'utilisateur coincé si la
    // fenêtre se perd en route.
    return window.confirm(question)
  })
}
