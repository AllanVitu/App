import { appendFileSync, existsSync, mkdirSync, renameSync, rmSync, statSync } from 'node:fs'
import { dirname } from 'node:path'

/**
 * Le journal de l'application : un fichier texte, une ligne par événement.
 *
 * Il tourne à 5 Mo — le fichier courant devient « .1 », l'ancien « .1 » est
 * effacé. Deux fichiers au plus : un journal qui grossit sans fin sur le poste
 * de quelqu'un est une donnée qu'on lui impose.
 */
export function creerJournal(fichier, { tailleMax = 5 * 1024 * 1024, echo = false } = {}) {
  mkdirSync(dirname(fichier), { recursive: true })

  const ecrire = (niveau, source, message) => {
    try {
      if (existsSync(fichier) && statSync(fichier).size > tailleMax) {
        rmSync(`${fichier}.1`, { force: true })
        renameSync(fichier, `${fichier}.1`)
      }

      for (const ligne of String(message).split(/\r?\n/)) {
        if (ligne.trim() !== '') {
          const entree = `${new Date().toISOString()} ${niveau.padEnd(6)} [${source}] ${ligne}\n`
          appendFileSync(fichier, entree)

          if (echo) {
            process.stdout.write(entree)
          }
        }
      }
    } catch {
      // Le journal ne doit jamais faire tomber ce qu'il observe.
    }
  }

  return {
    fichier,
    info: (source, message) => ecrire('INFO', source, message),
    alerte: (source, message) => ecrire('ALERTE', source, message),
    erreur: (source, message) => ecrire('ERREUR', source, message),
  }
}
