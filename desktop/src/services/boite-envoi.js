// La boîte d'envoi : les e-mails de service que l'API a « envoyés ».
//
// Sur le poste, le transport « fichier » de l'API (MAIL_TRANSPORT) les dépose
// dans un dossier, un .eml par message. L'application les lit ici pour les
// afficher : un lien de réinitialisation du mot de passe ou d'invitation doit
// pouvoir être suivi, sans serveur de messagerie.
//
// Seul le texte brut est lu. La partie HTML existe dans le fichier, mais
// l'afficher reviendrait à exécuter, dans l'application, un balisage construit
// en partie avec ce que les gens ont saisi (un nom, un nom d'espace).
import { existsSync, readdirSync, readFileSync, statSync, watch } from 'node:fs'
import { join } from 'node:path'

/** Le nom que donne l'API : horodatage UTC, puis 12 caractères aléatoires. */
export const NOM_MESSAGE = /^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z-[a-f0-9]{12}\.eml$/

const TAILLE_MAX = 2 * 1024 * 1024
const LISTE_MAX = 200

/** Les mots encodés des en-têtes (RFC 2047), en UTF-8, base64 ou quoted-printable. */
export function decoderEntete(valeur) {
  return String(valeur).replace(/=\?utf-8\?([bq])\?([^?]*)\?=/gi, (_, encodage, texte) => {
    if (encodage.toLowerCase() === 'b') {
      return Buffer.from(texte, 'base64').toString('utf8')
    }

    const octets = texte.replaceAll('_', ' ').replace(/=([0-9a-f]{2})/gi, (__, hex) => String.fromCharCode(Number.parseInt(hex, 16)))

    return Buffer.from(octets, 'latin1').toString('utf8')
  })
}

function separer(bloc) {
  const fin = bloc.search(/\r?\n\r?\n/)

  if (fin === -1) {
    return { tete: bloc, corps: '' }
  }

  const saut = bloc.slice(fin).match(/^\r?\n\r?\n/)[0].length

  return { tete: bloc.slice(0, fin), corps: bloc.slice(fin + saut) }
}

function lireEntetes(tete) {
  const entetes = new Map()

  // Une ligne qui commence par un blanc prolonge la précédente (RFC 5322 §2.2.3).
  for (const ligne of tete.replace(/\r?\n[ \t]+/g, ' ').split(/\r?\n/)) {
    const separateur = ligne.indexOf(':')

    if (separateur > 0) {
      entetes.set(ligne.slice(0, separateur).trim().toLowerCase(), ligne.slice(separateur + 1).trim())
    }
  }

  return entetes
}

function decoderCorps(corps, encodage) {
  switch (String(encodage ?? '').toLowerCase()) {
    case 'base64':
      return Buffer.from(corps.replace(/\s+/g, ''), 'base64').toString('utf8')
    case 'quoted-printable':
      return Buffer.from(
        corps.replace(/=\r?\n/g, '').replace(/=([0-9a-f]{2})/gi, (_, hex) => String.fromCharCode(Number.parseInt(hex, 16))),
        'latin1',
      ).toString('utf8')
    default:
      return corps
  }
}

export function analyserEml(brut) {
  const { tete, corps } = separer(brut)
  const entetes = lireEntetes(tete)
  const type = entetes.get('content-type') ?? 'text/plain'
  const frontiere = type.match(/boundary="?([^";]+)"?/i)?.[1]

  let texte = null

  if (/^multipart\//i.test(type) && frontiere) {
    for (const partie of corps.split(`--${frontiere}`)) {
      const { tete: tetePartie, corps: corpsPartie } = separer(partie.replace(/^\r?\n/, ''))
      const entetesPartie = lireEntetes(tetePartie)

      if (/^text\/plain/i.test(entetesPartie.get('content-type') ?? '')) {
        texte = decoderCorps(corpsPartie, entetesPartie.get('content-transfer-encoding'))

        break
      }
    }
  } else if (/^text\/plain/i.test(type)) {
    texte = decoderCorps(corps, entetes.get('content-transfer-encoding'))
  }

  return {
    de: decoderEntete(entetes.get('from') ?? ''),
    a: decoderEntete(entetes.get('to') ?? ''),
    sujet: decoderEntete(entetes.get('subject') ?? ''),
    texte: texte?.replace(/\r\n/g, '\n').trimEnd() ?? '',
  }
}

/** Les adresses web d'un texte, sans la ponctuation qui les suit dans une phrase. */
export function extraireLiens(texte) {
  const liens = new Set()

  for (const trouve of String(texte).matchAll(/https?:\/\/[^\s<>"']+/g)) {
    liens.add(trouve[0].replace(/[.,;:!?)\]]+$/, ''))
  }

  return [...liens]
}

function dateDuNom(nom) {
  const [, a, mo, j, h, mi, s] = nom.match(NOM_MESSAGE)

  return `${a}-${mo}-${j}T${h}:${mi}:${s}Z`
}

export function listerMessages(dossier) {
  if (!existsSync(dossier)) {
    return []
  }

  return readdirSync(dossier)
    .filter((nom) => NOM_MESSAGE.test(nom))
    .sort()
    .reverse()
    .slice(0, LISTE_MAX)
    .map((nom) => {
      const message = lireMessage(dossier, nom)

      return message && { id: nom, date: message.date, a: message.a, sujet: message.sujet }
    })
    .filter(Boolean)
}

/** Un message, désigné par son nom exact — jamais par un chemin. */
export function lireMessage(dossier, id) {
  if (typeof id !== 'string' || !NOM_MESSAGE.test(id)) {
    return null
  }

  const chemin = join(dossier, id)

  try {
    if (statSync(chemin).size > TAILLE_MAX) {
      return null
    }

    const message = analyserEml(readFileSync(chemin, 'utf8'))

    return { id, date: dateDuNom(id), ...message, liens: extraireLiens(message.texte) }
  } catch {
    return null
  }
}

/** Prévient à l'arrivée de chaque nouveau message (renommage final de l'API). */
export function surveiller(dossier, surNouveau) {
  const vus = new Set(existsSync(dossier) ? readdirSync(dossier) : [])

  const observateur = watch(dossier, (_, nom) => {
    if (nom && NOM_MESSAGE.test(nom) && !vus.has(nom) && existsSync(join(dossier, nom))) {
      vus.add(nom)
      surNouveau(nom)
    }
  })

  return () => observateur.close()
}
