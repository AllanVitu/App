// PHP sur le poste : l'API servie par une réserve de processus php-cgi, et le worker.
//
// Dans l'image Docker, PHP-FPM gère lui-même ses processus. Sous Windows, il
// n'existe pas : php-cgi y sert une connexion FastCGI à la fois, sans jamais
// se dupliquer. La réserve en lance plusieurs, chacun sur son port, et confie
// chaque requête au premier libre.
import { spawn } from 'node:child_process'
import { rmSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { requeteFastCgi } from './fastcgi.js'
import { attendrePort, portLibre } from './ports.js'
import { executer } from './processus.js'

/** Les extensions que l'API charge : celles de l'image Docker, ni plus ni moins. */
export const EXTENSIONS_PHP = ['curl', 'fileinfo', 'intl', 'mbstring', 'openssl', 'pdo_pgsql', 'sodium', 'zip']

/**
 * Une valeur de php.ini. Entre guillemets simples, rien n'est interprété ;
 * entre doubles, « ${…} » le serait. Un chemin Windows peut contenir une
 * apostrophe (C:\Users\O'Brien) : il passe alors en guillemets doubles, s'il
 * ne contient rien qu'ils interpréteraient.
 */
export function valeurIni(valeur) {
  const texte = String(valeur).replaceAll('\\', '/')

  if (/[\r\n\0]/.test(texte)) {
    throw new Error('Valeur de php.ini invalide.')
  }

  if (!texte.includes("'")) {
    return `'${texte}'`
  }

  if (!texte.includes('"') && !texte.includes('${')) {
    return `"${texte}"`
  }

  throw new Error(`Chemin inutilisable dans php.ini : ${texte}`)
}

/**
 * Le php.ini de la session. Il reprend les réglages de production de l'image
 * Docker (docker/php/php.prod.ini), et y ajoute ce que le poste impose.
 */
export function iniPhp({ php, garde, pointEntree, journaux, temporaire, jeton, production }) {
  return [
    '; Écrit par Relais à chaque lancement : une modification ici serait écrasée.',
    '',
    '[PHP]',
    'expose_php = Off',
    'memory_limit = 256M',
    'max_execution_time = 30',
    'post_max_size = 20M',
    'upload_max_filesize = 20M',
    'date.timezone = UTC',
    'default_charset = "UTF-8"',
    'display_errors = Off',
    'display_startup_errors = Off',
    'error_reporting = E_ALL',
    'log_errors = On',
    `error_log = ${valeurIni(join(journaux, 'php.log'))}`,
    'zend.exception_ignore_args = On',
    'allow_url_include = Off',
    '',
    '; Fichiers temporaires (téléversements, archives d’export) : dans le dossier',
    '; du compte, vidé à chaque lancement — jamais dans le dossier temporaire commun.',
    `sys_temp_dir = ${valeurIni(temporaire)}`,
    `upload_tmp_dir = ${valeurIni(temporaire)}`,
    '',
    '; La garde du point d’entrée (bureau/garde.php) passe avant tout script, et',
    '; aucun dossier ne peut la désactiver par un .user.ini.',
    `auto_prepend_file = ${valeurIni(garde)}`,
    'user_ini.filename =',
    'cgi.force_redirect = 0',
    'cgi.fix_pathinfo = 0',
    '',
    `extension_dir = ${valeurIni(join(php, 'ext'))}`,
    ...EXTENSIONS_PHP.map((nom) => `extension = ${nom}`),
    'zend_extension = opcache',
    '',
    '; Les sondes de disponibilité vérifient les certificats : magasin de Mozilla.',
    `curl.cainfo = ${valeurIni(join(php, 'cacert.pem'))}`,
    `openssl.cafile = ${valeurIni(join(php, 'cacert.pem'))}`,
    '',
    '[opcache]',
    'opcache.enable = 1',
    'opcache.enable_cli = 0',
    'opcache.memory_consumption = 64',
    'opcache.interned_strings_buffer = 8',
    'opcache.max_accelerated_files = 4000',
    // Installé, le code ne change qu'avec une mise à jour, qui relance tout.
    `opcache.validate_timestamps = ${production ? 0 : 1}`,
    `opcache.file_cache = ${valeurIni(join(temporaire, 'opcache'))}`,
    'opcache.file_cache_fallback = 1',
    '',
    '[Relais]',
    '; Lus par la garde avec get_cfg_var() — aucun paramètre de requête ne les remplace.',
    `relais.jeton = "${jeton}"`,
    `relais.point_entree = ${valeurIni(pointEntree)}`,
    '',
  ].join('\r\n')
}

export function creerReservePhp({ php, ini, env, taille = 4, requetesMax = 500, journal }) {
  const executable = join(php, 'php-cgi.exe')
  const attente = []
  let arretee = false

  const travailleurs = Array.from({ length: taille }, (_, rang) => ({
    rang,
    port: null,
    processus: null,
    pret: false,
    occupe: false,
    requetes: 0,
    morts: [],
  }))

  function distribuer() {
    while (attente.length > 0) {
      const libre = travailleurs.find((t) => t.pret && !t.occupe)

      if (!libre) {
        return
      }

      libre.occupe = true
      attente.shift().servir(libre)
    }
  }

  async function lancer(t) {
    if (arretee) {
      return
    }

    t.port = await portLibre(t.port ?? undefined)
    t.requetes = 0

    const source = `php-cgi ${t.rang}`
    const processus = spawn(executable, ['-c', ini, '-b', `127.0.0.1:${t.port}`], {
      env: { ...env, PHP_FCGI_MAX_REQUESTS: String(requetesMax), FCGI_WEB_SERVER_ADDRS: '127.0.0.1' },
      windowsHide: true,
      stdio: ['ignore', 'pipe', 'pipe'],
    })

    t.processus = processus

    processus.stdout.on('data', (m) => journal.alerte(source, m.toString('utf8')))
    processus.stderr.on('data', (m) => journal.alerte(source, m.toString('utf8')))
    processus.on('error', (e) => journal.erreur(source, e.message))

    processus.on('exit', (code) => {
      if (t.processus !== processus) {
        return
      }

      t.processus = null
      t.pret = false

      if (arretee) {
        return
      }

      if (t.requetes < requetesMax) {
        journal.alerte(source, `arrêt inattendu (code ${code}), relance`)
      }

      // Un processus qui meurt en boucle — configuration cassée, antivirus
      // qui bloque l'exécutable — voit ses relances espacées, plutôt que de
      // saturer la machine.
      const maintenant = Date.now()
      t.morts = [...t.morts.filter((m) => maintenant - m < 60_000), maintenant]

      setTimeout(() => lancer(t).catch((e) => journal.erreur(source, e.message)), t.morts.length > 5 ? 10_000 : 50)
    })

    await attendrePort(t.port, { delai: 20_000 })

    if (t.processus === processus) {
      t.pret = true
      distribuer()
    }
  }

  function acquerir(delai = 30_000) {
    return new Promise((resoudre, rejeter) => {
      const demande = {
        servir: (t) => {
          clearTimeout(minuterie)
          resoudre(t)
        },
      }

      const minuterie = setTimeout(() => {
        attente.splice(attente.indexOf(demande), 1)
        rejeter(Object.assign(new Error('Aucun processus PHP disponible.'), { code: 'SATURE' }))
      }, delai)

      attente.push(demande)
      distribuer()
    })
  }

  async function traiter({ parametres, corps, surEntetes, surCorps }) {
    for (let essai = 1; ; essai += 1) {
      const t = await acquerir()

      t.requetes += 1

      // php-cgi s'arrête de lui-même après sa N-ième requête (recyclage
      // contre les fuites de mémoire) : il n'en reçoit pas une de plus.
      if (t.requetes >= requetesMax) {
        t.pret = false
      }

      try {
        await requeteFastCgi({
          port: t.port,
          parametres,
          corps,
          surEntetes,
          surCorps,
          surErreurPhp: (message) => journal.alerte('php', message),
        }).promesse

        return
      } catch (erreur) {
        if (erreur.code === 'DELAI') {
          t.pret = false
          t.processus?.kill()
        }

        // Connexion refusée : rien n'est parti, la requête peut être confiée
        // à un autre processus sans risque d'être jouée deux fois.
        if (erreur.code === 'ECONNREFUSED') {
          t.pret = false
          t.processus?.kill()

          if (essai < 3) {
            continue
          }
        }

        throw erreur
      } finally {
        t.occupe = false

        if (t.requetes >= requetesMax && t.processus) {
          const p = t.processus
          setTimeout(() => t.processus === p && p.kill(), 2_000)
        }

        distribuer()
      }
    }
  }

  async function arreter() {
    arretee = true

    const sorties = travailleurs
      .filter((t) => t.processus)
      .map(
        (t) =>
          new Promise((resoudre) => {
            t.processus.once('exit', resoudre)
            t.processus.kill()
            setTimeout(resoudre, 5_000)
          }),
      )

    await Promise.all(sorties)
  }

  return {
    demarrer: () => Promise.all(travailleurs.map(lancer)),
    traiter,
    arreter,
    pids: () => travailleurs.map((t) => t.processus?.pid).filter(Boolean),
    ports: () => travailleurs.filter((t) => t.pret).map((t) => t.port),
    prets: () => travailleurs.filter((t) => t.pret).length,
  }
}

export function creerWorker({ php, ini, env, script, fichierArret, journal }) {
  let processus = null
  let arrete = false
  let morts = []

  function lancer() {
    if (arrete) {
      return
    }

    rmSync(fichierArret, { force: true })

    const p = spawn(join(php, 'php.exe'), ['-c', ini, script], {
      env: { ...env, WORKER_FICHIER_ARRET: fichierArret },
      windowsHide: true,
      stdio: ['ignore', 'pipe', 'pipe'],
    })

    processus = p
    p.stdout.on('data', (m) => journal.info('worker', m.toString('utf8')))
    p.stderr.on('data', (m) => journal.alerte('worker', m.toString('utf8')))
    p.on('error', (e) => journal.erreur('worker', e.message))

    p.on('exit', (code) => {
      if (processus === p) {
        processus = null
      }

      if (arrete) {
        return
      }

      const maintenant = Date.now()
      morts = [...morts.filter((m) => maintenant - m < 300_000), maintenant]
      const pause = Math.min(60_000, 1_000 * 2 ** (morts.length - 1))

      journal.alerte('worker', `arrêté (code ${code}), relance dans ${Math.round(pause / 1000)} s`)
      setTimeout(lancer, pause)
    })
  }

  /** L'arrêt propre : le worker finit sa tâche en cours, puis sort de lui-même. */
  async function arreter(delai = 15_000) {
    arrete = true

    const p = processus

    if (!p) {
      return
    }

    writeFileSync(fichierArret, '')

    const sorti = await new Promise((resoudre) => {
      const minuterie = setTimeout(() => resoudre(false), delai)

      p.once('exit', () => {
        clearTimeout(minuterie)
        resoudre(true)
      })
    })

    if (!sorti) {
      journal.alerte('worker', 'arrêt forcé : la tâche en cours sera reprise au prochain lancement')
      p.kill()
    }

    rmSync(fichierArret, { force: true })
  }

  return { demarrer: lancer, arreter, pid: () => processus?.pid }
}

/** Un script PHP en ligne de commande, avec la configuration de la session. */
export function executerScriptPhp({ php, ini, env, script, args = [], surLigne, delai }) {
  return executer(join(php, 'php.exe'), ['-c', ini, script, ...args], { env, surLigne, delai })
}
