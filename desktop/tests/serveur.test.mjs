import assert from 'node:assert/strict'
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import http from 'node:http'
import net from 'node:net'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { after, before, test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { portLibre } from '../src/services/ports.js'
import {
  cheminStatique,
  COOKIE_POSTE,
  CORPS_MAX,
  creerServeur,
  ENTETES_SECURITE,
  lireCookie,
  retirerCookie,
} from '../src/services/serveur.js'

const DEPOT = dirname(dirname(dirname(fileURLToPath(import.meta.url))))
const JETON = 'jeton-du-poste-de-test-0123456789abcdef'

let serveur
let port
let racine
let front
const appels = []

before(async () => {
  racine = mkdtempSync(join(tmpdir(), 'relais-serveur-'))
  front = join(racine, 'front')

  mkdirSync(join(front, 'assets'), { recursive: true })
  writeFileSync(join(front, 'index.html'), '<!doctype html><div id="app"></div>')
  writeFileSync(join(front, 'assets', 'app-1a2b.js'), 'console.log(1)')
  writeFileSync(join(front, '.env'), 'SECRET_FICHIER_CACHE')
  writeFileSync(join(racine, 'secret.txt'), 'SECRET_HORS_RACINE')

  port = await portLibre()

  const reserve = {
    traiter: async ({ parametres, corps, surEntetes, surCorps }) => {
      appels.push({ parametres, corps: corps.toString() })
      surEntetes({
        statut: 201,
        entetes: [
          ['Content-Type', 'application/json'],
          ['Set-Cookie', 'a=1; Path=/api/auth'],
          ['Set-Cookie', 'b=2'],
          ['X-Powered-By', 'PHP/8.3'],
          ['Cache-Control', 'no-store'],
        ],
      })
      surCorps(Buffer.from('{"ok":true}'))
    },
  }

  serveur = creerServeur({
    port,
    racineFront: front,
    pointEntree: join(racine, 'api', 'public', 'index.php'),
    jetonPoste: JETON,
    jetonPhp: 'jeton-php',
    reserve,
    journal: { info() {}, alerte() {}, erreur() {} },
  })

  await serveur.ecouter()
})

after(async () => {
  await serveur.fermer()
  rmSync(racine, { recursive: true, force: true })
})

function requete({ methode = 'GET', chemin = '/', entetes = {}, corps, cookie = `${COOKIE_POSTE}=${JETON}` } = {}) {
  return new Promise((resoudre, rejeter) => {
    const req = http.request(
      { host: '127.0.0.1', port, method: methode, path: chemin, headers: { ...(cookie ? { Cookie: cookie } : {}), ...entetes } },
      (res) => {
        const morceaux = []
        res.on('data', (m) => morceaux.push(m))
        res.on('end', () => resoudre({ statut: res.statusCode, entetes: res.headers, texte: Buffer.concat(morceaux).toString() }))
      },
    )

    req.on('error', rejeter)

    if (corps) {
      req.write(corps)
    }

    req.end()
  })
}

test('les en-têtes de sécurité sont ceux de nginx, HSTS excepté', async () => {
  const conf = readFileSync(join(DEPOT, 'docker', 'nginx', 'security-headers.conf'), 'utf8')
  const nginx = Object.fromEntries([...conf.matchAll(/^add_header\s+(\S+)\s+"([^"]*)"/gm)].map(([, nom, valeur]) => [nom, valeur]))

  assert.ok(nginx['Strict-Transport-Security'], 'la configuration nginx a changé : relire ce test')
  delete nginx['Strict-Transport-Security']

  assert.deepEqual({ ...ENTETES_SECURITE }, nginx)

  for (const chemin of ['/', '/api/tickets', '/assets/absent.js']) {
    const { entetes } = await requete({ chemin })

    for (const [nom, valeur] of Object.entries(nginx)) {
      assert.equal(entetes[nom.toLowerCase()], valeur, `${nom} sur ${chemin}`)
    }
  }
})

test('sans le cookie du poste, rien ne répond — pas même un refus sans en-têtes', async () => {
  appels.length = 0

  for (const cookie of [null, `${COOKIE_POSTE}=`, `${COOKIE_POSTE}=${JETON.replace(/.$/, 'X')}`, `autre=${JETON}`]) {
    for (const chemin of ['/', '/api/auth/me']) {
      const reponse = await requete({ chemin, cookie })

      assert.equal(reponse.statut, 403, `${cookie} sur ${chemin}`)
      assert.equal(reponse.entetes['x-frame-options'], 'DENY')
    }
  }

  assert.equal(appels.length, 0)
})

test('un autre nom d’hôte est refusé : pas de rattachement par DNS', async () => {
  for (const hote of [`localhost:${port}`, `relais.example:${port}`, '127.0.0.1', `127.0.0.1:${port}.evil.example`]) {
    assert.equal((await requete({ entetes: { Host: hote } })).statut, 421, hote)
  }
})

test('le client compilé, son cache, et le repli sur index.html', async () => {
  const accueil = await requete()
  assert.equal(accueil.statut, 200)
  assert.match(accueil.texte, /id="app"/)
  assert.equal(accueil.entetes['cache-control'], 'no-cache')
  assert.match(accueil.entetes['content-type'], /^text\/html/)

  const route = await requete({ chemin: '/espaces/relais/tickets/42' })
  assert.match(route.texte, /id="app"/)

  const script = await requete({ chemin: '/assets/app-1a2b.js' })
  assert.equal(script.entetes['cache-control'], 'public, max-age=31536000, immutable')
  assert.match(script.entetes['content-type'], /^text\/javascript/)

  assert.equal((await requete({ chemin: '/assets/absent.js' })).statut, 404)
  assert.equal((await requete({ methode: 'DELETE' })).statut, 405)
})

test('rien ne sort du dossier du client, quelle que soit l’écriture du chemin', async () => {
  const tentatives = [
    '/..%2fsecret.txt',
    '/%2e%2e/secret.txt',
    '/%2e%2e%2fsecret.txt',
    '/assets/..%2f..%2fsecret.txt',
    '/assets/%2e%2e/%2e%2e/secret.txt',
    '/..%5csecret.txt',
    '/assets/..%5c..%5csecret.txt',
    '/.env',
    '/assets/../.env',
    '/%2eenv',
    '/index.html%3a%3a$DATA',
    '/C:%5cWindows%5cwin.ini',
    '/%00index.html',
    '/%c0%ae%c0%ae/secret.txt',
  ]

  for (const chemin of tentatives) {
    const { statut, texte } = await requete({ chemin })

    assert.doesNotMatch(texte, /SECRET_/, `${chemin} → ${statut}`)
  }
})

test('cheminStatique : la règle, sans le réseau', () => {
  assert.equal(cheminStatique(front, '/assets/app-1a2b.js'), join(front, 'assets', 'app-1a2b.js'))
  assert.equal(cheminStatique(front, '/'), front)

  for (const refuse of ['/../secret.txt', '/a/%2e%2e/%2e%2e/x', '/.git/config', '/x%5cy', '/a:b', '/%E0%A4%A', '/x%00']) {
    assert.equal(cheminStatique(front, refuse), null, refuse)
  }
})

test('l’API reçoit une requête FastCGI propre, et sa réponse passe intacte', async () => {
  appels.length = 0

  const corps = JSON.stringify({ titre: 'Écran figé' })
  const reponse = await requete({
    methode: 'POST',
    chemin: '/api/tickets?page=2&q=a%20b',
    cookie: `autre=1; ${COOKIE_POSTE}=${JETON}; relais_refresh=xyz`,
    entetes: {
      'Content-Type': 'application/json',
      Proxy: 'http://proxy.evil.example',
      'X-Forwarded_For': '203.0.113.9',
      'X-Demo': 'oui',
    },
    corps,
  })

  assert.equal(appels.length, 1)

  const { parametres, corps: recu } = appels[0]

  assert.equal(recu, corps)
  assert.equal(parametres.REQUEST_METHOD, 'POST')
  assert.equal(parametres.REQUEST_URI, '/api/tickets?page=2&q=a%20b')
  assert.equal(parametres.QUERY_STRING, 'page=2&q=a%20b')
  assert.equal(parametres.CONTENT_LENGTH, String(Buffer.byteLength(corps)))
  assert.equal(parametres.CONTENT_TYPE, 'application/json')
  assert.equal(parametres.SCRIPT_FILENAME, join(racine, 'api', 'public', 'index.php'))
  assert.equal(parametres.RELAIS_JETON, 'jeton-php')
  assert.equal(parametres.REMOTE_ADDR, '127.0.0.1')
  assert.equal(parametres.HTTP_HOST, `127.0.0.1:${port}`)
  assert.equal(parametres.HTTP_X_DEMO, 'oui')

  // Le cookie du poste reste entre le serveur et la fenêtre.
  assert.equal(parametres.HTTP_COOKIE, 'autre=1; relais_refresh=xyz')
  assert.equal(parametres.HTTP_PROXY, undefined)
  assert.ok(!Object.keys(parametres).some((nom) => nom.includes('FORWARDED')))

  assert.equal(reponse.statut, 201)
  assert.deepEqual(reponse.entetes['set-cookie'], ['a=1; Path=/api/auth', 'b=2'])
  assert.equal(reponse.entetes['x-powered-by'], undefined)
  assert.equal(reponse.entetes['cache-control'], 'no-store')
  assert.equal(reponse.texte, '{"ok":true}')
})

test('le script exécuté ne suit jamais l’URL', async () => {
  appels.length = 0

  await requete({ chemin: '/api/..%2f..%2fevil.php' })
  await requete({ chemin: '/api/x/../../../evil.php' })

  for (const { parametres } of appels) {
    assert.equal(parametres.SCRIPT_FILENAME, join(racine, 'api', 'public', 'index.php'))
  }
})

test('« /api » sans barre finale est une route du client, comme sous nginx', async () => {
  appels.length = 0

  assert.match((await requete({ chemin: '/api' })).texte, /id="app"/)
  assert.equal(appels.length, 0)
})

test('un corps de plus de 20 Mo est refusé sur son annonce, sans être lu', async () => {
  const reponse = await new Promise((resoudre, rejeter) => {
    const socket = net.connect({ port, host: '127.0.0.1' })
    let recu = ''

    socket.on('connect', () =>
      socket.write(
        `POST /api/fichiers HTTP/1.1\r\nHost: 127.0.0.1:${port}\r\nCookie: ${COOKIE_POSTE}=${JETON}\r\n` +
          `Content-Type: application/octet-stream\r\nContent-Length: ${CORPS_MAX + 1}\r\n\r\ndebut`,
      ),
    )
    socket.on('data', (m) => {
      recu += m.toString()

      if (recu.includes('\r\n\r\n')) {
        socket.destroy()
        resoudre(recu)
      }
    })
    socket.on('error', rejeter)
  })

  assert.match(reponse, /^HTTP\/1\.1 413 /)
})

test('lecture et retrait de cookies', () => {
  assert.equal(lireCookie(`a=1; ${COOKIE_POSTE}=v=w; b=2`, COOKIE_POSTE), 'v=w')
  assert.equal(lireCookie('a=1', COOKIE_POSTE), null)
  assert.equal(lireCookie(undefined, COOKIE_POSTE), null)
  assert.equal(retirerCookie(`${COOKIE_POSTE}=x;a=1 ;  b=2`, COOKIE_POSTE), 'a=1; b=2')
  assert.equal(retirerCookie(`${COOKIE_POSTE}=x`, COOKIE_POSTE), '')
})
