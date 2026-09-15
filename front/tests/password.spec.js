import { describe, expect, it } from 'vitest'

import { passwordBits, passwordProblem, passwordStrength } from '@/utils/password'

/**
 * Les mêmes exemples que back/tests/Unit/PasswordPolicyTest.php. Si les deux
 * règles divergent, l'écran annonce « excellent » un mot de passe que l'API
 * refuse — ou l'inverse.
 */
const ACCEPTES = ['Password123!', 'Motdepasse2026', 'cheval batterie agrafe', 'Élégance été 2026']
const REFUSES = ['motdepasse1', 'Motdepasse1', 'Motdepasse12', 'Ab1!'.repeat(5), 'a'.repeat(40)]

describe('passwordProblem', () => {
  it.each(ACCEPTES)('accepte « %s »', (value) => {
    expect(passwordProblem(value)).toBeNull()
  })

  it.each(REFUSES)('refuse « %s »', (value) => {
    expect(passwordProblem(value)).not.toBeNull()
  })

  it('ne juge que les 72 octets que bcrypt lit', () => {
    expect(passwordProblem('a'.repeat(72) + 'Zx9!Qw8?')).not.toBeNull()
    expect(passwordProblem('Zx9!Qw8?' + 'a'.repeat(70))).toBeNull()
  })

  it('refuse les caractères de contrôle', () => {
    expect(passwordProblem('Password123!\0suite')).not.toBeNull()
    expect(passwordProblem('Password123!\tsuite')).not.toBeNull()
  })

  it('refuse au-delà de deux cents caractères', () => {
    expect(passwordProblem('Zx9!Qw8?' + 'a'.repeat(193))).not.toBeNull()
    expect(passwordProblem('Zx9!Qw8?' + 'a'.repeat(192))).toBeNull()
  })
})

describe('passwordBits', () => {
  it('multiplie la longueur par le logarithme de l’alphabet employé', () => {
    expect(passwordBits('Password123!')).toBeCloseTo(12 * Math.log2(95), 4)
    expect(passwordBits('motdepasse1')).toBeCloseTo(11 * Math.log2(36), 4)
    expect(passwordBits('')).toBe(0)
  })

  it('compte en caractères, et coupe en octets', () => {
    // « é » pèse deux octets : le trente-septième n'est plus lu par bcrypt.
    expect(passwordBits('é'.repeat(37))).toBeCloseTo(36 * Math.log2(33), 4)
  })
})

describe('passwordStrength', () => {
  it('ne mesure rien sur un champ vide', () => {
    expect(passwordStrength('')).toMatchObject({ score: 0, acceptable: false })
  })

  it('n’affiche jamais « moyen » un mot de passe que l’API accepte', () => {
    // 79 bits, accepté au titre de l'exemple de la CNIL.
    expect(passwordStrength('Password123!')).toMatchObject({ score: 4, acceptable: true })
  })

  it('n’affiche jamais « bon » un mot de passe que l’API refuse', () => {
    expect(passwordStrength('Motdepasse12')).toMatchObject({ score: 3, acceptable: false })
    expect(passwordStrength('Ab1!'.repeat(5)).score).toBeLessThanOrEqual(3)
  })

  it('réserve « excellent » aux mots de passe au-delà de cent bits', () => {
    expect(passwordStrength('cheval batterie agrafe')).toMatchObject({ score: 5, acceptable: true })
  })
})
