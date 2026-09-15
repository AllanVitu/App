// Rend l'icône de Relais dans toutes les tailles dont Windows a besoin.
//
// Deux familles de dessins — une icône réduite n'est pas une icône dessinée
// pour sa taille :
//   64 px et plus   → icone/relais.svg : relief, liseré, grain.
//   16 à 48 px      → redessinées ici, taille par taille, sur la grille des
//                     pixels : aplats, bords entiers, aucune demi-valeur.
//
// Sorties : build/icon.ico (toutes les tailles dans un seul fichier),
// build/icon.png (1024 px), le favicon du client, et build/apercu-icone.png,
// une planche pour relire le rendu à l'échelle 1 et agrandi.
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const ICI = dirname(dirname(fileURLToPath(import.meta.url)))
const DEPOT = dirname(ICI)

const GRAND = readFileSync(join(ICI, 'icone', 'relais.svg'), 'utf8')

const ENCRE = '#141418'
const PAPIER = '#f4f4f6'

/**
 * La géométrie de chaque petite taille, décidée au pixel.
 *
 * La marque se construit sur une grille de 32 : barres de 4, écarts de 3,
 * marges de 7, hauteurs dans le rapport 1/3, 2/3, 1. Réduite telle quelle, elle
 * tombe entre les pixels dès qu'on quitte 32 ; chaque taille arrondit donc à
 * sa manière, en privilégiant une barre un peu plus épaisse — une barre d'un
 * pixel et demi disparaît, une barre de deux se lit.
 *
 * Quand le compte ne tombe pas juste, le pixel en trop va à droite : la barre
 * haute y met déjà le poids de la marque, décaler l'ensemble d'un demi-pixel
 * vers la gauche la recentre à l'œil.
 *
 *   barre, ecart : largeur des barres et des intervalles
 *   gauche       : marge de gauche (la droite en découle)
 *   haut, bas    : marges verticales
 *   hauteurs     : des trois barres, de gauche à droite
 *   rayon        : arrondi de la plaque ; arrondiBarre : celui des barres
 */
const PETITES = {
  16: { barre: 2, ecart: 2, gauche: 3, haut: 3, bas: 3, hauteurs: [4, 7, 10], rayon: 3, arrondiBarre: 0 },
  20: { barre: 3, ecart: 2, gauche: 3, haut: 4, bas: 4, hauteurs: [4, 8, 12], rayon: 4, arrondiBarre: 0 },
  24: { barre: 3, ecart: 2, gauche: 5, haut: 5, bas: 5, hauteurs: [5, 9, 14], rayon: 5, arrondiBarre: 0 },
  32: { barre: 4, ecart: 3, gauche: 7, haut: 7, bas: 7, hauteurs: [6, 12, 18], rayon: 6, arrondiBarre: 1 },
  40: { barre: 5, ecart: 4, gauche: 8, haut: 9, bas: 9, hauteurs: [7, 15, 22], rayon: 8, arrondiBarre: 1 },
  48: { barre: 6, ecart: 4, gauche: 11, haut: 11, bas: 11, hauteurs: [9, 17, 26], rayon: 9, arrondiBarre: 1.5 },
}

