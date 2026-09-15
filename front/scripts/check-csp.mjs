#!/usr/bin/env node
/**
 * Vérifie que la compilation de production tient dans sa politique de
 * sécurité du contenu (docker/nginx/security-headers.conf).
 *
 * LA POLITIQUE. « script-src 'self' ; style-src 'self' ; font-src 'self' » :
 * ni script ni style écrit dans la page, et aucune ressource tierce. C'est ce
 * qui rend une injection HTML inoffensive — le navigateur refuse d'exécuter ce
 * qu'elle aurait glissé — et ce qui garde l'adresse IP des visiteurs chez
 * nous : une police chargée depuis Google transmet cette adresse à Google,
 * sans consentement, ce qu'un tribunal allemand a jugé contraire au RGPD
 * (LG München, 20 janvier 2022).
 *
 * ET CELA SE CASSE EN SILENCE. Un script de trois lignes ajouté à index.html,
 * une police « juste pour essayer » : rien ne rougit en développement, où Vite
 * ne pose aucune CSP. En déploiement, le navigateur bloque, et l'écran se
 * dégrade sans une ligne dans les journaux du serveur. Ce contrôle échoue
 * AVANT.
 *
 * Il lit les octets émis — dist/index.html, les feuilles de style et les lots
 * JavaScript — et non les sources. Il a été vérifié en réintroduisant chacune
 * des fautes qu'il cherche.
 *
 *   node scripts/check-csp.mjs
 */
import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'

const DIST = fileURLToPath(new URL('../dist/', import.meta.url))

let html

try {
  html = readFileSync(join(DIST, 'index.html'), 'utf8')
} catch {
  console.error('✗ dist/index.html introuvable — lancez « npm run build » d’abord.')
  process.exit(1)
}

const fautes = []

/** Les commentaires HTML ne sont pas servis comme du code : on les écarte. */
const page = html.replace(/<!--[\s\S]*?-->/g, '')

for (const [, attributs, contenu] of page.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)) {
  if (!/\bsrc\s*=/.test(attributs) || contenu.trim() !== '') {
    fautes.push("index.html : script écrit dans la page (bloqué par « script-src 'self' »)")
  }
}

if (/<style\b/i.test(page)) {
  fautes.push("index.html : balise <style> (bloquée par « style-src 'self' »)")
}

if (/\sstyle\s*=/i.test(page)) {
  fautes.push("index.html : attribut style (bloqué par « style-src 'self' »)")
}

if (/\son[a-z]+\s*=/i.test(page)) {
  fautes.push(
    "index.html : gestionnaire d’événement en attribut (bloqué par « script-src 'self' »)",
  )
}

for (const [, , adresse] of page.matchAll(
  /\b(?:src|href)\s*=\s*["'](https?:)?\/\/([^"']+)["']/gi,
)) {
  fautes.push(`index.html : ressource tierce « //${adresse} »`)
}

/** Une feuille de style ou un lot qui irait chercher une ressource ailleurs. */
const TIERS = /url\(\s*["']?(?:https?:)?\/\/|fonts\.(?:googleapis|gstatic)\.com/

/**
 * Un fragment HTML porteur d'un attribut style, dans un lot. Vue insère les
 * grands sous-arbres STATIQUES d'un gabarit par innerHTML, et le navigateur
 * applique alors « style-src » à l'attribut ; le même style posé par le CSSOM
 * (« :style », anime.js) n'est pas concerné. La faute dépend donc du nombre de
 * nœuds voisins — aucune lecture du gabarit ne la laisse deviner, seule la
 * compilation la montre. Rencontrée : la grille de fond d'AuthLayout.
 */
const FRAGMENT_STYLE = /<[a-z][a-z0-9-]*\s[^<>]*\bstyle=/i

const assets = join(DIST, 'assets')

for (const fichier of readdirSync(assets)) {
  if (!fichier.endsWith('.css') && !fichier.endsWith('.js')) continue

  const source = readFileSync(join(assets, fichier), 'utf8')
  const trouve = source.match(TIERS)

  if (trouve) {
    fautes.push(`assets/${fichier} : ressource tierce « ${trouve[0]} »`)
  }

  if (fichier.endsWith('.js') && FRAGMENT_STYLE.test(source)) {
    fautes.push(
      `assets/${fichier} : attribut style inséré par innerHTML (bloqué par « style-src 'self' »)`,
    )
  }
}

if (fautes.length) {
  for (const faute of fautes) console.error(`✗ ${faute}`)
  process.exit(1)
}

console.log('✓ La compilation tient dans la politique de sécurité du contenu.')
