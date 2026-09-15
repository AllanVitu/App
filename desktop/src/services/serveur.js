// Le serveur web du poste : ce que fait nginx dans l'image Docker.
//
// Une seule origine, http://127.0.0.1:<port>, sert :
//   /api/*    → l'API, par la réserve php-cgi (docker/nginx/prod.conf, location /api/)
//   /assets/* → les fichiers compilés, en cache permanent
//   le reste  → le client Vue, avec repli sur index.html
//
// Et deux verrous que nginx n'a pas à poser, parce qu'il n'écoute pas sur un
// poste partagé :
//   - l'en-tête Host doit être exactement 127.0.0.1:<port>. Une page web qui
//     ferait pointer son propre nom de domaine sur 127.0.0.1 (« DNS
//     rebinding ») ne peut pas se faire passer pour l'origine locale ;
//   - chaque requête porte le cookie du poste, tiré au lancement et remis à
//     la seule fenêtre de Relais. Un navigateur ouvert à côté, un autre
//     programme ou un autre compte Windows qui atteindrait le port reçoit 403.
import { timingSafeEqual } from 'node:crypto'
import { createReadStream, statSync } from 'node:fs'
import http from 'node:http'
import { dirname, extname, join, normalize, sep } from 'node:path'

/**
 * docker/nginx/security-headers.conf, à l'identique — un test compare les
 * deux. Sauf HSTS : sur une adresse de bouclage en HTTP, un navigateur
 * l'ignore, et le déclarer ferait croire à une protection qui n'existe pas.
 */
export const ENTETES_SECURITE = Object.freeze({
  'X-Content-Type-Options': 'nosniff',
  'X-Frame-Options': 'DENY',
  'Referrer-Policy': 'strict-origin-when-cross-origin',
  'Content-Security-Policy':
    "default-src 'self'; script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self' data:; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
  'Permissions-Policy': 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
  'Cross-Origin-Opener-Policy': 'same-origin',
  'Cross-Origin-Resource-Policy': 'same-origin',
})

export const COOKIE_POSTE = 'relais_poste'

/** client_max_body_size 20M, comme nginx. */
export const CORPS_MAX = 20 * 1024 * 1024

const TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.map': 'application/json; charset=utf-8',
  '.webmanifest': 'application/manifest+json',
  '.txt': 'text/plain; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.webp': 'image/webp',
  '.avif': 'image/avif',
  '.ico': 'image/x-icon',
  '.woff2': 'font/woff2',
  '.woff': 'font/woff',
  '.ttf': 'font/ttf',
}

function egal(a, b) {
  const x = Buffer.from(String(a))
  const y = Buffer.from(String(b))

  return x.length === y.length && timingSafeEqual(x, y)
}

export function lireCookie(entete, nom) {
  for (const morceau of String(entete ?? '').split(';')) {
    const egale = morceau.indexOf('=')

    if (egale > 0 && morceau.slice(0, egale).trim() === nom) {
      return morceau.slice(egale + 1).trim()
    }
  }

  return null
}

/** L'en-tête Cookie sans le cookie du poste : l'API n'a pas à le connaître. */
export function retirerCookie(entete, nom) {
  return String(entete ?? '')
    .split(';')
    .filter((morceau) => morceau.split('=')[0].trim() !== nom)
    .map((morceau) => morceau.trim())
    .filter(Boolean)
    .join('; ')
}

/**
 * Le fichier du client que désigne un chemin d'URL, ou null.
 *
 * Refusés : tout ce qui remonterait hors du dossier (« .. », encodé ou non),
 * les fichiers et dossiers cachés, les barres obliques inverses, et les
 * deux-points — sous Windows, « index.html::$DATA » lit un flux de données
 * alternatif, et « C: » change de lecteur.
 */
export function cheminStatique(racine, cheminUrl) {
  let decode

  try {
    decode = decodeURIComponent(cheminUrl)
  } catch {
    return null
  }

  if (/[\0\\:]/.test(decode)) {
    return null
  }

  const segments = decode.split('/').filter((segment) => segment !== '')

  if (segments.some((segment) => segment === '..' || segment.startsWith('.'))) {
    return null
  }

  const absolu = join(racine, ...segments)

  return absolu === racine || absolu.startsWith(racine + sep) ? absolu : null
}

