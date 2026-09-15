import { spawn } from 'node:child_process'
import { join } from 'node:path'

/**
 * Lance un programme et attend sa fin, sortie recueillie ligne à ligne.
 *
 * windowsHide : sans lui, chaque programme en ligne de commande ouvrirait une
 * fenêtre de console, le temps de son exécution, par-dessus l'application.
 *
 * La fin est la sortie du PROCESSUS, pas la fermeture de ses flux : sous
 * Windows, « pg_ctl start » lance le serveur en lui transmettant ses propres
 * descripteurs, qui restent donc ouverts aussi longtemps que PostgreSQL tourne.
 * Attendre leur fermeture, ce serait attendre l'arrêt de la base.
 */
export function executer(programme, args, { env, cwd, delai = 120_000, surLigne } = {}) {
  return new Promise((resoudre, rejeter) => {
    const enfant = spawn(programme, args, { env, cwd, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] })

    let sortie = ''
    let reste = ''
    let fini = false

    const recevoir = (morceau) => {
      const texte = morceau.toString('utf8')

      // La sortie gardée en mémoire est bornée : seule sa fin sert à expliquer un échec.
      sortie = (sortie + texte).slice(-16_000)

      const lignes = (reste + texte).split(/\r?\n/)
      reste = lignes.pop() ?? ''
      lignes.filter((l) => l.trim() !== '').forEach((l) => surLigne?.(l))
    }

    const terminer = (code) => {
      if (fini) {
        return
      }

      fini = true
      clearTimeout(minuterie)

      if (reste.trim() !== '') {
        surLigne?.(reste)
      }

      enfant.stdout.destroy()
      enfant.stderr.destroy()
      resoudre({ code, sortie })
    }

    const minuterie = setTimeout(() => {
      fini = true
      enfant.kill()
      rejeter(new Error(`${programme} n'a pas terminé dans le délai imparti.`))
    }, delai)

    enfant.stdout.on('data', recevoir)
    enfant.stderr.on('data', recevoir)
    enfant.on('error', (erreur) => {
      if (!fini) {
        fini = true
        clearTimeout(minuterie)
        rejeter(erreur)
      }
    })

    // Les derniers octets écrits juste avant la sortie peuvent arriver après
    // l'événement : un court délai les laisse passer.
    enfant.on('exit', (code) => setTimeout(() => terminer(code), 250))
    enfant.on('close', (code) => terminer(code))
  })
}

/**
 * Un outil de Windows, par son chemin complet. Désigné par son seul nom, il
 * serait cherché dans le PATH — où « whoami » peut être celui de Git, et
 * « icacls » n'importe quel programme déposé plus tôt dans la liste.
 */
export function outilWindows(...chemin) {
  return join(process.env.SystemRoot ?? 'C:\\Windows', 'System32', ...chemin)
}

/**
 * L'environnement minimal d'un programme lancé par l'application.
 *
 * Rien n'est hérité par défaut : les variables d'Electron, du terminal ou de
 * la session n'ont rien à faire dans PHP ou PostgreSQL. Seul ce dont Windows a
 * besoin pour démarrer un programme est repris.
 */
export function environnementDeBase({ chemins = [], temporaire }) {
  const systeme = process.env.SystemRoot ?? 'C:\\Windows'

  return {
    SystemRoot: systeme,
    windir: systeme,
    ComSpec: process.env.ComSpec ?? `${systeme}\\System32\\cmd.exe`,
    PATH: [...chemins, `${systeme}\\System32`, systeme].join(';'),
    TEMP: temporaire,
    TMP: temporaire,
    ...(process.env.USERPROFILE ? { USERPROFILE: process.env.USERPROFILE } : {}),
    ...(process.env.LOCALAPPDATA ? { LOCALAPPDATA: process.env.LOCALAPPDATA } : {}),
  }
}
