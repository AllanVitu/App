import { describe, expect, it } from 'vitest'

import { CONSERVATION, TERMS_VERSION, needsTermsAcceptance } from '@/utils/legal'

describe('textes légaux', () => {
  it('demande d’accepter toute version qui n’est pas celle en vigueur', () => {
    expect(needsTermsAcceptance({ terms_version: '1.0' })).toBe(true)
    expect(needsTermsAcceptance({ terms_version: null })).toBe(true)
    expect(needsTermsAcceptance({ terms_version: TERMS_VERSION })).toBe(false)
  })

  it('ne demande rien tant qu’aucun compte n’est chargé', () => {
    expect(needsTermsAcceptance(null)).toBe(false)
  })

  it('publie une durée pour chaque catégorie de données', () => {
    expect(CONSERVATION.length).toBeGreaterThan(0)

    for (const ligne of CONSERVATION) {
      expect(ligne.donnees.trim()).not.toBe('')
      expect(ligne.duree.trim()).not.toBe('')
    }
  })
})
