import assert from 'node:assert/strict'
import net from 'node:net'
import { test } from 'node:test'
import {
  analyserEntetes,
  decoderPaires,
  enregistrement,
  flux,
  Lecteur,
  paires,
  ReponseCgi,
  requeteFastCgi,
  TYPES,
} from '../src/services/fastcgi.js'

test('un enregistrement est bourré jusqu’au multiple de 8 suivant', () => {
  for (const taille of [0, 1, 7, 8, 9, 65_535]) {
    const e = enregistrement(TYPES.SORTIE, 3, Buffer.alloc(taille, 0x61))

    assert.equal(e.length % 8, 0)
    assert.equal(e.readUInt8(0), 1)
    assert.equal(e.readUInt8(1), TYPES.SORTIE)
    assert.equal(e.readUInt16BE(2), 3)
    assert.equal(e.readUInt16BE(4), taille)
    assert.equal(e.readUInt8(6), e.length - 8 - taille)
  }

  assert.throws(() => enregistrement(TYPES.SORTIE, 1, Buffer.alloc(65_536)), RangeError)
})

test('un flux long est découpé, puis clos par un enregistrement vide', () => {
  const recus = new Lecteur().pousser(flux(TYPES.ENTREE, 1, Buffer.alloc(70_000, 1)))

  assert.deepEqual(
    recus.map((r) => r.contenu.length),
    [65_535, 4_465, 0],
  )
})

test('les paires nom-valeur survivent à l’aller-retour, longueurs sur 4 octets comprises', () => {
  const parametres = {
    REQUEST_METHOD: 'POST',
    HTTP_X_LONG: 'é'.repeat(300),
    VIDE: '',
    ['N'.repeat(200)]: 'v',
  }

  assert.deepEqual(decoderPaires(paires(parametres)), parametres)
  assert.deepEqual(decoderPaires(paires({ A: undefined, B: null, C: 1 })), { C: '1' })
})

test('le lecteur réassemble un flux livré octet par octet', () => {
  const brut = Buffer.concat([
    enregistrement(TYPES.SORTIE, 1, Buffer.from('Status: 201\r\n\r\nok')),
    enregistrement(TYPES.FIN, 1, Buffer.alloc(8)),
  ])

  const lecteur = new Lecteur()
  const recus = []

  for (const octet of brut) {
    recus.push(...lecteur.pousser(Buffer.from([octet])))
  }

  assert.deepEqual(
    recus.map((r) => r.type),
    [TYPES.SORTIE, TYPES.FIN],
  )
  assert.equal(recus[0].contenu.toString(), 'Status: 201\r\n\r\nok')
})

test('une version de protocole inconnue est refusée', () => {
  const e = enregistrement(TYPES.SORTIE, 1)
  e.writeUInt8(2, 0)

  assert.throws(() => new Lecteur().pousser(e))
})

test('en-têtes CGI : statut, redirection implicite, noms invalides écartés', () => {
  assert.deepEqual(analyserEntetes('Status: 404 Not Found\r\nContent-Type: application/json'), {
    statut: 404,
    entetes: [['Content-Type', 'application/json']],
  })

  assert.equal(analyserEntetes('Location: /ailleurs').statut, 302)
  assert.equal(analyserEntetes('Status: 201 Created\r\nLocation: /x').statut, 201)
  assert.equal(analyserEntetes('Status: 999').statut, 200)
  assert.deepEqual(analyserEntetes('Mauvais Nom: x\r\nX-Ok: 1\r\nsans-deux-points').entetes, [['X-Ok', '1']])
})

test('la séparation des en-têtes et du corps ne dépend pas du découpage', () => {
  const brut = Buffer.from('Status: 200\r\nSet-Cookie: a=1\r\nSet-Cookie: b=2\r\n\r\ncorps\r\n\r\nsuite')

  for (let coupe = 1; coupe < brut.length; coupe += 1) {
    let entetes = null
    const corps = []
    const reponse = new ReponseCgi({ surEntetes: (e) => (entetes = e), surCorps: (m) => corps.push(m) })

    reponse.pousser(brut.subarray(0, coupe))
    reponse.pousser(brut.subarray(coupe))

    assert.equal(entetes.entetes.length, 2, `coupe à ${coupe}`)
    assert.equal(Buffer.concat(corps).toString(), 'corps\r\n\r\nsuite', `coupe à ${coupe}`)
  }
})

test('une requête complète contre un faux php-cgi', async () => {
  let recu = null

  const serveur = net.createServer((socket) => {
    const lecteur = new Lecteur()
    const parametres = []
    const entree = []

    socket.on('data', (morceau) => {
      for (const { type, contenu } of lecteur.pousser(morceau)) {
        if (type === TYPES.PARAMETRES) {
          parametres.push(contenu)
        } else if (type === TYPES.ENTREE && contenu.length > 0) {
          entree.push(contenu)
        } else if (type === TYPES.ENTREE) {
          recu = { parametres: decoderPaires(Buffer.concat(parametres)), corps: Buffer.concat(entree).toString() }

          const reponse = Buffer.from('Status: 201 Created\r\nContent-Type: text/plain\r\n\r\n' + 'x'.repeat(100_000))
          const brut = Buffer.concat([
            flux(TYPES.SORTIE, 1, reponse),
            enregistrement(TYPES.ERREUR, 1, Buffer.from('avertissement')),
            enregistrement(TYPES.FIN, 1, Buffer.alloc(8)),
          ])

          // Livrée en morceaux dont les bords ne tombent jamais sur ceux des enregistrements.
          for (let i = 0; i < brut.length; i += 7_919) {
            socket.write(brut.subarray(i, i + 7_919))
          }

          socket.end()
        }
      }
    })
  })

  await new Promise((resoudre) => serveur.listen(0, '127.0.0.1', resoudre))

  let statut = 0
  const corps = []
  const erreurs = []

  await requeteFastCgi({
    port: serveur.address().port,
    parametres: { REQUEST_METHOD: 'POST', CONTENT_LENGTH: '7' },
    corps: Buffer.from('bonjour'),
    surEntetes: (e) => (statut = e.statut),
    surCorps: (m) => corps.push(m),
    surErreurPhp: (m) => erreurs.push(m),
  }).promesse

  serveur.close()

  assert.equal(statut, 201)
  assert.equal(Buffer.concat(corps).length, 100_000)
  assert.deepEqual(erreurs, ['avertissement'])
  assert.equal(recu.parametres.REQUEST_METHOD, 'POST')
  assert.equal(recu.corps, 'bonjour')
})

test('un php-cgi muet est abandonné au bout du délai', async () => {
  const serveur = net.createServer(() => {})
  await new Promise((resoudre) => serveur.listen(0, '127.0.0.1', resoudre))

  await assert.rejects(
    requeteFastCgi({ port: serveur.address().port, parametres: {}, delai: 200, surEntetes() {}, surCorps() {} }).promesse,
    { code: 'DELAI' },
  )

  serveur.close()
})

test('un port fermé est signalé comme tel : la requête peut partir ailleurs', async () => {
  const serveur = net.createServer()
  await new Promise((resoudre) => serveur.listen(0, '127.0.0.1', resoudre))
  const { port } = serveur.address()
  await new Promise((resoudre) => serveur.close(resoudre))

  await assert.rejects(requeteFastCgi({ port, parametres: {}, surEntetes() {}, surCorps() {} }).promesse, { code: 'ECONNREFUSED' })
})
