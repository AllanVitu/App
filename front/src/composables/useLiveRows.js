import { computed } from 'vue'

import { useLiveStream } from '@/composables/useLiveStream'

/**
 * Applique à une liste ce que les coéquipiers viennent de faire.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  CETTE LOGIQUE VIVAIT DANS L'ÉCRAN DES TICKETS, ET NULLE PART AILLEURS  │
 * │                                                                         │
 * │  Le tableau des tickets bougeait tout seul ; les quatre autres écrans   │
 * │  restaient figés, sans que rien n'explique la différence. Recopier      │
 * │  quarante lignes quatre fois aurait donné cinq variantes qui            │
 * │  divergeraient à la première correction.                                │
 * │                                                                         │
 * │  Ce composable ne sait rien d'un ticket : il connaît un MODULE, une     │
 * │  liste de lignes, et la règle d'application des changements.            │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * @param {object}   options
 * @param {string}   options.module    Slug attendu dans les événements.
 * @param {string}   options.screen    Écran courant, pour la présence.
 * @param {import('vue').Ref}      options.rows      Les lignes affichées.
 * @param {Function} [options.subject]   Sujet ouvert, s'il y en a un.
 * @param {Function} [options.protege]   Identifiant à ne PAS écraser.
 * @param {Function} options.recharger   Relecture complète, en dernier recours.
 * @param {Function} [options.decorer]   Complète une ligne modifiée à distance.
 */
export function useLiveRows({ module, screen, rows, subject, protege, recharger, decorer } = {}) {
  /**
   * Applique une salve d'événements.
   *
   * ┌───────────────────────────────────────────────────────────────────────┐
   * │  CE QUI EST DÉLIBÉRÉMENT NON APPLIQUÉ                                 │
   * │                                                                       │
   * │  La ligne OUVERTE en édition est laissée telle quelle. Voir un champ  │
   * │  se réécrire sous ses doigts est pire que de l'ignorer : on perd ce   │
   * │  qu'on tapait, et on ne comprend pas ce qui s'est passé.              │
   * │                                                                       │
   * │  L'arbitrage a lieu À L'ENREGISTREMENT, où le serveur dit quel champ  │
   * │  a bougé et par qui — le seul moment où l'on peut proposer un vrai    │
   * │  choix.                                                               │
   * └───────────────────────────────────────────────────────────────────────┘
   */
  function appliquer(events) {
    let besoinDeRecharger = false
    const intouchable = typeof protege === 'function' ? protege() : protege

    for (const evenement of events) {
      if (evenement.module !== module) continue

      const index = rows.value.findIndex((ligne) => ligne.id === evenement.subject_id)

      // Créée ou restaurée ailleurs : la ligne n'est pas là, et le journal ne
      // porte pas de quoi la fabriquer entière. Une relecture, une seule, à la
      // fin de la salve.
      if (index === -1) {
        besoinDeRecharger = besoinDeRecharger || evenement.action !== 'deleted'
        continue
      }

      if (evenement.action === 'deleted') {
        rows.value.splice(index, 1)
        continue
      }

      if (intouchable && intouchable === evenement.subject_id) continue

      const changes = {}

      for (const [champ, [, apres]] of Object.entries(evenement.changes ?? {})) {
        changes[champ] = apres
      }

      if (!Object.keys(changes).length) continue

      // Le journal ne transporte que des valeurs BRUTES : un identifiant
      // d'assigné, jamais son nom. L'écran sait compléter — la liste des
      // membres est déjà chargée — plutôt que de payer un aller-retour.
      const complet = decorer ? decorer(changes, rows.value[index]) : changes

      rows.value[index] = { ...rows.value[index], ...complet }
    }

    if (besoinDeRecharger) recharger()
  }

  const { presence, connected } = useLiveStream({
    screen,
    subject,
    onEvents: appliquer,
    // Rattraper deux cents changements un par un ferait clignoter l'écran plus
    // longtemps qu'un rechargement franc.
    onDistanced: recharger,
  })

  /**
   * Qui regarde quoi, indexé par sujet.
   *
   * Un objet plutôt qu'une recherche dans la liste à chaque ligne : un écran
   * en affiche des dizaines, et parcourir la présence pour chacune d'elles à
   * chaque rendu se paierait sur le défilement.
   */
  const watchers = computed(() => {
    const parSujet = {}

    for (const present of presence.value) {
      if (!present.subject_id) continue

      ;(parSujet[present.subject_id] ??= []).push(present.full_name)
    }

    return parSujet
  })

  return { presence, watchers, connected }
}
