import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'

/**
 * Garde-fou de la palette.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  LA COULEUR EST LA SEULE PARTIE DU DESSIN QUI SE MESURE             │
 * │                                                                     │
 * │  Une valeur choisie à l'œil sur un écran bien réglé peut tomber à   │
 * │  2,7:1 — c'est arrivé ici, sur « ink-3 » en thème clair, et         │
 * │  personne ne l'a vu pendant deux refontes. Rien dans la chaîne ne   │
 * │  l'aurait signalé : ni ESLint, ni le compilateur, ni un parcours    │
 * │  navigateur, qui ne sait pas lire un rapport de luminance.          │
 * │                                                                     │
 * │  Ce script le sait. Il lit les jetons du fichier de style, applique │
 * │  les seuils, et refuse la chaîne si l'un d'eux les manque.          │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * TROIS FAMILLES, TROIS EXIGENCES DIFFÉRENTES.
 *
 *   Les ENCRES et la SÉMANTIQUE se lisent en toutes lettres : 4,5:1 sur
 *   chaque fond où elles apparaissent (WCAG AA, texte courant).
 *
 *   Les MARQUES DE GRAPHIQUE sont des aplats, pas du texte : 3:1 suffit
 *   (WCAG AA, composant non textuel). Mais elles doivent en plus se
 *   DISTINGUER l'une de l'autre — y compris pour un œil qui ne sépare pas
 *   le rouge du vert, ce qui concerne près d'un homme sur douze.
 *
 *   Le CHROMA a son propre plancher : une teinte trop délavée vire au gris
 *   en aplat, et deux gris ne se distinguent plus quelle que soit la vision.
 */

const RACINE = dirname(dirname(fileURLToPath(import.meta.url)))
const CSS = resolve(RACINE, 'src/assets/css/main.css')

// --- Couleur : conversions ---------------------------------------------------

const versCanal = (v) => (v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4)

/** #rrggbb → [r, g, b] linéaires, 0..1. */
function lineaire(hex) {
  const n = hex.replace('#', '')
  const large = n.length === 3 ? [...n].map((c) => c + c).join('') : n

  return [0, 2, 4].map((i) => versCanal(parseInt(large.slice(i, i + 2), 16) / 255))
}

/** Luminance relative — la grandeur dont WCAG fait un rapport. */
const luminance = ([r, g, b]) => 0.2126 * r + 0.7152 * g + 0.0722 * b

function contraste(a, b) {
  const [clair, sombre] = [luminance(lineaire(a)), luminance(lineaire(b))].sort((x, y) => y - x)

  return (clair + 0.05) / (sombre + 0.05)
}

/** RGB linéaire → CIELAB (D65). ΔE se mesure là, pas en sRGB. */
function versLab([r, g, b]) {
  const X = 0.4124564 * r + 0.3575761 * g + 0.1804375 * b
  const Y = 0.2126729 * r + 0.7151522 * g + 0.072175 * b
  const Z = 0.0193339 * r + 0.119192 * g + 0.9503041 * b

  const f = (t) => (t > 216 / 24389 ? Math.cbrt(t) : (841 / 108) * t + 4 / 29)
  const [fx, fy, fz] = [f(X / 0.95047), f(Y / 1), f(Z / 1.08883)]

  return [116 * fy - 16, 500 * (fx - fy), 200 * (fy - fz)]
}

const deltaE = (a, b) => Math.hypot(...versLab(a).map((v, i) => v - versLab(b)[i]))

/** Chroma OKLCH — ce qui reste de couleur quand on retire clarté et teinte. */
function chroma(hex) {
  const [r, g, b] = lineaire(hex)

  const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b)
  const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b)
  const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b)

  const A = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s
  const B = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s

  return Math.hypot(A, B)
}

/**
 * Simulation des dichromatismes, matrices de Viénot, Brettel & Mollon (1999),
 * appliquées en RGB LINÉAIRE — les appliquer sur du sRGB donnerait des
 * couleurs plausibles et des écarts faux.
 */
const CVD = {
  protanopie: [
    [0.11238, 0.88762, 0],
    [0.11238, 0.88762, 0],
    [0.00401, -0.00401, 1],
  ],
  deutéranopie: [
    [0.29275, 0.70725, 0],
    [0.29275, 0.70725, 0],
    [-0.02234, 0.02234, 1],
  ],
  tritanopie: [
    [1, 0.14461, -0.14461],
    [0, 1, 0],
    [0, 0.15594, 0.84406],
  ],
}

