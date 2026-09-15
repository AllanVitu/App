import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { EXTENSIONS_PHP, iniPhp, valeurIni } from '../src/services/php.js'

const ICI = dirname(dirname(fileURLToPath(import.meta.url)))

test('une valeur de php.ini n’est jamais interprétée', () => {
  assert.equal(valeurIni('C:\\Users\\Marie\\AppData'), "'C:/Users/Marie/AppData'")

  // Une apostrophe dans le nom du compte : guillemets doubles, sans rien à y interpréter.
  assert.equal(valeurIni("C:\\Users\\O'Brien"), `"C:/Users/O'Brien"`)

  assert.throws(() => valeurIni("C:\\Users\\O'Brien\\${HOME}"))
  assert.throws(() => valeurIni('C:\\a\nextension=evil'))
  assert.throws(() => valeurIni('C:\\a\0b'))
})

test('le php.ini de la session pose la garde et ferme ce qui la contournerait', () => {
  const ini = iniPhp({
    php: 'C:\\Relais\\runtime\\php',
    garde: 'C:\\Relais\\app\\bureau\\garde.php',
    pointEntree: 'C:\\Relais\\app\\back\\public\\index.php',
    journaux: 'C:\\Donnees\\journaux',
    temporaire: 'C:\\Donnees\\temporaire',
    jeton: 'f'.repeat(64),
    production: true,
  })

  const lignes = ini.split('\r\n')

  assert.ok(lignes.includes("auto_prepend_file = 'C:/Relais/app/bureau/garde.php'"))
  assert.ok(lignes.includes('user_ini.filename ='))
  assert.ok(lignes.includes('cgi.fix_pathinfo = 0'))
  assert.ok(lignes.includes('expose_php = Off'))
  assert.ok(lignes.includes('display_errors = Off'))
  assert.ok(lignes.includes('allow_url_include = Off'))
  assert.ok(lignes.includes('opcache.validate_timestamps = 0'))
  assert.ok(lignes.includes(`relais.jeton = "${'f'.repeat(64)}"`))
  assert.ok(lignes.includes("relais.point_entree = 'C:/Relais/app/back/public/index.php'"))

  for (const extension of EXTENSIONS_PHP) {
    assert.ok(lignes.includes(`extension = ${extension}`), extension)
  }
})

test('les extensions chargées sont celles de l’image Docker', () => {
  const dockerfile = readFileSync(join(ICI, '..', 'docker', 'php', 'Dockerfile'), 'utf8')

  // Ajoutées par docker-php-ext-install ; les autres (curl, mbstring, openssl,
  // sodium, fileinfo) sont compilées d'office dans l'image officielle.
  for (const extension of ['pdo_pgsql', 'intl', 'zip']) {
    assert.match(dockerfile, new RegExp(`\\b${extension}\\b`))
    assert.ok(EXTENSIONS_PHP.includes(extension), extension)
  }
})

test('la garde et l’installateur sont livrés', () => {
  assert.ok(existsSync(join(ICI, 'php', 'garde.php')))
  assert.ok(existsSync(join(ICI, 'php', 'installer.php')))
  assert.match(readFileSync(join(ICI, 'php', 'garde.php'), 'utf8'), /hash_equals/)
})
