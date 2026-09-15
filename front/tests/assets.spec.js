import { describe, expect, it } from 'vitest'

import { assetUrl } from '@/utils/assets'

/**
 * Les adresses d'images que l'application accepte de charger.
 *
 * Le cas nominal tient en une ligne ; le reste vérifie que rien d'autre ne
 * devient une balise <img> — chaque image chargée depuis ailleurs révélerait
 * l'adresse IP de quiconque la voit.
 */
describe('assetUrl', () => {
  it('rattache un chemin de fichier à l’origine de l’API', () => {
    expect(assetUrl('/api/files/0f1e?expires=7200&signature=abc')).toBe(
      'http://localhost:8080/api/files/0f1e?expires=7200&signature=abc',
    )
  })

  it.each([
    ['un autre domaine', 'https://pistage.example/pixel.gif'],
    ['un domaine sans protocole', '//pistage.example/api/files/x'],
    ['un schéma de script', 'javascript:alert(1)'],
    ['une image en ligne', 'data:image/svg+xml,<svg/>'],
    ['un autre chemin de l’API', '/api/auth/me'],
    ['rien', null],
    ['une chaîne vide', ''],
  ])('refuse %s', (_cas, valeur) => {
    expect(assetUrl(valeur)).toBeNull()
  })
})