/** Le dessin aplat d'une petite taille, en coordonnées de pixels. */
export function aplat(taille) {
  const g = PETITES[taille]
  const pied = taille - g.bas

  const barres = g.hauteurs
    .map((hauteur, rang) => {
      const x = g.gauche + rang * (g.barre + g.ecart)
      const arrondi = g.arrondiBarre > 0 ? ` rx="${g.arrondiBarre}"` : ''

      return `  <rect x="${x}" y="${pied - hauteur}" width="${g.barre}" height="${hauteur}"${arrondi} fill="${PAPIER}" />`
    })
    .join('\n')

  // Les barres sans arrondi sont tracées bord sur pixel ; la plaque, elle,
  // garde l'anticrénelage : un coin arrondi en escalier se voit plus qu'un
  // coin légèrement doux.
  const rendu = g.arrondiBarre === 0 ? ' shape-rendering="crispEdges"' : ''

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${taille} ${taille}">
  <rect width="${taille}" height="${taille}" rx="${g.rayon}" fill="${ENCRE}" />
  <g${rendu}>
${barres}
  </g>
</svg>
`
}

const dessinPour = (taille) => (PETITES[taille] ? aplat(taille) : GRAND)

/** Les tailles d'un .ico Windows : explorateur, barre des tâches, raccourcis, écrans à forte densité. */
const TAILLES_ICO = [16, 20, 24, 32, 40, 48, 64, 256]

/**
 * Un .ico dont chaque image est un PNG : lu par Windows depuis Vista, et sans
 * perte de la transparence que la conversion en BMP abîmerait sur les bords.
 */
export function ico(images) {
  const entete = Buffer.alloc(6)
  entete.writeUInt16LE(0, 0)
  entete.writeUInt16LE(1, 2)
  entete.writeUInt16LE(images.length, 4)

  const repertoire = Buffer.alloc(16 * images.length)
  let decalage = 6 + repertoire.length

  images.forEach(({ taille, png }, rang) => {
    const base = 16 * rang
    // Sur un octet, 256 s'écrit 0 : c'est la convention du format.
    repertoire.writeUInt8(taille >= 256 ? 0 : taille, base)
    repertoire.writeUInt8(taille >= 256 ? 0 : taille, base + 1)
    repertoire.writeUInt8(0, base + 2)
    repertoire.writeUInt8(0, base + 3)
    repertoire.writeUInt16LE(1, base + 4)
    repertoire.writeUInt16LE(32, base + 6)
    repertoire.writeUInt32LE(png.length, base + 8)
    repertoire.writeUInt32LE(decalage, base + 12)
    decalage += png.length
  })

  return Buffer.concat([entete, repertoire, ...images.map(({ png }) => png)])
}

async function principal() {
  // Le Chromium de la suite navigateur fait un excellent moteur de rendu SVG.
  const { chromium } = createRequire(join(DEPOT, 'e2e', 'package.json'))('@playwright/test')
  const navigateur = await chromium.launch()
  const page = await navigateur.newPage()

  async function rendre(taille) {
    await page.setViewportSize({ width: taille, height: taille })
    await page.setContent(
      `<!doctype html><html><head><style>html,body{margin:0;background:transparent}svg{display:block;width:${taille}px;height:${taille}px}</style></head><body>${dessinPour(taille)}</body></html>`,
    )

    return page.screenshot({ omitBackground: true, clip: { x: 0, y: 0, width: taille, height: taille } })
  }

  mkdirSync(join(ICI, 'build'), { recursive: true })

  const images = []

  for (const taille of TAILLES_ICO) {
    images.push({ taille, png: await rendre(taille) })
  }

  writeFileSync(join(ICI, 'build', 'icon.ico'), ico(images))
  writeFileSync(join(ICI, 'build', 'icon.png'), await rendre(1024))

  // Le favicon du client : le dessin de 32, fait pour un onglet comme pour
  // une barre de titre.
  writeFileSync(join(DEPOT, 'front', 'public', 'favicon.svg'), aplat(32))

  // La planche d'aperçu : chaque taille à l'échelle 1, puis agrandie sans
  // lissage, sur fond clair et sur fond sombre — là où une icône se juge.
  const apercus = images.map(({ taille, png }) => ({ taille, png: png.toString('base64') }))

  await page.setViewportSize({ width: 1500, height: 720 })
  await page.setContent(`<!doctype html><html><head><style>
    body{margin:0;font:12px system-ui;display:grid;grid-template-rows:1fr 1fr}
    .bande{display:flex;align-items:flex-end;gap:22px;padding:22px}
    .clair{background:#f3f3f5;color:#333}.sombre{background:#202024;color:#ccc}
    figure{margin:0;display:grid;justify-items:center;gap:6px}
    img.zoom{image-rendering:pixelated}
  </style></head><body>
    ${['clair', 'sombre']
      .map(
        (fond) => `<div class="bande ${fond}">${apercus
          .map(
            ({ taille, png }) => `<figure>
              <img src="data:image/png;base64,${png}" width="${taille}" height="${taille}">
              ${taille <= 64 ? `<img class="zoom" src="data:image/png;base64,${png}" width="${Math.min(taille * 4, 192)}" height="${Math.min(taille * 4, 192)}">` : ''}
              <figcaption>${taille} px</figcaption></figure>`,
          )
          .join('')}</div>`,
      )
      .join('')}
  </body></html>`)
  await page.screenshot({ path: join(ICI, 'build', 'apercu-icone.png') })

  await navigateur.close()

  console.log(`build/icon.ico (${TAILLES_ICO.join(', ')} px), build/icon.png, front/public/favicon.svg, build/apercu-icone.png`)
}

// Importé par les tests (ico, aplat), le module ne lance pas de navigateur.
if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  await principal()
}
