import assert from 'node:assert/strict'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { after, test } from 'node:test'
import { ouvrirCoffre } from '../src/services/coffre.js'

// Un scellement de test, réversible et reconnaissable. Le vrai est DPAPI.
const sceller = (texte) => Buffer.from(`scelle:${Buffer.from(texte).reverse().toString('base64')}`)
const desceller = (octets) => {
  const texte = octets.toString()

  if (!texte.startsWith('scelle:')) {
    throw new Error('pas scellé par ce compte')
  }

  return Buffer.from(texte.slice(7), 'base64').reverse().toString()
}

const dossiers = []

function dossier() {
  const racine = mkdtempSync(join(tmpdir(), 'relais-coffre-'))
  dossiers.push(racine)

  return join(racine, 'coffre.bin')
}

after(() => dossiers.forEach((racine) => rmSync(racine, { recursive: true, force: true })))

test('au premier lancement, trois secrets forts, jamais écrits en clair', () => {
  const fichier = dossier()
  const { secrets, cree } = ouvrirCoffre({ fichier, sceller, desceller })

  assert.equal(cree, true)
  assert.equal(new Set([secrets.appKey, secrets.jwtSecret, secrets.motDePasseBase]).size, 3)

  for (const secret of [secrets.appKey, secrets.jwtSecret, secrets.motDePasseBase]) {
    assert.ok(secret.length >= 32)
    assert.ok(!readFileSync(fichier).toString().includes(secret))
  }
})

test('les lancements suivants retrouvent les mêmes secrets', () => {
  const fichier = dossier()
  const premier = ouvrirCoffre({ fichier, sceller, desceller })
  const second = ouvrirCoffre({ fichier, sceller, desceller })

  assert.equal(second.cree, false)
  assert.deepEqual(second.secrets, premier.secrets)
})

test('un coffre illisible n’est jamais remplacé par un neuf', () => {
  const fichier = dossier()
  ouvrirCoffre({ fichier, sceller, desceller })
  const avant = readFileSync(fichier)

  assert.throws(
    () => ouvrirCoffre({ fichier, sceller, desceller: () => { throw new Error('autre compte') } }),
    { code: 'COFFRE_ILLISIBLE' },
  )
  assert.deepEqual(readFileSync(fichier), avant)

  writeFileSync(fichier, sceller(JSON.stringify({ version: 1, appKey: 'court' })))
  assert.throws(() => ouvrirCoffre({ fichier, sceller, desceller }), { code: 'COFFRE_ILLISIBLE' })
})

test('pas de coffre sans scellement', () => {
  assert.throws(() => ouvrirCoffre({ fichier: dossier(), sceller: null, desceller }))
  assert.throws(() => ouvrirCoffre({ fichier: dossier(), sceller, desceller: undefined }))
})
