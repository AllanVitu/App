// FastCGI, côté serveur web : le strict nécessaire pour parler à php-cgi.
//
// Ce que fait nginx dans l'image Docker (fastcgi_pass), l'application de bureau
// le fait ici. Le protocole tient en peu de choses : des enregistrements d'un
// en-tête de 8 octets, d'un contenu de 65 535 octets au plus, et d'un bourrage
// jusqu'au multiple de 8 suivant.
//
//   https://fastcgi-archives.github.io/FastCGI_Specification.html
import net from 'node:net'

export const TYPES = Object.freeze({
  DEBUT: 1,
  ABANDON: 2,
  FIN: 3,
  PARAMETRES: 4,
  ENTREE: 5,
  SORTIE: 6,
  ERREUR: 7,
})

const VERSION = 1
const ROLE_REPONDEUR = 1
const CONTENU_MAX = 0xffff

/** Au-delà, un bloc d'en-têtes CGI n'est plus une réponse, c'est une panne. */
const ENTETES_MAX = 64 * 1024

export function enregistrement(type, id, contenu = Buffer.alloc(0)) {
  if (contenu.length > CONTENU_MAX) {
    throw new RangeError('Contenu trop long pour un seul enregistrement FastCGI.')
  }

  const bourrage = (8 - (contenu.length % 8)) % 8
  const entete = Buffer.alloc(8)

  entete.writeUInt8(VERSION, 0)
  entete.writeUInt8(type, 1)
  entete.writeUInt16BE(id, 2)
  entete.writeUInt16BE(contenu.length, 4)
  entete.writeUInt8(bourrage, 6)

  return Buffer.concat([entete, contenu, Buffer.alloc(bourrage)])
}

/** Un flux (paramètres ou entrée) : découpé en enregistrements, clos par un enregistrement vide. */
export function flux(type, id, contenu) {
  const morceaux = []

  for (let debut = 0; debut < contenu.length; debut += CONTENU_MAX) {
    morceaux.push(enregistrement(type, id, contenu.subarray(debut, debut + CONTENU_MAX)))
  }

  morceaux.push(enregistrement(type, id))

  return Buffer.concat(morceaux)
}

/** Une longueur tient sur 1 octet sous 128, sur 4 au-delà, bit de poids fort levé. */
function longueur(n) {
  if (n < 0x80) {
    return Buffer.from([n])
  }

  const octets = Buffer.alloc(4)
  octets.writeUInt32BE((n | 0x80000000) >>> 0)

  return octets
}

export function paires(parametres) {
  const morceaux = []

  for (const [nom, valeur] of Object.entries(parametres)) {
    if (valeur === undefined || valeur === null) {
      continue
    }

    const n = Buffer.from(String(nom), 'utf8')
    const v = Buffer.from(String(valeur), 'utf8')

    morceaux.push(longueur(n.length), longueur(v.length), n, v)
  }

  return Buffer.concat(morceaux)
}

/** L'opération inverse de paires() — c'est ce que lit php-cgi. */
export function decoderPaires(contenu) {
  const resultat = {}
  let position = 0

  const lireLongueur = () => {
    const premier = contenu.readUInt8(position)

    if (premier < 0x80) {
      position += 1

      return premier
    }

    const valeur = contenu.readUInt32BE(position) & 0x7fffffff
    position += 4

    return valeur
  }

  while (position < contenu.length) {
    const tailleNom = lireLongueur()
    const tailleValeur = lireLongueur()
    const nom = contenu.subarray(position, position + tailleNom).toString('utf8')
    position += tailleNom
    resultat[nom] = contenu.subarray(position, position + tailleValeur).toString('utf8')
    position += tailleValeur
  }

  return resultat
}

/** Réassemble les enregistrements, quel que soit le découpage des paquets TCP. */
export class Lecteur {
  #tampon = Buffer.alloc(0)

  pousser(morceau) {
    this.#tampon = this.#tampon.length > 0 ? Buffer.concat([this.#tampon, morceau]) : morceau

    const enregistrements = []

    while (this.#tampon.length >= 8) {
      const taille = this.#tampon.readUInt16BE(4)
      const total = 8 + taille + this.#tampon.readUInt8(6)

      if (this.#tampon.length < total) {
        break
      }

      if (this.#tampon.readUInt8(0) !== VERSION) {
        throw new Error('Version FastCGI inattendue.')
      }

      enregistrements.push({
        type: this.#tampon.readUInt8(1),
        id: this.#tampon.readUInt16BE(2),
        contenu: this.#tampon.subarray(8, 8 + taille),
      })

      this.#tampon = this.#tampon.subarray(total)
    }

    return enregistrements
  }
}

