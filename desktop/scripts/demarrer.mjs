// Lance l'application depuis le dépôt.
//
//   npm run demarrer
//
// Sans ELECTRON_RUN_AS_NODE : un terminal ouvert depuis un outil lui-même bâti
// sur Electron (l'hôte d'extensions d'un éditeur, par exemple) peut l'avoir
// hérité, et electron.exe se comporterait alors comme un simple Node.js. Dans
// l'installateur, un fusible rend cette variable sans effet.
import { spawn } from 'node:child_process'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const ICI = dirname(dirname(fileURLToPath(import.meta.url)))
const { ELECTRON_RUN_AS_NODE, ...env } = process.env

const electron = spawn(join(ICI, 'node_modules', 'electron', 'dist', 'electron.exe'), [ICI, ...process.argv.slice(2)], {
  env,
  stdio: 'inherit',
})

electron.on('exit', (code) => process.exit(code ?? 0))