const simuler = (rgb, matrice) =>
  matrice.map((ligne) => ligne.reduce((s, k, i) => s + k * rgb[i], 0))

// --- Lecture des jetons ------------------------------------------------------

/**
 * Les deux thèmes vivent dans le même fichier : « :root » porte le sombre,
 * « .light » le clair. On les lit tels quels plutôt que d'exiger une
 * quelconque discipline de nommage supplémentaire.
 */
function jetons(css) {
  const bloc = (selecteur) => {
    const debut = css.indexOf(selecteur + ' {')

    if (debut === -1) throw new Error(`bloc « ${selecteur} » introuvable`)

    const corps = css.slice(debut, css.indexOf('\n}', debut))
    const trouves = {}

    for (const [, nom, valeur] of corps.matchAll(/--c-([\w-]+):\s*(#[0-9a-fA-F]{3,8})/g)) {
      trouves[nom] = valeur
    }

    return trouves
  }

  const sombre = bloc(':root')

  // Le clair ne redéfinit que ce qui change : on complète avec le sombre,
  // exactement comme la cascade le fait dans le navigateur.
  return { sombre, clair: { ...sombre, ...bloc('.light') } }
}

// --- Règles ------------------------------------------------------------------

const echecs = []
const lignes = []

function exiger(condition, libelle, mesure, seuil, unite = ':1') {
  const verdict = condition ? '  ok  ' : ' ÉCHEC'
  const texte = `${verdict} ${libelle.padEnd(46)} ${mesure.toFixed(2)}${unite} (≥ ${seuil}${unite})`

  lignes.push(texte)

  if (!condition) echecs.push(texte.trim())
}

const TEXTE = 4.5
const APLAT = 3
const CHROMA_MIN = 0.1
const ECART_NORMAL = 15
const ECART_CVD = 8

for (const [theme, t] of Object.entries(jetons(readFileSync(CSS, 'utf8')))) {
  lignes.push(`\n── ${theme} ───────────────────────────────────────────────────`)

  // Les encres, sur les deux fonds où elles se posent réellement.
  for (const encre of ['ink', 'ink-2', 'ink-3']) {
    for (const fond of ['panel', 'paper', 'raised']) {
      exiger(
        contraste(t[encre], t[fond]) >= TEXTE,
        `${encre} sur ${fond}`,
        contraste(t[encre], t[fond]),
        TEXTE,
      )
    }
  }

  // La sémantique se lit en toutes lettres, sur le panneau ET sur sa propre
  // pastille teintée — c'est là qu'elle est le plus fragile.
  for (const role of ['moss', 'ochre', 'brick']) {
    exiger(
      contraste(t[role], t.panel) >= TEXTE,
      `${role} sur panel`,
      contraste(t[role], t.panel),
      TEXTE,
    )
    exiger(
      contraste(t[role], t[`${role}-bg`]) >= TEXTE,
      `${role} sur ${role}-bg`,
      contraste(t[role], t[`${role}-bg`]),
      TEXTE,
    )
  }

  // Les marques de graphique : aplats, donc 3:1 — mais du chroma, et un
  // écart qui tienne sous les trois dichromatismes.
  for (const marque of ['chart-1', 'chart-2']) {
    exiger(
      contraste(t[marque], t.panel) >= APLAT,
      `${marque} sur panel`,
      contraste(t[marque], t.panel),
      APLAT,
    )
    exiger(
      chroma(t[marque]) >= CHROMA_MIN,
      `${marque} chroma OKLCH`,
      chroma(t[marque]),
      CHROMA_MIN,
      '',
    )
  }

  const [un, deux] = [lineaire(t['chart-1']), lineaire(t['chart-2'])]

  exiger(
    deltaE(un, deux) >= ECART_NORMAL,
    'chart-1 / chart-2 — vision normale',
    deltaE(un, deux),
    ECART_NORMAL,
    ' ΔE',
  )

  for (const [nom, matrice] of Object.entries(CVD)) {
    const ecart = deltaE(simuler(un, matrice), simuler(deux, matrice))

    exiger(ecart >= ECART_CVD, `chart-1 / chart-2 — ${nom}`, ecart, ECART_CVD, ' ΔE')
  }
}

console.log(lignes.join('\n'))

if (echecs.length) {
  console.error(`\n✗ ${echecs.length} règle(s) de palette non tenue(s).`)
  process.exit(1)
}

console.log('\n✓ palette conforme — contraste, chroma et séparation en dichromatisme')
