/**
 * Copie, côté client, de App\Core\PasswordPolicy.
 *
 * Elle guide la saisie — indicateur, message avant l'envoi — et l'API seule
 * fait foi. Les deux se vérifient sur les mêmes exemples
 * (tests/password.spec.js et back/tests/Unit/PasswordPolicyTest.php) : une
 * divergence ferait annoncer « excellent » un mot de passe refusé.
 *
 * 80 bits, mesurés comme la CNIL les mesure : longueur × log2(alphabet).
 */

export const MIN_BITS = 80
export const MAX_LENGTH = 200

export const PASSWORD_HINT =
  '12 caractères mêlant majuscules, minuscules, chiffres et symboles, ou une phrase de passe plus longue.'

/** bcrypt ignore tout ce qui dépasse. */
const BCRYPT_BYTES = 72

/** « Ab1!Ab1!Ab1!Ab1! » a l'alphabet et la longueur, pas la variété. */
const MIN_DISTINCT = 6

const FAMILIES = [
  [/[a-z]/, 26],
  [/[A-Z]/, 26],
  [/[0-9]/, 10],
  // Une lettre accentuée compte ici : elle n'est ni dans [a-z] ni dans [A-Z].
  [/[^a-zA-Z0-9]/u, 33],
]

const encoder = new TextEncoder()

/** Les 72 premiers octets UTF-8, sans couper un caractère en deux. */
function readByBcrypt(value) {
  let bytes = 0
  let read = ''

  for (const char of value) {
    bytes += encoder.encode(char).length
    if (bytes > BCRYPT_BYTES) break
    read += char
  }

  return read
}

function families(value) {
  let pool = 0
  let count = 0

  for (const [pattern, size] of FAMILIES) {
    if (pattern.test(value)) {
      pool += size
      count++
    }
  }

  return { pool, count }
}

/** Caractères de contrôle, sans expression régulière (no-control-regex). */
function hasControl(value) {
  for (const char of value) {
    const code = char.codePointAt(0)
    if (code < 0x20 || code === 0x7f) return true
  }

  return false
}

/** Entropie au sens de la CNIL, sur ce que bcrypt lit réellement. */
export function passwordBits(value) {
  const read = readByBcrypt(value)
  const { pool } = families(read)

  // En caractères, comme mb_strlen : « length » compterait des unités UTF-16.
  return pool === 0 ? 0 : [...read].length * Math.log2(pool)
}

/** Ce qui ne va pas, ou null — les messages de l'API, mot pour mot. */
export function passwordProblem(value) {
  if (hasControl(value)) {
    return 'Le mot de passe contient des caractères non autorisés.'
  }

  if ([...value].length > MAX_LENGTH) {
    return 'Le mot de passe est trop long (200 caractères maximum).'
  }

  const read = readByBcrypt(value)
  const characters = [...read]

  // L'exemple de la CNIL — douze caractères des quatre familles — tombe à
  // 79 bits : l'écarter contredirait la recommandation appliquée.
  const strong =
    passwordBits(value) >= MIN_BITS || (characters.length >= 12 && families(read).count === 4)

  if (!strong || new Set(characters).size < MIN_DISTINCT) {
    return `Mot de passe trop prévisible : ${PASSWORD_HINT}`
  }

  return null
}

/**
 * Pour l'indicateur. Le score suit l'acceptation avant les bits : un mot de
 * passe accepté n'est jamais « moyen », un mot de passe refusé jamais « bon ».
 */
export function passwordStrength(value) {
  if (!value) return { bits: 0, score: 0, acceptable: false }

  const bits = passwordBits(value)
  const acceptable = passwordProblem(value) === null

  let score
  if (acceptable) score = bits >= 100 ? 5 : 4
  else if (bits < 40) score = 1
  else if (bits < 60) score = 2
  else score = 3

  return { bits, score, acceptable }
}
