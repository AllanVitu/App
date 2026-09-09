import { effectScope } from 'vue'
import { describe, expect, it } from 'vitest'

import { useWriteQueue } from '@/composables/useWriteQueue'

/**
 * La file d'écritures.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  ELLE CORRIGE UN BUG QUI A RÉELLEMENT ÉTÉ OBSERVÉ                   │
 * │                                                                     │
 * │  Les écrans enregistrent champ par champ : quitter un champ envoie  │
 * │  sa modification sans attendre. Sur le panneau d'un ticket, on      │
 * │  renseignait le projet puis les étiquettes ; deux requêtes          │
 * │  partaient, chaque réponse portant la ressource ENTIÈRE. Celle du   │
 * │  projet revenant en dernier réécrivait le ticket sans les           │
 * │  étiquettes — la saisie disparaissait de l'écran alors qu'elle      │
 * │  était bien en base.                                                │
 * │                                                                     │
 * │  Cette garantie n'était vérifiée que par la suite Playwright, donc  │
 * │  indirectement et en deux minutes. Ces tests-ci la vérifient        │
 * │  directement, en millisecondes, et surtout ils vérifient les cas    │
 * │  qu'un parcours navigateur ne sait pas provoquer : une réponse plus │
 * │  lente que la suivante, un échec au milieu d'une file.              │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Le composable utilise `onScopeDispose` : il lui faut une portée d'effet
 * active, sinon Vue avertit et le nettoyage n'est pas branché. D'où
 * `effectScope` autour de chaque instanciation.
 */

/** Instancie dans une portée, comme le ferait un composant. */
function file() {
  const scope = effectScope()
  let queue

  scope.run(() => {
    queue = useWriteQueue()
  })

  return { ...queue, stop: () => scope.stop() }
}

/** Une écriture qui met `ms` à répondre — pour forcer l'inversion. */
const lente = (ms, valeur, journal) => () =>
  new Promise((resolve) => {
    setTimeout(() => {
      journal?.push(valeur)
      resolve(valeur)
    }, ms)
  })

describe('useWriteQueue', () => {
  it('exécute les écritures d’une même ressource dans l’ordre d’émission', async () => {
    const { enqueue, stop } = file()
    const journal = []

    // La PREMIÈRE est la plus lente : sans file, elle finirait en dernier et
    // sa réponse écraserait celle de la seconde. C'est exactement le bug.
    const a = enqueue('ticket-1', lente(30, 'projet', journal))
    const b = enqueue('ticket-1', lente(1, 'étiquettes', journal))

    await Promise.all([a, b])

    expect(journal).toEqual(['projet', 'étiquettes'])
    stop()
  })

  it('ne fait pas attendre deux ressources différentes l’une pour l’autre', async () => {
    const { enqueue, stop } = file()
    const journal = []

    // Deux ressources distinctes : la rapide doit finir la première, même
    // empilée après la lente. Sérialiser tout le trafic ralentirait
    // l'application sans rien protéger.
    const lent = enqueue('ticket-1', lente(30, 'lent', journal))
    const rapide = enqueue('ticket-2', lente(1, 'rapide', journal))

    await Promise.all([lent, rapide])

    expect(journal).toEqual(['rapide', 'lent'])
    stop()
  })

  it('laisse passer la suite quand une écriture échoue', async () => {
    const { enqueue, stop } = file()
    const journal = []

    // Un échec ne doit pas condamner la file : la tâche fautive a déjà
    // signalé son erreur à son propre appelant, les suivantes n'y sont pour
    // rien. Sans le `catch` interne, tout le reste resterait bloqué.
    const rate = enqueue('ticket-1', () => Promise.reject(new Error('422')))
    await expect(rate).rejects.toThrow('422')

    await enqueue('ticket-1', lente(1, 'après', journal))

    expect(journal).toEqual(['après'])
    stop()
  })

  it('rend le résultat de CHAQUE écriture à son propre appelant', async () => {
    const { enqueue, stop } = file()

    // La file ordonne, elle ne redirige pas : chacun doit récupérer sa
    // réponse, pas celle du voisin.
    const [un, deux] = await Promise.all([
      enqueue('ticket-1', lente(10, 'un')),
      enqueue('ticket-1', lente(1, 'deux')),
    ])

    expect(un).toBe('un')
    expect(deux).toBe('deux')
    stop()
  })

  it('ne libère pas la clé tant qu’une écriture est empilée derrière', async () => {
    const { enqueue, stop } = file()
    const journal = []

    // ─── LE PIÈGE QUE GARDE `if (queues.get(key) === next)` ───────────────
    //
    // La file se retire de la table une fois vidée, sinon un écran ouvert
    // longtemps garderait une entrée par ligne jamais rouverte. Mais chaque
    // tâche déclenche ce retrait EN FINISSANT, et la première finit alors
    // que la deuxième court encore. Sans la garde, elle emporterait la clé
    // avec elle : la troisième écriture ne verrait plus rien à quoi
    // s'accrocher et partirait en parallèle de la deuxième.
    //
    // C'est le bug d'origine, revenu par la porte de service — d'où ce test,
    // le seul à l'attraper : les quatre précédents passent sans la garde.

    const a = enqueue('ticket-1', lente(30, 'a', journal))
    enqueue('ticket-1', lente(30, 'b', journal))

    // On attend la PREMIÈRE seulement : la deuxième court toujours, c'est
    // tout l'intérêt du moment choisi.
    await a

    // Puis on laisse le tour de boucle se terminer. Le nettoyage de « a »
    // est enchaîné en microtâches (`.catch().finally()`) : sans cette pause,
    // il n'a pas encore eu lieu quand on empile la suite, et le test
    // passerait même sans la garde — il ne prouverait alors rien.
    await new Promise((resolve) => setTimeout(resolve, 0))

    // Volontairement plus rapide que « b » : sans la garde, elle la double.
    const c = enqueue('ticket-1', lente(1, 'c', journal))
    await c

    expect(journal).toEqual(['a', 'b', 'c'])
    stop()
  })
})