/**
 * Les en-têtes CGI d'une réponse PHP : « Status: 404 Not Found » donne le code,
 * le reste passe tel quel. Un nom qui n'est pas un jeton HTTP valide est
 * écarté plutôt que transmis.
 */
export function analyserEntetes(texte) {
  let statut = 200
  let statutExplicite = false
  const entetes = []

  for (const ligne of texte.split(/\r?\n/)) {
    const separateur = ligne.indexOf(':')

    if (separateur <= 0) {
      continue
    }

    const nom = ligne.slice(0, separateur).trim()
    const valeur = ligne.slice(separateur + 1).trim()

    if (!/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/.test(nom)) {
      continue
    }

    if (nom.toLowerCase() === 'status') {
      const code = Number.parseInt(valeur, 10)

      if (code >= 100 && code <= 599) {
        statut = code
        statutExplicite = true
      }

      continue
    }

    entetes.push([nom, valeur])
  }

  // CGI/1.1 : une redirection sans statut explicite est une 302.
  if (!statutExplicite && entetes.some(([nom]) => nom.toLowerCase() === 'location')) {
    statut = 302
  }

  return { statut, entetes }
}

/** Sépare, au fil de l'eau, le bloc d'en-têtes CGI du corps de la réponse. */
export class ReponseCgi {
  #tete = Buffer.alloc(0)
  #entetesLus = false

  constructor({ surEntetes, surCorps }) {
    this.surEntetes = surEntetes
    this.surCorps = surCorps
  }

  get entetesLus() {
    return this.#entetesLus
  }

  pousser(morceau) {
    if (this.#entetesLus) {
      this.surCorps(morceau)

      return
    }

    this.#tete = Buffer.concat([this.#tete, morceau])

    let fin = this.#tete.indexOf('\r\n\r\n')
    let saut = 4

    if (fin === -1) {
      fin = this.#tete.indexOf('\n\n')
      saut = 2
    }

    if (fin === -1) {
      if (this.#tete.length > ENTETES_MAX) {
        throw new Error('Bloc d’en-têtes CGI démesuré.')
      }

      return
    }

    const reste = this.#tete.subarray(fin + saut)
    this.#entetesLus = true
    this.surEntetes(analyserEntetes(this.#tete.subarray(0, fin).toString('latin1')))
    this.#tete = Buffer.alloc(0)

    if (reste.length > 0) {
      this.surCorps(reste)
    }
  }
}

/**
 * Une requête complète sur une connexion neuve : paramètres, corps, puis
 * lecture de la réponse jusqu'à l'enregistrement de fin.
 *
 * Le corps arrive déjà entier : php-cgi ne lit l'entrée qu'à hauteur de
 * CONTENT_LENGTH, qu'il faut donc connaître avant d'envoyer quoi que ce soit.
 */
export function requeteFastCgi({ port, parametres, corps = Buffer.alloc(0), delai = 60_000, surEntetes, surCorps, surErreurPhp }) {
  const id = 1
  const socket = net.connect({ port, host: '127.0.0.1' })
  const lecteur = new Lecteur()
  const reponse = new ReponseCgi({ surEntetes, surCorps })

  let terminee = false

  const promesse = new Promise((resoudre, rejeter) => {
    const echouer = (erreur) => {
      if (!terminee) {
        terminee = true
        socket.destroy()
        rejeter(erreur)
      }
    }

    socket.setNoDelay(true)
    socket.setTimeout(delai, () => echouer(Object.assign(new Error('php-cgi ne répond plus.'), { code: 'DELAI' })))

    socket.on('connect', () => {
      const debut = Buffer.alloc(8)
      debut.writeUInt16BE(ROLE_REPONDEUR, 0)

      socket.write(
        Buffer.concat([
          enregistrement(TYPES.DEBUT, id, debut),
          flux(TYPES.PARAMETRES, id, paires(parametres)),
          flux(TYPES.ENTREE, id, corps),
        ]),
      )
    })

    socket.on('data', (morceau) => {
      try {
        for (const { type, contenu } of lecteur.pousser(morceau)) {
          if (type === TYPES.SORTIE && contenu.length > 0) {
            reponse.pousser(contenu)
          } else if (type === TYPES.ERREUR && contenu.length > 0) {
            surErreurPhp?.(contenu.toString('utf8'))
          } else if (type === TYPES.FIN) {
            if (!reponse.entetesLus) {
              throw new Error('php-cgi a terminé sans en-têtes.')
            }

            terminee = true
            socket.end()
            resoudre()

            return
          }
        }
      } catch (erreur) {
        echouer(erreur)
      }
    })

    socket.on('error', echouer)
    socket.on('close', () => echouer(Object.assign(new Error('php-cgi a fermé la connexion.'), { code: 'FERMEE' })))
  })

  return { promesse, annuler: () => socket.destroy() }
}
