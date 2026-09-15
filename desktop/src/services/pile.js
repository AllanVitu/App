// La pile locale : base de données, API, serveur web et worker.
//
// Démarrés dans l'ordre où chacun a besoin du précédent, arrêtés dans l'ordre
// inverse. L'application Electron s'en sert, et le vérificateur autonome
// (scripts/verifier.mjs) aussi : ce qui est vérifié est ce qui est livré.
import { randomBytes } from 'node:crypto'
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { cpus } from 'node:os'
import { join, normalize } from 'node:path'
import { ouvrirCoffre } from './coffre.js'
import { creerJournal } from './journal.js'
import { creerReservePhp, creerWorker, executerScriptPhp, iniPhp } from './php.js'
import { portLibre } from './ports.js'
import { creerPostgres, UTILISATEUR } from './postgres.js'
import { environnementDeBase, executer, outilWindows } from './processus.js'
import { creerServeur } from './serveur.js'

export const NOM_BASE = 'relais'

/** Où se trouvent les programmes et le code : à côté de l'exécutable une fois installé, dans le dépôt sinon. */
export function cheminsProgrammes({ emballee, ressources, desktop }) {
  return emballee
    ? {
        php: join(ressources, 'runtime', 'php'),
        postgres: join(ressources, 'runtime', 'postgres', 'bin'),
        api: join(ressources, 'app', 'back'),
        front: join(ressources, 'app', 'front'),
        bureau: join(ressources, 'app', 'bureau'),
      }
    : {
        php: join(desktop, 'runtime', 'php'),
        postgres: join(desktop, 'runtime', 'postgres', 'bin'),
        api: join(desktop, '..', 'back'),
        front: join(desktop, '..', 'front', 'dist'),
        bureau: join(desktop, 'php'),
      }
}

/** Le dossier de données du compte, et ce qu'il contient. */
export function cheminsDonnees(racine) {
  return {
    racine,
    coffre: join(racine, 'coffre.bin'),
    reglages: join(racine, 'reglages.json'),
    processus: join(racine, 'processus.json'),
    base: join(racine, 'base'),
    fichiers: join(racine, 'fichiers'),
    boiteEnvoi: join(racine, 'boite-d-envoi'),
    journaux: join(racine, 'journaux'),
    temporaire: join(racine, 'temporaire'),
    session: join(racine, 'session'),
  }
}

export function lireReglages(fichier) {
  try {
    const reglages = JSON.parse(readFileSync(fichier, 'utf8'))

    return reglages !== null && typeof reglages === 'object' ? reglages : {}
  } catch {
    return {}
  }
}

export function ecrireReglages(fichier, reglages) {
  writeFileSync(fichier, JSON.stringify(reglages, null, 2) + '\n')
}

/**
 * Réserve le dossier de données au seul compte Windows qui l'utilise (et au
 * système) : l'héritage des droits du profil est coupé, les administrateurs
 * et les autres comptes du poste n'y lisent plus rien.
 *
 * Fait une fois, puis retenu dans les réglages : la propagation sur une base
 * déjà remplie prend du temps, et un droit posé ne se perd pas tout seul.
 */
async function restreindreAcces(dossier, reglages, journal) {
  if (process.platform !== 'win32' || reglages.accesRestreint === true) {
    return reglages
  }

  try {
    const identite = await executer(outilWindows('whoami.exe'), ['/user', '/fo', 'csv', '/nh'], { delai: 15_000 })
    const sid = identite.sortie.match(/S-1-[0-9-]+/)?.[0]

    if (!sid) {
      throw new Error('identifiant du compte introuvable')
    }

    const droits = await executer(outilWindows('icacls.exe'), [dossier, '/inheritance:r', '/grant:r', `*${sid}:(OI)(CI)F`, '*S-1-5-18:(OI)(CI)F', '/Q'], { delai: 120_000 })

    if (droits.code !== 0) {
      throw new Error(droits.sortie.trim())
    }

    return { ...reglages, accesRestreint: true }
  } catch (erreur) {
    journal.alerte('acces', `Droits du dossier de données non restreints : ${erreur.message}`)

    return reglages
  }
}

/**
 * Les processus PHP d'une session qui s'est mal terminée (fin de tâche,
 * plantage) : ils écoutent encore, pour rien. Seuls ceux dont l'exécutable est
 * CELUI de Relais sont arrêtés — un numéro de processus peut avoir été repris
 * par un tout autre programme depuis.
 */
