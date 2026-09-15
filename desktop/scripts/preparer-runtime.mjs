// Prépare les deux programmes que Relais embarque : PHP et PostgreSQL.
//
//   npm run preparer
//
// Rien n'est pris sur la foi d'un nom de fichier :
//   - PHP est téléchargé depuis windows.php.net, et l'archive n'est dépliée que
//     si son empreinte SHA-256 est celle publiée pour cette version ;
//   - PostgreSQL vient du paquet npm épinglé dans package.json, dont
//     package-lock.json fixe l'empreinte d'intégrité ;
//   - les autorités de certification données à PHP (pour les sondes de
//     disponibilité, en HTTPS) sont celles que Node embarque : le magasin de
//     Mozilla, sans second téléchargement à vérifier.
//
// Le résultat, runtime/, n'est pas versionné : il se reconstruit à l'identique.
import { spawnSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { cpSync, existsSync, mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { rootCertificates } from 'node:tls'
import { fileURLToPath } from 'node:url'
import { EXTENSIONS_PHP } from '../src/services/php.js'

const ICI = dirname(dirname(fileURLToPath(import.meta.url)))
const RUNTIME = join(ICI, 'runtime')
const CACHE = join(RUNTIME, '.telechargements')

const PHP = {
  version: '8.3.33',
  fichier: 'php-8.3.33-nts-Win32-vs16-x64.zip',
  sha256: '534399107056313246f424adbbb7937337e40fbbf6aa7bc26287ba9cfd2e4a2a',
  // Une version remplacée par la suivante part dans « archives » : les deux
  // adresses sont essayées, l'empreinte départage.
  sources: [
    'https://windows.php.net/downloads/releases/',
    'https://windows.php.net/downloads/releases/archives/',
  ],
}

function etape(texte) {
  console.log(`\n▸ ${texte}`)
}

function sha256(contenu) {
  return createHash('sha256').update(contenu).digest('hex')
}

async function telecharger({ fichier, sha256: attendue, sources }) {
  const local = join(CACHE, fichier)

  if (existsSync(local) && sha256(readFileSync(local)) === attendue) {
    console.log(`  déjà téléchargé : ${fichier}`)

    return local
  }

  for (const source of sources) {
    const url = source + fichier
    console.log(`  ${url}`)

    const reponse = await fetch(url)

    if (!reponse.ok) {
      console.log(`  → ${reponse.status}, adresse suivante`)

      continue
    }

    const contenu = Buffer.from(await reponse.arrayBuffer())
    const obtenue = sha256(contenu)

    if (obtenue !== attendue) {
      throw new Error(`Empreinte inattendue pour ${fichier} :\n  attendue ${attendue}\n  obtenue  ${obtenue}`)
    }

    mkdirSync(CACHE, { recursive: true })
    writeFileSync(local, contenu)
    console.log(`  empreinte vérifiée (${(contenu.length / 1048576).toFixed(1)} Mo)`)

    return local
  }

  throw new Error(`${fichier} introuvable sur les adresses connues.`)
}

function deplier(archive, destination) {
  mkdirSync(destination, { recursive: true })

  // Le tar de Windows (bsdtar, livré depuis Windows 10) lit le zip ; celui de
  // Git Bash, non. Le chemin complet évite de tomber sur le second.
  const tar = join(process.env.SystemRoot ?? 'C:\\Windows', 'System32', 'tar.exe')
  const resultat = spawnSync(tar, ['-xf', archive, '-C', destination], { stdio: 'inherit' })

  if (resultat.status !== 0) {
    throw new Error(`Dépliage impossible : ${archive}`)
  }
}

async function preparerPhp() {
  etape(`PHP ${PHP.version}`)

  const archive = await telecharger(PHP)
  const dossier = join(RUNTIME, 'php')

  rmSync(dossier, { recursive: true, force: true })
  deplier(archive, dossier)

  // Ce qui sert à compiler ou à déboguer PHP n'a rien à faire chez l'utilisateur.
  for (const inutile of ['dev', 'extras', 'lib', 'phpdbg.exe', 'php-win.exe', 'deplister.exe', 'php.ini-development', 'php.ini-production', 'news.txt', 'README.md', 'snapshot.txt', 'install.txt']) {
    rmSync(join(dossier, inutile), { recursive: true, force: true })
  }

  // Ce que l'API ne charge pas n'a pas à être livré.
  const gardees = new Set([...EXTENSIONS_PHP.map((nom) => `php_${nom}.dll`), 'php_opcache.dll'])

  for (const dll of readdirSync(join(dossier, 'ext'))) {
    if (!gardees.has(dll)) {
      rmSync(join(dossier, 'ext', dll), { force: true })
    }
  }

  writeFileSync(join(dossier, 'cacert.pem'), rootCertificates.join('\n') + '\n')
  console.log(`  ${EXTENSIONS_PHP.length} extensions + opcache, ${rootCertificates.length} autorités de certification`)
}

function preparerPostgres() {
  const paquet = join(ICI, 'node_modules', '@embedded-postgres', 'windows-x64')
  const version = JSON.parse(readFileSync(join(paquet, 'package.json'), 'utf8')).version

  etape(`PostgreSQL ${version}`)

  if (!existsSync(join(paquet, 'native', 'bin', 'postgres.exe'))) {
    throw new Error('Paquet PostgreSQL absent : lancer « npm install » dans desktop/.')
  }

  const dossier = join(RUNTIME, 'postgres')
  rmSync(dossier, { recursive: true, force: true })

  for (const partie of ['bin', 'lib', 'share']) {
    cpSync(join(paquet, 'native', partie), join(dossier, partie), { recursive: true })
  }

  // Sous Windows, le paquet ne contient pas de liens symboliques ; s'il en
  // déclarait, ils sont recopiés comme fichiers — un installateur NSIS ne
  // sait pas les restituer.
  const liens = JSON.parse(readFileSync(join(paquet, 'native', 'pg-symlinks.json'), 'utf8'))

  for (const { source, target } of Array.isArray(liens) ? liens : []) {
    cpSync(join(dossier, target), join(dossier, source))
  }

  // La licence PostgreSQL demande que sa notice accompagne toute copie.
  cpSync(join(paquet, 'LICENSE.md'), join(dossier, 'LICENSE.md'))

  // Ni include/ ni les outils client : le serveur, son initialisation, son pilotage.
  console.log(`  ${readdirSync(join(dossier, 'bin')).filter((f) => f.endsWith('.exe')).join(', ')}`)

  return version
}

async function principal() {
  mkdirSync(RUNTIME, { recursive: true })

  await preparerPhp()
  const postgres = preparerPostgres()

  writeFileSync(
    join(RUNTIME, 'versions.json'),
    JSON.stringify({ php: PHP.version, phpSha256: PHP.sha256, postgres, extensions: EXTENSIONS_PHP }, null, 2) + '\n',
  )

  etape('runtime/ prêt')
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  await principal()
}