function statOuRien(chemin) {
  try {
    return statSync(chemin)
  } catch {
    return null
  }
}

function repondre(res, statut, texte) {
  if (res.headersSent) {
    res.destroy()

    return
  }

  res.statusCode = statut
  res.setHeader('Content-Type', 'text/plain; charset=utf-8')
  res.setHeader('Cache-Control', 'no-store')
  res.end(texte)
}

function lireCorps(req) {
  const annonce = Number(req.headers['content-length'] ?? 0)

  if (annonce > CORPS_MAX) {
    return Promise.reject(Object.assign(new Error('Corps trop volumineux.'), { statut: 413 }))
  }

  return new Promise((resoudre, rejeter) => {
    const morceaux = []
    let taille = 0

    req.on('data', (morceau) => {
      taille += morceau.length

      if (taille > CORPS_MAX) {
        req.removeAllListeners('data')
        rejeter(Object.assign(new Error('Corps trop volumineux.'), { statut: 413 }))

        return
      }

      morceaux.push(morceau)
    })
    req.on('end', () => resoudre(Buffer.concat(morceaux)))
    req.on('error', rejeter)
  })
}

/** Les paramètres FastCGI d'une requête : ceux de fastcgi_params, plus la garde. */
export function parametresFastCgi({ req, port, pointEntree, jetonPhp, longueurCorps }) {
  const requete = req.url
  const interrogation = requete.indexOf('?')
  const adresse = String(req.socket.remoteAddress ?? '').replace(/^::ffff:/, '')

  const parametres = {
    GATEWAY_INTERFACE: 'CGI/1.1',
    SERVER_SOFTWARE: 'Relais',
    SERVER_PROTOCOL: `HTTP/${req.httpVersion}`,
    SERVER_NAME: '127.0.0.1',
    SERVER_ADDR: '127.0.0.1',
    SERVER_PORT: String(port),
    REMOTE_ADDR: adresse,
    REMOTE_PORT: String(req.socket.remotePort ?? ''),
    REQUEST_SCHEME: 'http',
    REQUEST_METHOD: req.method,
    REQUEST_URI: requete,
    QUERY_STRING: interrogation === -1 ? '' : requete.slice(interrogation + 1),
    SCRIPT_FILENAME: pointEntree,
    SCRIPT_NAME: '/index.php',
    DOCUMENT_URI: '/index.php',
    DOCUMENT_ROOT: dirname(pointEntree),
    CONTENT_TYPE: req.headers['content-type'] ?? '',
    CONTENT_LENGTH: longueurCorps > 0 || req.headers['content-length'] !== undefined ? String(longueurCorps) : '',
    RELAIS_JETON: jetonPhp,
  }

  for (const [nom, valeur] of Object.entries(req.headers)) {
    // « Proxy » : la faille httpoxy (HTTP_PROXY lu comme un proxy sortant).
    // Un tiret bas : nginx ignore ces en-têtes, qui se confondraient une fois
    // convertis (X-Forwarded_For et X-Forwarded-For donnent le même nom).
    if (nom === 'proxy' || nom.includes('_') || nom === 'content-type' || nom === 'content-length') {
      continue
    }

    let texte = Array.isArray(valeur) ? valeur.join(', ') : String(valeur)

    if (nom === 'cookie') {
      texte = retirerCookie(texte, COOKIE_POSTE)

      if (texte === '') {
        continue
      }
    }

    parametres[`HTTP_${nom.toUpperCase().replaceAll('-', '_')}`] = texte
  }

  return parametres
}

