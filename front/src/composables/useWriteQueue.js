import { onScopeDispose } from 'vue'

/**
 * Sérialise les écritures visant une MÊME ressource.
 *
 * Les écrans enregistrent champ par champ : quitter un champ envoie sa
 * modification, sans attendre. Deux enregistrements peuvent donc être en vol
 * en même temps sur la même ressource — et leurs réponses revenir dans le
 * désordre.
 *
 * Le problème n'est pas théorique. Observé sur le panneau de détail d'un
 * ticket : on renseigne le projet, on passe aux étiquettes, on valide. Deux
 * requêtes partent. Chaque réponse contient la ressource ENTIÈRE telle que le
 * serveur la voyait au moment du traitement ; si celle du projet revient en
 * dernier, elle réécrit le ticket sans les étiquettes, et la saisie disparaît
 * de l'écran alors qu'elle est bien enregistrée en base.
 *
 * Mettre les écritures en file par identifiant suffit : la seconde ne part
 * qu'une fois la première revenue, les réponses arrivent donc dans l'ordre
 * d'émission, et la dernière — celle que l'utilisateur a faite en dernier —
 * est celle qui reste.
 *
 * Deux ressources DIFFÉRENTES ne s'attendent jamais : la file est par clé.
 */
export function useWriteQueue() {
  /** @type {Map<string, Promise<unknown>>} */
  const queues = new Map()

  /**
   * @param {string} key identifiant de la ressource
   * @param {() => Promise<T>} task écriture à exécuter
   * @returns {Promise<T>}
   * @template T
   */
  function enqueue(key, task) {
    // `catch` sur la précédente : un échec ne doit pas bloquer la file. La
    // tâche qui a échoué a déjà signalé son erreur à son propre appelant.
    const previous = queues.get(key) ?? Promise.resolve()
    const next = previous.then(task, task)

    queues.set(key, next)

    // On ne retire l'entrée que si personne n'a empilé derrière : sinon on
    // effacerait la file d'une écriture encore en attente.
    next
      .catch(() => {})
      .finally(() => {
        if (queues.get(key) === next) queues.delete(key)
      })

    return next
  }

  // Le composant démonté, les réponses en vol n'intéressent plus personne.
  onScopeDispose(() => queues.clear())

  return { enqueue }
}