async function arreterOrphelins(fichier, programmes, journal) {
  if (!existsSync(fichier)) {
    return
  }

  let pids = []

  try {
    pids = JSON.parse(readFileSync(fichier, 'utf8')).pids.filter(Number.isInteger)
  } catch {
    // Illisible : rien à arrêter avec certitude.
  }

  rmSync(fichier, { force: true })

  if (pids.length === 0 || process.platform !== 'win32') {
    return
  }

  const script = `$ids = @(${pids.join(',')}); Get-CimInstance Win32_Process | Where-Object { $ids -contains $_.ProcessId } | ForEach-Object { '{0}|{1}' -f $_.ProcessId, $_.ExecutablePath }`
  const { sortie } = await executer(outilWindows('WindowsPowerShell', 'v1.0', 'powershell.exe'), ['-NoProfile', '-NonInteractive', '-Command', script], { delai: 30_000 })
  const dossierPhp = normalize(programmes.php).toLowerCase()

  for (const ligne of sortie.split(/\r?\n/)) {
    const [pid, chemin] = ligne.split('|')

    if (chemin && normalize(chemin.trim()).toLowerCase().startsWith(dossierPhp)) {
      try {
        process.kill(Number(pid))
        journal.alerte('pile', `Processus PHP orphelin arrêté (${pid}).`)
      } catch {
        // Déjà terminé entre-temps.
      }
    }
  }
}

