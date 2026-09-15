import { describe, expect, it } from 'vitest'

import { buildTree, flattenTree } from '@/utils/docs'

const PAGES = [
  { id: 'c', parent_id: 'b', title: 'Restaurer', position: 0 },
  { id: 'a', parent_id: null, title: 'Exploitation', position: 0 },
  { id: 'b', parent_id: 'a', title: 'Base de données', position: 0 },
  { id: 'd', parent_id: 'a', title: 'Alertes', position: 0 },
  { id: 'e', parent_id: null, title: 'Accueil', position: 1 },
  { id: 'f', parent_id: 'disparu', title: 'Orpheline', position: 2 },
]

describe('arbre des pages', () => {
  it('range chaque page sous son parent, par ordre puis par titre', () => {
    const arbre = buildTree(PAGES)

    expect(arbre.map((noeud) => noeud.title)).toEqual(['Exploitation', 'Accueil', 'Orpheline'])
    expect(arbre[0].children.map((noeud) => noeud.title)).toEqual(['Alertes', 'Base de données'])
    expect(arbre[0].children[1].children[0]).toMatchObject({ title: 'Restaurer', depth: 2 })
  })

  it('remonte à la racine une page dont le parent n’est pas là', () => {
    expect(buildTree(PAGES).find((noeud) => noeud.id === 'f')).toMatchObject({ depth: 0 })
  })

  it('s’aplatit dans l’ordre de lecture', () => {
    expect(flattenTree(buildTree(PAGES)).map((noeud) => noeud.id)).toEqual([
      'a',
      'd',
      'b',
      'c',
      'e',
      'f',
    ])
  })

  it('masque une page et son sous-arbre, pour ne pas la ranger sous elle-même', () => {
    expect(flattenTree(buildTree(PAGES), 'b').map((noeud) => noeud.id)).toEqual([
      'a',
      'd',
      'e',
      'f',
    ])
  })
})
