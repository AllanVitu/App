// Vérifie la pile locale de bout en bout, sans Electron.
//
//   npm run verifier              dossier de données jetable, effacé si tout passe
//   npm run verifier -- --garder  le garde, pour l'inspecter
//
// Ce qui est vérifié est ce qui est livré : services/pile.js, celui que lance
// l'application. Seul le scellement du coffre diffère — DPAPI n'existe que
// sous Electron.
import assert from 'node:assert/strict'
import { existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import http from 'node:http'
import net from 'node:net'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { lireMessage, listerMessages } from '../src/services/boite-envoi.js'
import { requeteFastCgi } from '../src/services/fastcgi.js'
import { creerJournal } from '../src/services/journal.js'
import { cheminsProgrammes, demarrerPile } from '../src/services/pile.js'
import { executer, outilWindows } from '../src/services/processus.js'
import { COOKIE_POSTE } from '../src/services/serveur.js'
import { problemesDuClient } from './client.mjs'

const ICI = dirname(dirname(fileURLToPath(import.meta.url)))
const programmes = cheminsProgrammes({ emballee: false, desktop: ICI })
const racine = mkdtempSync(join(tmpdir(), 'relais-verification-'))
const journal = creerJournal(join(racine, 'journaux', 'relais.log'))

const sceller = (texte) => Buffer.from(`essai:${Buffer.from(texte).toString('base64')}`)
const desceller = (octets) => {
  const texte = octets.toString()
  assert.ok(texte.startsWith('essai:'), 'coffre scellé autrement')

  return Buffer.from(texte.slice(6), 'base64').toString()
}

let echecs = 0

async function verifier(nom, epreuve) {
  const debut = performance.now()

  try {
    const note = await epreuve()
    console.log(`  ✓ ${nom}${note ? ` — ${note}` : ''} (${Math.round(performance.now() - debut)} ms)`)

    return true
  } catch (erreur) {
    echecs += 1
    console.log(`  ✗ ${nom}\n      ${String(erreur.message).split('\n').slice(0, 12).join('\n      ')}`)

    return false
  }
}

async function attendre(condition, delai = 30_000) {
  const limite = Date.now() + delai

  while (Date.now() < limite) {
    const resultat = await condition()

    if (resultat) {
      return resultat
    }

    await new Promise((r) => setTimeout(r, 250))
  }

  throw new Error('délai dépassé')
}

function portOuvert(port) {
  return new Promise((resoudre) => {
    const socket = net.connect({ port, host: '127.0.0.1' })
    socket.once('connect', () => {
      socket.destroy()
      resoudre(true)
    })
    socket.once('error', () => resoudre(false))
  })
}

/** Une requête comme la fenêtre de Relais l'envoie : même origine, cookie du poste. */
function requete(pile, { methode = 'GET', chemin, json, corps, type, jeton, cookie = true, hote } = {}) {
  const donnees = json !== undefined ? Buffer.from(JSON.stringify(json)) : corps

  return new Promise((resoudre, rejeter) => {
    const req = http.request(
      {
        host: '127.0.0.1',
        port: pile.portHttp,
        method: methode,
        path: chemin,
        headers: {
          Host: hote ?? `127.0.0.1:${pile.portHttp}`,
          Accept: 'application/json',
          ...(cookie ? { Cookie: `${COOKIE_POSTE}=${pile.jetonPoste}` } : {}),
          ...(jeton ? { Authorization: `Bearer ${jeton}` } : {}),
          ...(methode !== 'GET' ? { Origin: pile.origine } : {}),
          ...(donnees ? { 'Content-Type': type ?? 'application/json', 'Content-Length': donnees.length } : {}),
        },
      },
      (res) => {
        const morceaux = []
        res.on('data', (m) => morceaux.push(m))
        res.on('end', () => {
          const octets = Buffer.concat(morceaux)
          resoudre({ statut: res.statusCode, entetes: res.headers, octets, texte: octets.toString(), json: () => JSON.parse(octets.toString()) })
        })
      },
    )

    req.on('error', rejeter)

    if (donnees) {
      req.write(donnees)
    }

    req.end()
  })
}

const demarrer = (options = {}) =>
  demarrerPile({
    programmes,
    racine,
    sceller,
    desceller,
    journal,
    production: true,
    surEtape: ({ message }) => console.log(`      · ${message}`),
    ...options,
  })

console.log(`\nRelais — vérification de la pile locale\n  données : ${racine}\n`)

let pile = null

await verifier('premier démarrage : coffre, base créée, schéma, API, worker', async () => {
  pile = await demarrer()

  return pile.origine
})

if (!pile) {
  console.log('\n  Fin du journal :\n')
  console.log(readFileSync(journal.fichier, 'utf8').split('\n').slice(-40).join('\n'))
  process.exit(1)
}

// --- Les portes fermées ------------------------------------------------------

await verifier('le client compilé est celui du poste : édition de bureau, API sur la même origine', () => {
  const problemes = problemesDuClient(programmes.front)

  assert.deepEqual(problemes, [], problemes.join('\n'))
})

await verifier('le client est servi, avec les en-têtes de sécurité', async () => {
  const r = await requete(pile, { chemin: '/' })

  assert.equal(r.statut, 200)
  assert.match(r.texte, /<div id="app">/)
  assert.match(r.entetes['content-security-policy'], /script-src 'self'/)
  assert.equal(r.entetes['x-frame-options'], 'DENY')
})

await verifier('sans le cookie du poste : 403, pour le client comme pour l’API', async () => {
  assert.equal((await requete(pile, { chemin: '/', cookie: false })).statut, 403)
  assert.equal((await requete(pile, { chemin: '/api/health', cookie: false })).statut, 403)
})

await verifier('sous un autre nom d’hôte : 421', async () => {
  assert.equal((await requete(pile, { chemin: '/api/health', hote: `localhost:${pile.portHttp}` })).statut, 421)
})

await verifier('rien hors du client compilé', async () => {
  for (const chemin of ['/..%2fpackage.json', '/%2e%2e/package.json', '/..%5cpackage.json', '/.edition', '/index.html%3a%3a$DATA']) {
    assert.doesNotMatch((await requete(pile, { chemin })).texte, /"devDependencies"|^bureau/, chemin)
  }
})

await verifier('php-cgi joint en direct : la garde refuse tout ce qui ne vient pas du serveur', async () => {
  const pointEntree = join(programmes.api, 'public', 'index.php')
  const intrus = join(racine, 'intrus')

  mkdirSync(intrus, { recursive: true })
  writeFileSync(join(intrus, 'intrus.php'), "<?php echo 'EXECUTE_SANS_GARDE';")
  writeFileSync(join(intrus, '.user.ini'), 'auto_prepend_file = none\n')

  const appeler = async (script, jeton) => {
    let statut = 0
    const corps = []

    await requeteFastCgi({
      port: pile.reserve.ports()[0],
      parametres: {
        GATEWAY_INTERFACE: 'CGI/1.1',
        SERVER_PROTOCOL: 'HTTP/1.1',
        REQUEST_METHOD: 'GET',
        REQUEST_URI: '/api/health',
        QUERY_STRING: '',
        SCRIPT_FILENAME: script,
        SCRIPT_NAME: '/index.php',
        HTTP_HOST: `127.0.0.1:${pile.portHttp}`,
        REMOTE_ADDR: '127.0.0.1',
        ...(jeton ? { RELAIS_JETON: jeton } : {}),
      },
      surEntetes: (e) => (statut = e.statut),
      surCorps: (m) => corps.push(m),
    }).promesse

    return { statut, texte: Buffer.concat(corps).toString() }
  }

  const cas = [
    ['point d’entrée, sans jeton', pointEntree, null],
    ['point d’entrée, faux jeton', pointEntree, 'f'.repeat(64)],
    ['autre script de l’API, bon jeton', join(programmes.api, 'bin', 'migrate.php'), pile.jetonPhp],
    ['script déposé ailleurs, sans jeton', join(intrus, 'intrus.php'), null],
    ['script déposé ailleurs, bon jeton', join(intrus, 'intrus.php'), pile.jetonPhp],
  ]

  for (const [nom, script, jeton] of cas) {
    const { statut, texte } = await appeler(script, jeton)

    assert.equal(statut, 403, nom)
    assert.doesNotMatch(texte, /EXECUTE_SANS_GARDE|"status"/, nom)
  }

  assert.equal((await appeler(pointEntree, pile.jetonPhp)).statut, 200, 'le serveur lui-même doit passer')

  return `${cas.length} tentatives refusées`
})

await verifier('la base n’accepte pas un mauvais mot de passe', async () => {
  const script = join(racine, 'intrus', 'connexion.php')
  writeFileSync(
    script,
    `<?php try { new PDO('pgsql:host=127.0.0.1;port=${pile.portBase};dbname=relais', 'relais', 'mauvais'); echo 'ACCEPTE'; } catch (PDOException) { echo 'REFUSE'; }`,
  )

  const { sortie } = await executer(join(programmes.php, 'php.exe'), ['-c', pile.ini, script], { env: pile.envPhp })
  assert.equal(sortie.trim(), 'REFUSE')
})

await verifier('le dossier de données est réservé au compte', async () => {
  if (process.platform !== 'win32') {
    return 'hors Windows : sans objet'
  }

  const { sortie } = await executer(outilWindows('icacls.exe'), [racine])
  const droits = sortie.split(/\r?\n/).filter((ligne) => ligne.includes(':('))

  assert.equal(droits.length, 2, sortie)
  assert.doesNotMatch(sortie, /\(I\)/, 'des droits sont encore hérités du profil')
})

// --- Les parcours -----------------------------------------------------------

const compte = {
  full_name: 'Camille Vérification',
  email: `camille.verification.${Date.now()}@exemple.fr`,
  password: 'Relais-Verif-2026!x',
}

let jeton = null

await verifier('santé de l’API', async () => {
  const r = await requete(pile, { chemin: '/api/health' })
  assert.equal(r.statut, 200, r.texte)

  return r.texte.slice(0, 80)
})

await verifier('inscription : compte créé, cookie de session HttpOnly et SameSite=Strict', async () => {
  const r = await requete(pile, {
    methode: 'POST',
    chemin: '/api/auth/register',
    json: { ...compte, password_confirmation: compte.password, terms_accepted: true },
  })

  assert.equal(r.statut, 201, r.texte)
  jeton = r.json().data?.access_token
  assert.ok(jeton, r.texte.slice(0, 200))

  const cookie = [r.entetes['set-cookie'] ?? []].flat().find((c) => /HttpOnly/i.test(c))
  assert.ok(cookie, 'cookie de session absent')
  assert.match(cookie, /SameSite=Strict/i)
  assert.match(cookie, /path=\/api\/auth/i)
})

let lienConfirmation = null

await verifier('l’e-mail de confirmation arrive dans la boîte d’envoi', async () => {
  const boite = pile.dossiers.boiteEnvoi
  const [premier] = await attendre(() => listerMessages(boite).length > 0 && listerMessages(boite))
  const message = lireMessage(boite, premier.id)

  assert.match(message.a, new RegExp(compte.email.replaceAll('.', '\\.')))
  lienConfirmation = message.liens.find((lien) => lien.startsWith(`${pile.origine}/`))
  assert.ok(lienConfirmation, `aucun lien vers ${pile.origine} : ${message.liens.join(', ')}`)

  return message.sujet
})

await verifier('le lien confirme l’adresse', async () => {
  const token = new URL(lienConfirmation).searchParams.get('token')
  assert.ok(token, lienConfirmation)

  const r = await requete(pile, { methode: 'POST', chemin: '/api/auth/email/verify', json: { token }, jeton })
  assert.ok(r.statut < 300, `${r.statut} ${r.texte}`)

  const moi = await requete(pile, { chemin: '/api/auth/me', jeton })
  assert.equal(moi.statut, 200, moi.texte)
  assert.match(moi.texte, /"email_verified_at":"\d{4}-/)
})

await verifier('connexion, et refus d’un mauvais mot de passe', async () => {
  const bon = await requete(pile, { methode: 'POST', chemin: '/api/auth/login', json: { email: compte.email, password: compte.password } })
  assert.equal(bon.statut, 200, bon.texte)
  assert.ok(bon.json().data?.access_token, bon.texte.slice(0, 200))

  const mauvais = await requete(pile, { methode: 'POST', chemin: '/api/auth/login', json: { email: compte.email, password: 'pas-le-bon-1A!' } })
  assert.ok(mauvais.statut >= 400 && mauvais.statut < 500, `${mauvais.statut}`)
  assert.doesNotMatch(mauvais.texte, /access_token/)
})

await verifier('export des données : une archive ZIP, construite dans le dossier temporaire du compte', async () => {
  const r = await requete(pile, { chemin: '/api/profile/export', jeton })

  assert.equal(r.statut, 200, r.texte.slice(0, 300))
  assert.equal(r.octets.subarray(0, 2).toString(), 'PK')

  return `${Math.round(r.octets.length / 1024)} Ko`
})

await verifier('photo de profil : téléversée, vérifiée, rangée dans le dossier du compte', async () => {
  const image = readFileSync(join(ICI, 'build', 'icon.png'))
  const frontiere = `----relais${Date.now()}`
  const corps = Buffer.concat([
    Buffer.from(`--${frontiere}\r\nContent-Disposition: form-data; name="avatar"; filename="photo.png"\r\nContent-Type: image/png\r\n\r\n`),
    image,
    Buffer.from(`\r\n--${frontiere}--\r\n`),
  ])

  const r = await requete(pile, { methode: 'POST', chemin: '/api/profile/avatar', corps, type: `multipart/form-data; boundary=${frontiere}`, jeton })
  assert.equal(r.statut, 200, r.texte.slice(0, 300))

  const deposes = readdirSync(pile.dossiers.fichiers, { recursive: true, withFileTypes: true }).filter((e) => e.isFile())
  assert.ok(deposes.length > 0, 'aucun fichier dans le dossier du compte')

  const adresse = r.texte.match(/"avatar_url":"([^"]+)"/)?.[1]?.replaceAll('\\/', '/')

  if (adresse) {
    const relue = await requete(pile, { chemin: adresse.replace(pile.origine, ''), jeton })
    assert.equal(relue.statut, 200, `${adresse} → ${relue.statut}`)
    assert.match(relue.entetes['content-type'], /^image\//)
  }

  return `${deposes.length} fichier(s), ${Math.round(image.length / 1024)} Ko`
})

// --- Arrêt, redémarrage ------------------------------------------------------

await verifier('arrêt : plus aucun port ouvert, plus aucun processus noté', async () => {
  const ports = [pile.portHttp, pile.portBase, ...pile.reserve.ports()]

  await pile.arreter()

  for (const port of ports) {
    assert.equal(await portOuvert(port), false, `port ${port} encore ouvert`)
  }

  assert.equal(existsSync(join(racine, 'processus.json')), false)
})

await verifier('un coffre qui ne s’ouvre pas n’en crée pas un autre, et rien ne démarre', async () => {
  const coffre = readFileSync(join(racine, 'coffre.bin'))

  await assert.rejects(
    demarrer({
      desceller: () => {
        throw new Error('autre compte Windows')
      },
      surEtape: () => {},
    }),
    { code: 'COFFRE_ILLISIBLE' },
  )

  assert.deepEqual(readFileSync(join(racine, 'coffre.bin')), coffre)
})

await verifier('redémarrage : mêmes secrets, mêmes données', async () => {
  pile = await demarrer()

  const r = await requete(pile, { methode: 'POST', chemin: '/api/auth/login', json: { email: compte.email, password: compte.password } })
  assert.equal(r.statut, 200, r.texte)

  return pile.origine
})

await verifier('arrêt final', () => pile.arreter())

console.log(echecs === 0 ? '\n  Tout est vérifié.\n' : `\n  ${echecs} vérification(s) en échec — données gardées : ${racine}\n`)

if (echecs === 0 && !process.argv.includes('--garder')) {
  rmSync(racine, { recursive: true, force: true })
}

process.exit(echecs === 0 ? 0 : 1)