export async function demarrerPile({
  programmes,
  racine,
  sceller,
  desceller,
  surEtape = () => {},
  journal = creerJournal(join(racine, 'journaux', 'relais.log')),
  production = true,
  taillePhp = Math.max(2, Math.min(4, cpus().length)),
}) {
  const d = cheminsDonnees(racine)
  const arrets = []
  let arretee = false

  const arreter = async () => {
    if (arretee) {
      return
    }

    arretee = true

    for (const etape of arrets.reverse()) {
      try {
        await etape()
      } catch (erreur) {
        journal.erreur('arret', erreur.message)
      }
    }

    rmSync(d.processus, { force: true })
    journal.info('pile', 'Arrêt terminé.')
  }

  try {
    for (const dossier of [d.racine, d.fichiers, d.boiteEnvoi, d.journaux, d.session]) {
      mkdirSync(dossier, { recursive: true })
    }

    // Vidé à chaque lancement : téléversements interrompus, archives d'export
    // déjà remises, cache d'opcache d'une version précédente.
    rmSync(d.temporaire, { recursive: true, force: true })
    mkdirSync(join(d.temporaire, 'opcache'), { recursive: true })

    journal.info('pile', 'Démarrage.')
    surEtape({ etape: 'coffre', message: 'Ouverture du coffre' })

    let reglages = await restreindreAcces(d.racine, lireReglages(d.reglages), journal)
    const { secrets, cree } = ouvrirCoffre({ fichier: d.coffre, sceller, desceller })

    const postgres = creerPostgres({
      binaires: programmes.postgres,
      donnees: d.base,
      fichierJournal: join(d.journaux, 'postgres.log'),
      journal,
      // Messages de pg_ctl et d'initdb en anglais : traduits, ils sortent dans
      // la page de code de la console Windows, illisibles dans un journal UTF-8.
      env: { ...environnementDeBase({ chemins: [programmes.postgres], temporaire: d.temporaire }), LC_MESSAGES: 'C' },
    })

    if (cree && postgres.estInitialisee()) {
      // Le coffre a disparu, pas la base : ses secrets ne se retrouvent pas,
      // et en générer d'autres ne rouvrirait rien.
      throw Object.assign(new Error('Le coffre de cette installation a disparu : la base existante ne peut plus être ouverte.'), {
        code: 'COFFRE_PERDU',
      })
    }

    await arreterOrphelins(d.processus, programmes, journal)

    const portBase = await portLibre(reglages.portBase)
    let portHttp = await portLibre(reglages.portHttp)

    if (portHttp === portBase) {
      portHttp = await portLibre()
    }

    reglages = { ...reglages, portBase, portHttp }
    ecrireReglages(d.reglages, reglages)

    if (!postgres.estInitialisee()) {
      surEtape({ etape: 'initdb', message: 'Création de la base de données locale' })
      await postgres.initialiser(secrets.motDePasseBase)
    }

    surEtape({ etape: 'base', message: 'Démarrage de la base de données' })
    await postgres.demarrer(portBase)
    arrets.push(() => postgres.arreter())

    // Deux jetons par lancement : celui de la fenêtre (cookie du poste) et
    // celui de php-cgi (la garde). Aucun n'est écrit ailleurs qu'en mémoire,
    // et dans le php.ini de la session pour le second.
    const jetonPhp = randomBytes(32).toString('hex')
    const jetonPoste = randomBytes(32).toString('base64url')
    const pointEntree = join(programmes.api, 'public', 'index.php')
    const ini = join(d.session, 'php.ini')

    writeFileSync(
      ini,
      iniPhp({
        php: programmes.php,
        garde: join(programmes.bureau, 'garde.php'),
        pointEntree,
        journaux: d.journaux,
        temporaire: d.temporaire,
        jeton: jetonPhp,
        production,
      }),
      { mode: 0o600 },
    )

    const origine = `http://127.0.0.1:${portHttp}`
    const barres = (chemin) => chemin.replaceAll('\\', '/')

    const envPhp = {
      ...environnementDeBase({ chemins: [programmes.php], temporaire: d.temporaire }),
      APP_ENV: 'production',
      APP_DEBUG: 'false',
      APP_KEY: secrets.appKey,
      JWT_SECRET: secrets.jwtSecret,
      JWT_ISSUER: 'relais-bureau',
      APP_FRONTEND_URL: origine,
      CORS_ALLOWED_ORIGIN: origine,
      // Pas de HTTPS sur l'adresse de bouclage : un cookie « Secure » n'y
      // serait jamais renvoyé. SameSite=Strict et HttpOnly restent.
      COOKIE_SECURE: 'false',
      COOKIE_SAMESITE: 'Strict',
      DB_HOST: '127.0.0.1',
      DB_PORT: String(portBase),
      DB_NAME: NOM_BASE,
      DB_USER: UTILISATEUR,
      DB_PASSWORD: secrets.motDePasseBase,
      STORAGE_PATH: barres(d.fichiers),
      MAIL_TRANSPORT: 'fichier',
      MAIL_OUTBOX_PATH: barres(d.boiteEnvoi),
      MAIL_FROM_ADDRESS: 'relais@poste.local',
      MAIL_FROM_NAME: 'Relais',
    }

    surEtape({ etape: 'schema', message: 'Préparation de la base' })

    const installation = await executerScriptPhp({
      php: programmes.php,
      ini,
      env: envPhp,
      script: join(programmes.bureau, 'installer.php'),
      args: [programmes.api],
      delai: 300_000,
      surLigne: (ligne) => {
        try {
          const evenement = JSON.parse(ligne)
          journal.info('installation', evenement.message)
          surEtape(evenement)
        } catch {
          journal.alerte('installation', ligne)
        }
      },
    })

    if (installation.code !== 0) {
      throw new Error(`Préparation de la base impossible.\n${installation.sortie}`)
    }

    surEtape({ etape: 'api', message: 'Démarrage de l’application' })

    const reserve = creerReservePhp({ php: programmes.php, ini, env: envPhp, taille: taillePhp, journal })
    arrets.push(() => reserve.arreter())
    await reserve.demarrer()

    const serveur = creerServeur({ port: portHttp, racineFront: programmes.front, pointEntree, jetonPoste, jetonPhp, reserve, journal })
    await serveur.ecouter()
    arrets.push(() => serveur.fermer())

    const worker = creerWorker({
      php: programmes.php,
      ini,
      env: envPhp,
      script: join(programmes.api, 'bin', 'worker.php'),
      fichierArret: join(d.session, 'arret-worker'),
      journal,
    })
    worker.demarrer()
    arrets.push(() => worker.arreter())

    const noterProcessus = () =>
      writeFileSync(d.processus, JSON.stringify({ pids: [...reserve.pids(), worker.pid()].filter(Boolean) }))

    noterProcessus()
    const minuterie = setInterval(noterProcessus, 30_000)
    minuterie.unref()
    arrets.push(() => clearInterval(minuterie))

    journal.info('pile', `Prête sur ${origine}.`)

    return { origine, jetonPoste, jetonPhp, portHttp, portBase, ini, envPhp, dossiers: d, journal, reserve, arreter }
  } catch (erreur) {
    journal.erreur('pile', erreur.stack ?? erreur.message)
    await arreter()

    throw erreur
  }
}
