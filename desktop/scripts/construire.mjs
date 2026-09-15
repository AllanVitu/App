// Construit l'installateur Windows de Relais.
//
//   npm run construire                 tout, dans l'ordre
//   npm run construire -- --sans-client  garde le client déjà compilé en édition de bureau
//
//   1. runtime/ doit être prêt (npm run preparer) ;
//   2. le client est compilé en édition de bureau : API sur la même origine
//      (VITE_API_BASE_URL=/api), textes légaux du poste (VITE_EDITION=bureau) ;
//   3. les tests de l'application de bureau passent ;
//   4. electron-builder produit dist/Relais-Installation-<version>.exe.
import { spawnSync } from 'node:child_process'
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { problemesDuClient } from './client.mjs'

const ICI = dirname(dirname(fileURLToPath(import.meta.url)))
const DEPOT = dirname(ICI)
const FRONT = join(DEPOT, 'front')
const TAMPON = join(FRONT, 'dist', '.edition')
const version = JSON.parse(readFileSync(join(ICI, 'package.json'), 'utf8')).version

function etape(texte) {
  console.log(`\n▸ ${texte}`)
}

function lancer(commande, args, options = {}) {
  const resultat = spawnSync(commande, args, { stdio: 'inherit', shell: process.platform === 'win32', ...options })

  if (resultat.status !== 0) {
    throw new Error(`Échec : ${commande} ${args.join(' ')}`)
  }
}

etape('Programmes embarqués')

for (const requis of ['runtime/php/php-cgi.exe', 'runtime/postgres/bin/postgres.exe', 'runtime/versions.json']) {
  if (!existsSync(join(ICI, requis))) {
    throw new Error(`${requis} absent : lancer « npm run preparer » d'abord.`)
  }
}

console.log('  PHP et PostgreSQL présents')

if (process.argv.includes('--sans-client')) {
  etape('Client : compilation existante')
} else {
  etape('Client : compilation en édition de bureau')

  const variables = { VITE_API_BASE_URL: '/api', VITE_EDITION: 'bureau', VITE_APP_RELEASE: `bureau-${version}` }

  if (existsSync(join(FRONT, 'node_modules'))) {
    lancer('npm', ['run', 'build'], { cwd: FRONT, env: { ...process.env, ...variables } })
  } else {
    // Poste de développement : les dépendances du client vivent dans le
    // conteneur « node » (volume Docker), pas sur Windows.
    lancer(
      'docker',
      ['compose', 'exec', '-T', ...Object.entries(variables).flatMap(([cle, valeur]) => ['-e', `${cle}=${valeur}`]), 'node', 'npm', 'run', 'build'],
      { cwd: DEPOT },
    )
  }

  // Un fichier caché (jamais servi, exclu de l'installateur) dit pour quelle
  // édition dist/ a été compilé : le client web et celui du poste partagent
  // ce dossier.
  writeFileSync(TAMPON, 'bureau\n')
}

const problemes = problemesDuClient(join(FRONT, 'dist'))

if (problemes.length > 0) {
  throw new Error(`Client compilé inutilisable :\n  - ${problemes.join('\n  - ')}`)
}

console.log('  client contrôlé : édition de bureau, API sur la même origine')

etape('Tests')
lancer('node', ['--test', 'tests/**/*.test.mjs'], { cwd: ICI })

etape('Installateur')
lancer('npx', ['electron-builder', '--win', 'nsis', '--x64', '--publish', 'never'], { cwd: ICI })

console.log(`\n  dist/Relais-Installation-${version}.exe`)
