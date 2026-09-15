import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { aplat, ico } from '../scripts/icones.mjs'

const ICI = dirname(dirname(fileURLToPath(import.meta.url)))
const PNG = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])

function lireIco(octets) {
  assert.equal(octets.readUInt16LE(0), 0)
  assert.equal(octets.readUInt16LE(2), 1)

  return Array.from({ length: octets.readUInt16LE(4) }, (_, rang) => {
    const base = 6 + 16 * rang

    return {
      largeur: octets.readUInt8(base) || 256,
      hauteur: octets.readUInt8(base + 1) || 256,
      taille: octets.readUInt32LE(base + 8),
      decalage: octets.readUInt32LE(base + 12),
    }
  })
}

test('le format .ico : répertoire, décalages, 256 écrit 0', () => {
  const images = [
    { taille: 16, png: Buffer.concat([PNG, Buffer.from('a')]) },
    { taille: 256, png: Buffer.concat([PNG, Buffer.from('bcd')]) },
  ]
  const octets = ico(images)
  const entrees = lireIco(octets)

  assert.deepEqual(
    entrees.map((e) => e.largeur),
    [16, 256],
  )
  assert.equal(octets.readUInt8(6 + 16), 0)

  entrees.forEach((entree, rang) => {
    assert.deepEqual(octets.subarray(entree.decalage, entree.decalage + entree.taille), images[rang].png)
  })
})

test('chaque petite taille tombe sur la grille des pixels', () => {
  for (const taille of [16, 20, 24, 32, 40, 48]) {
    const rects = [...aplat(taille).matchAll(/<rect ([^>]+)\/>/g)].map(([, attributs]) =>
      Object.fromEntries([...attributs.matchAll(/(\w+)="([^"]+)"/g)].map(([, nom, valeur]) => [nom, valeur])),
    )

    const [plaque, ...barres] = rects

    assert.equal(Number(plaque.width), taille)
    assert.equal(Number(plaque.height), taille)
    assert.equal(barres.length, 3)

    for (const barre of barres) {
      for (const cote of ['x', 'y', 'width', 'height']) {
        assert.ok(Number.isInteger(Number(barre[cote])), `${taille} px : ${cote}=${barre[cote]}`)
      }
    }

    const [a, b, c] = barres.map((barre) => ({
      x: Number(barre.x),
      largeur: Number(barre.width),
      hauteur: Number(barre.height),
      pied: Number(barre.y) + Number(barre.height),
    }))

    assert.ok(a.hauteur < b.hauteur && b.hauteur < c.hauteur, `${taille} px : hauteurs croissantes`)
    assert.ok(a.largeur === b.largeur && b.largeur === c.largeur, `${taille} px : largeurs égales`)
    assert.equal(b.x - (a.x + a.largeur), c.x - (b.x + b.largeur), `${taille} px : écarts égaux`)
    assert.ok(a.pied === b.pied && b.pied === c.pied, `${taille} px : pieds alignés`)

    const gauche = a.x
    const droite = taille - (c.x + c.largeur)
    assert.ok(droite - gauche >= 0 && droite - gauche <= 1, `${taille} px : marges ${gauche}/${droite}`)
  }
})

test('build/icon.ico contient toutes les tailles, en PNG', () => {
  const octets = readFileSync(join(ICI, 'build', 'icon.ico'))
  const entrees = lireIco(octets)

  assert.deepEqual(
    entrees.map((e) => e.largeur),
    [16, 20, 24, 32, 40, 48, 64, 256],
  )

  for (const { decalage, largeur, hauteur } of entrees) {
    assert.deepEqual(octets.subarray(decalage, decalage + 8), PNG)
    assert.equal(largeur, hauteur)
    // Dimensions de l'image elle-même, dans l'en-tête IHDR du PNG.
    assert.equal(octets.readUInt32BE(decalage + 16), largeur)
  }
})
