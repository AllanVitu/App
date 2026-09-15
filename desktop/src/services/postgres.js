// PostgreSQL sur le poste : un serveur privé, lancé et arrêté avec l'application.
//
// C'est le même moteur, dans la même version majeure, que l'image Docker : le
// schéma, les extensions (citext, unaccent) et les requêtes de l'API n'ont pas
// à connaître la différence.
import { randomBytes } from 'node:crypto'
import { appendFileSync, closeSync, existsSync, openSync, readSync, renameSync, rmSync, statSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { executer } from './processus.js'

export const UTILISATEUR = 'relais'

/**
 * Ajouté au postgresql.conf à l'initialisation.
 *
 * L'adresse d'écoute est celle de la machine seule : aucun autre ordinateur
 * du réseau ne peut joindre la base. L'authentification reste exigée malgré
 * tout (scram-sha-256) — un autre compte Windows du même poste, lui, peut
 * atteindre 127.0.0.1.
 */
const CONFIGURATION = `
# --- Relais ------------------------------------------------------------------
# Une base de poste : elle n'écoute que la machine elle-même, et se dimensionne
# pour une personne, pas pour un serveur.
listen_addresses = '127.0.0.1'
max_connections = 30
shared_buffers = 64MB
password_encryption = scram-sha-256
ssl = off
timezone = 'UTC'
log_timezone = 'UTC'
log_line_prefix = '%m [%p] '
`

const JOURNAL_MAX = 5 * 1024 * 1024

export function creerPostgres({ binaires, donnees, fichierJournal, journal, env }) {
  const pgCtl = join(binaires, 'pg_ctl.exe')
  const options = (extra = {}) => ({ env, surLigne: (ligne) => journal.info('postgres', ligne), ...extra })

  async function enMarche() {
    const { code } = await executer(pgCtl, ['status', '--pgdata', donnees], { env, delai: 15_000 })

    return code === 0
  }

  function finDuJournal(octets = 2048) {
    try {
      const taille = statSync(fichierJournal).size
      const tampon = Buffer.alloc(Math.min(octets, taille))
      const fd = openSync(fichierJournal, 'r')
      readSync(fd, tampon, 0, tampon.length, taille - tampon.length)
      closeSync(fd)

      return tampon.toString('utf8')
    } catch {
      return ''
    }
  }

  async function arreter() {
    const { code } = await executer(pgCtl, ['stop', '--pgdata', donnees, '--mode', 'fast', '--wait', '--timeout', '60'], options({ delai: 90_000 }))

    return code === 0
  }

  return {
    enMarche,
    arreter,

    estInitialisee: () => existsSync(join(donnees, 'PG_VERSION')),

    async initialiser(motDePasse) {
      // initdb ne lit le mot de passe que dans un fichier. Il ne vit que le
      // temps de la commande, dans le dossier de données du compte.
      const fichierMotDePasse = join(dirname(donnees), `.initdb-${randomBytes(8).toString('hex')}`)
      writeFileSync(fichierMotDePasse, motDePasse, { mode: 0o600 })

      try {
        const { code, sortie } = await executer(
          join(binaires, 'initdb.exe'),
          [
            '--pgdata', donnees,
            '--username', UTILISATEUR,
            `--pwfile=${fichierMotDePasse}`,
            '--auth', 'scram-sha-256',
            '--encoding', 'UTF8',
            // Le tri et la casse suivent les règles du français (ICU) : « é »
            // se range avec « e », pas après « z ».
            '--locale-provider', 'icu',
            '--icu-locale', 'fr-FR',
            '--locale', 'C',
            '--no-instructions',
          ],
          options({ delai: 180_000 }),
        )

        if (code !== 0) {
          // Un dossier à moitié initialisé serait pris, au lancement suivant,
          // pour une base existante.
          rmSync(donnees, { recursive: true, force: true })

          throw new Error(`Initialisation de PostgreSQL impossible.\n${sortie}`)
        }
      } finally {
        rmSync(fichierMotDePasse, { force: true })
      }

      appendFileSync(join(donnees, 'postgresql.conf'), CONFIGURATION)
    },

    async demarrer(port) {
      // Une fin brutale de l'application (coupure, fin de tâche) laisse le
      // serveur tourner : il est arrêté proprement, puis relancé sur le port
      // de cette session.
      if (await enMarche()) {
        journal.alerte('postgres', 'Serveur resté actif depuis la session précédente : arrêt.')
        await arreter()
      }

      if (existsSync(fichierJournal) && statSync(fichierJournal).size > JOURNAL_MAX) {
        rmSync(`${fichierJournal}.1`, { force: true })
        renameSync(fichierJournal, `${fichierJournal}.1`)
      }

      const args = ['start', '--pgdata', donnees, '--log', fichierJournal, '--wait', '--timeout', '90', '-o', `-p ${port}`]
      let resultat = await executer(pgCtl, args, options({ delai: 120_000 }))

      if (resultat.code !== 0 && existsSync(join(donnees, 'postmaster.pid')) && !(await enMarche())) {
        // Le verrou d'un serveur arrêté brutalement, dont le numéro de
        // processus a été repris par un autre programme : PostgreSQL le
        // prend pour une instance vivante et refuse de démarrer.
        journal.alerte('postgres', 'Verrou orphelin retiré.')
        rmSync(join(donnees, 'postmaster.pid'), { force: true })
        resultat = await executer(pgCtl, args, options({ delai: 120_000 }))
      }

      if (resultat.code !== 0) {
        throw new Error(`Démarrage de PostgreSQL impossible.\n${resultat.sortie}\n${finDuJournal()}`)
      }
    },
  }
}