export function creerServeur({ port, racineFront, pointEntree, jetonPoste, jetonPhp, reserve, journal }) {
  const hote = `127.0.0.1:${port}`
  const racine = normalize(racineFront)
  const index = join(racine, 'index.html')
  const securite = new Set(Object.keys(ENTETES_SECURITE).map((nom) => nom.toLowerCase()))

  async function api(req, res) {
    const corps = await lireCorps(req)

    await reserve.traiter({
      parametres: parametresFastCgi({ req, port, pointEntree, jetonPhp, longueurCorps: corps.length }),
      corps,
      surEntetes: ({ statut, entetes }) => {
        res.statusCode = statut

        for (const [nom, valeur] of entetes) {
          const minuscule = nom.toLowerCase()

          if (minuscule === 'x-powered-by') {
            continue
          }

          // Un en-tête de sécurité posé par l'API remplace celui du serveur ;
          // tout autre s'ajoute (plusieurs Set-Cookie, par exemple).
          if (securite.has(minuscule)) {
            res.setHeader(nom, valeur)
          } else {
            res.appendHeader(nom, valeur)
          }
        }
      },
      surCorps: (morceau) => res.write(morceau),
    })

    res.end()
  }

  function statique(req, res, chemin) {
    if (req.method !== 'GET' && req.method !== 'HEAD') {
      res.setHeader('Allow', 'GET, HEAD')

      return repondre(res, 405, 'Méthode non autorisée.')
    }

    const demande = cheminStatique(racine, chemin)

    if (demande === null) {
      return repondre(res, 404, 'Introuvable.')
    }

    let fichier = demande
    let infos = statOuRien(fichier)

    if (infos?.isDirectory()) {
      fichier = join(fichier, 'index.html')
      infos = statOuRien(fichier)
    }

    if (!infos?.isFile()) {
      // Un fichier compilé absent est une vraie 404 ; toute autre adresse est
      // une route du client, que Vue résout depuis index.html.
      if (chemin.startsWith('/assets/')) {
        return repondre(res, 404, 'Introuvable.')
      }

      fichier = index
      infos = statOuRien(fichier)

      if (!infos?.isFile()) {
        return repondre(res, 404, 'Introuvable.')
      }
    }

    const empreinte = chemin.startsWith('/assets/') && fichier !== index

    res.statusCode = 200
    res.setHeader('Content-Type', TYPES[extname(fichier).toLowerCase()] ?? 'application/octet-stream')
    res.setHeader('Content-Length', infos.size)
    res.setHeader('Cache-Control', empreinte ? 'public, max-age=31536000, immutable' : 'no-cache')
    res.setHeader('Last-Modified', infos.mtime.toUTCString())

    if (req.method === 'HEAD') {
      res.end()

      return
    }

    createReadStream(fichier)
      .on('error', () => res.destroy())
      .pipe(res)
  }

  const serveur = http.createServer((req, res) => {
    for (const [nom, valeur] of Object.entries(ENTETES_SECURITE)) {
      res.setHeader(nom, valeur)
    }

    if (req.headers.host !== hote) {
      return repondre(res, 421, 'Hôte non reconnu.')
    }

    const cookie = lireCookie(req.headers.cookie, COOKIE_POSTE)

    if (cookie === null || !egal(cookie, jetonPoste)) {
      return repondre(res, 403, 'Ce serveur ne répond qu’à la fenêtre de Relais.')
    }

    if (!req.url.startsWith('/') || req.url.startsWith('//')) {
      return repondre(res, 400, 'Requête invalide.')
    }

    let chemin

    try {
      chemin = new URL(req.url, `http://${hote}`).pathname
    } catch {
      return repondre(res, 400, 'Requête invalide.')
    }

    if (chemin.startsWith('/api/')) {
      api(req, res).catch((erreur) => {
        if (erreur.statut === 413) {
          return repondre(res, 413, 'Fichier trop volumineux : 20 Mo au plus.')
        }

        journal.erreur('http', `${req.method} ${chemin} : ${erreur.message}`)
        repondre(res, erreur.code === 'SATURE' ? 503 : 502, 'Service momentanément indisponible.')
      })

      return
    }

    statique(req, res, chemin)
  })

  // Un client qui envoie ses en-têtes au compte-gouttes ne garde pas une
  // connexion ouverte indéfiniment.
  serveur.headersTimeout = 20_000
  serveur.requestTimeout = 120_000

  return {
    origine: `http://${hote}`,
    ecouter: () =>
      new Promise((resoudre, rejeter) => {
        serveur.once('error', rejeter)
        serveur.listen({ port, host: '127.0.0.1', exclusive: true }, resoudre)
      }),
    fermer: () =>
      new Promise((resoudre) => {
        serveur.close(() => resoudre())
        serveur.closeAllConnections()
      }),
  }
}
