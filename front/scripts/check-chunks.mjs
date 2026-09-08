#!/usr/bin/env node
/**
 * Vérifie le DÉCOUPAGE DES LOTS sur la compilation de production.
 *
 * Pourquoi un script et pas un test de bout en bout : la suite Playwright
 * tourne contre le serveur de développement, où Vite sert les modules un par
 * un, sans lot. L'invariant à protéger n'existe tout simplement pas là-bas —
 * un test qui l'y cherchait passerait ou échouerait pour de mauvaises
 * raisons.
 *
 * L'INVARIANT. Le moteur d'animation est servi en trois morceaux :
 *
 *   anime         le tronc commun, chargé par toutes les pages
 *   anime-layout  la mesure de mise en page (l'équivalent de Flip)
 *   anime-svg     le morphing d'icône et le tracé progressif
 *
 * Les deux derniers ne servent qu'à des écrans identifiés. L'écran de
 * connexion — celui que voit un visiteur pas encore identifié, souvent sur un
 * réseau qu'il n'a pas choisi — ne doit charger que le premier.
 *
 * ET CELA SE CASSE EN SILENCE. Il suffit qu'une règle disparaisse de
 * `manualChunks` — au fil d'une mise à jour de Vite, ou parce qu'un chemin
 * interne d'anime.js a changé de nom — pour que les trois lots redeviennent
 * un seul, chargé par tout le monde : aucune erreur, aucun test rouge, juste
 * 7 Ko de plus sur l'écran de connexion.
 *
 * Le contrôle porte sur les OCTETS RÉELLEMENT SERVIS : on lit les imports
 * statiques écrits dans les fichiers émis, et on en suit la fermeture
 * transitive depuis le point d'entrée. Il a été vérifié en cassant
 * volontairement la règle de découpage — un garde-fou qui ne peut pas échouer
 * ne garde rien.
 *
 *   node scripts/check-chunks.mjs
 */
import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'

const ASSETS = new URL('../dist/assets/', import.meta.url).pathname

/** Lots interdits sur le chemin public, et la raison de l'interdiction. */
const INTERDITS = {
  'anime-layout': "l'écran de connexion n'anime aucune liste",
  'anime-svg': "l'écran de connexion n'a ni bascule de thème ni coche animée",
  gsap: 'GSAP a été retiré du projet',
}

let fichiers

try {
  fichiers = readdirSync(ASSETS).filter((name) => name.endsWith('.js'))
} catch {
  console.error('✗ dist/assets introuvable — lancez « npm run build » d’abord.')
  process.exit(1)
}

/** Nom de lot sans son empreinte : « anime-layout-BesEgJWE.js » -> « anime-layout ». */
const lotDe = (fichier) => fichier.replace(/-[A-Za-z0-9_-]{8}\.js$/, '').replace(/\.js$/, '')

/** Imports statiques écrits dans un fichier émis. */
function importsDe(fichier) {
  const source = readFileSync(join(ASSETS, fichier), 'utf8')

  return [...source.matchAll(/from"\.\/([A-Za-z0-9_.-]+\.js)"/g)].map((match) => match[1])
}

/** Fermeture transitive : tout ce que le navigateur téléchargera pour ce point d'entrée. */
function fermeture(depart) {
  const vus = new Set()
  const aVoir = [...depart]

  while (aVoir.length) {
    const fichier = aVoir.pop()

    if (!fichier || vus.has(fichier)) continue

    vus.add(fichier)
    aVoir.push(...importsDe(fichier))
  }

  return vus
}

// Le chemin public = le lot d'entrée (chargé par toutes les pages) + les deux
// morceaux propres à l'écran de connexion.
const depart = fichiers.filter((f) => /^(index|LoginView|AuthLayout)-/.test(f))

if (depart.length < 3) {
  console.error('✗ point d’entrée introuvable dans dist/assets — le nommage a-t-il changé ?')
  process.exit(1)
}

// Les lots séparés doivent EXISTER. Sans ce contrôle, supprimer une règle de
// « manualChunks » ferait disparaître le lot au lieu de le déplacer : la
// vérification suivante ne trouverait plus rien à reprocher, et passerait —
// alors que le code interdit serait revenu dans le tronc commun.
const emis = new Set(fichiers.map(lotDe))
const disparus = ['anime-layout', 'anime-svg'].filter((lot) => !emis.has(lot))

if (disparus.length) {
  console.error(`✗ lots absents de la compilation : ${disparus.join(', ')}`)
  console.error('  La règle « manualChunks » de vite.config.js ne les sépare plus :')
  console.error('  leur code est donc reparti dans le tronc commun, chargé par toutes les pages.')
  process.exit(1)
}

const charges = fermeture(depart)
const lots = [...charges].map(lotDe)
const fautes = Object.entries(INTERDITS).filter(([lot]) => lots.includes(lot))

if (fautes.length) {
  console.error('✗ l’écran de connexion charge des lots qu’il n’utilise pas :\n')
  for (const [lot, raison] of fautes) console.error(`    ${lot} — ${raison}`)
  console.error('\n  Vérifiez qu’aucun module du tronc commun ne les référence.')
  process.exit(1)
}

// Le pendant : séparer les lots ne sert à rien si le morceau séparé
// n’arrive jamais là où il est nécessaire.
const listes = fichiers.filter((f) => /^(TicketsView|ModuleView)-/.test(f))
const manquants = listes.filter((f) => ![...fermeture([f])].map(lotDe).includes('anime-layout'))

if (manquants.length) {
  console.error(`✗ ces vues animent une liste sans charger anime-layout : ${manquants.join(', ')}`)
  process.exit(1)
}

console.log(
  `✓ chemin public propre — ${charges.size} fichiers, sans ${Object.keys(INTERDITS).join(', ')}`,
)
