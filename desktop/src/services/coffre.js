// Le coffre : les secrets de l'installation, scellés par Windows.
//
// Trois secrets naissent au premier lancement et ne quittent jamais le poste :
//   - la clé maîtresse de l'API (APP_KEY), dont dérivent les chiffrements —
//     secrets de double authentification, jetons sensibles ;
//   - le secret de signature des jetons de session (JWT_SECRET) ;
//   - le mot de passe du compte PostgreSQL local.
//
// Sur disque, ils sont scellés : sous Electron, par safeStorage, c'est-à-dire
// DPAPI, la protection de données de Windows liée au compte de l'utilisateur.
// Le fichier copié sur une autre machine, ou lu depuis un autre compte, ne se
// déchiffre pas.
//
// Le scellement est injecté (sceller, desceller) : l'application fournit
// safeStorage, le vérificateur autonome un scellement de test. Le coffre
// refuse de fonctionner sans : il n'existe pas de mode « en clair ».
import { randomBytes } from 'node:crypto'
import { existsSync, mkdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs'
import { dirname } from 'node:path'

const VERSION = 1

export function nouveauxSecrets() {
  return {
    version: VERSION,
    appKey: randomBytes(48).toString('base64url'),
    jwtSecret: randomBytes(48).toString('base64url'),
    motDePasseBase: randomBytes(32).toString('base64url'),
  }
}

function valides(secrets) {
  return (
    secrets !== null &&
    typeof secrets === 'object' &&
    secrets.version === VERSION &&
    ['appKey', 'jwtSecret', 'motDePasseBase'].every((cle) => typeof secrets[cle] === 'string' && secrets[cle].length >= 32)
  )
}

export function ouvrirCoffre({ fichier, sceller, desceller }) {
  if (typeof sceller !== 'function' || typeof desceller !== 'function') {
    throw new Error('Le coffre ne fonctionne pas sans scellement.')
  }

  if (existsSync(fichier)) {
    let secrets

    try {
      secrets = JSON.parse(desceller(readFileSync(fichier)))
    } catch {
      // Ne jamais régénérer ici : de nouveaux secrets rendraient illisibles
      // la base (autre mot de passe) et les secrets chiffrés qu'elle contient.
      throw Object.assign(
        new Error('Le coffre de cette installation ne peut pas être ouvert avec ce compte Windows.'),
        { code: 'COFFRE_ILLISIBLE' },
      )
    }

    if (!valides(secrets)) {
      throw Object.assign(new Error('Le coffre de cette installation est altéré.'), { code: 'COFFRE_ILLISIBLE' })
    }

    return { secrets, cree: false }
  }

  const secrets = nouveauxSecrets()

  mkdirSync(dirname(fichier), { recursive: true })

  // Écrit à côté puis renommé : une coupure en pleine écriture ne laisse
  // jamais un coffre tronqué, que plus rien ne saurait ouvrir.
  const temporaire = `${fichier}.${process.pid}.tmp`
  writeFileSync(temporaire, sceller(JSON.stringify(secrets)), { mode: 0o600 })
  renameSync(temporaire, fichier)

  return { secrets, cree: true }
}
