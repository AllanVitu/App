import { describe, expect, it } from 'vitest'

import { parseBlocks, parseInlines, plainExcerpt, safeHref } from '@/utils/richText'

describe('ce qui ne passe jamais', () => {
  it('garde une balise comme du texte, sans rien interpréter', () => {
    expect(parseInlines('<script>alert(1)</script>')).toEqual([
      { type: 'text', value: '<script>alert(1)</script>' },
    ])

    expect(parseBlocks('<img src=x onerror=alert(1)>')).toEqual([
      { type: 'paragraph', inlines: [{ type: 'text', value: '<img src=x onerror=alert(1)>' }] },
    ])
  })

  it('refuse les adresses qui exécutent ou emportent quelque chose', () => {
    expect(safeHref('javascript:alert(1)')).toBeNull()
    expect(safeHref(' JaVaScRiPt:alert(1)')).toBeNull()
    expect(safeHref('data:text/html;base64,PHNjcmlwdD4=')).toBeNull()
    expect(safeHref('vbscript:msgbox(1)')).toBeNull()
    expect(safeHref('//evil.example/')).toBeNull()
    expect(safeHref('pas une adresse')).toBeNull()
  })

  it('accepte le web, le courriel et les chemins de l’application', () => {
    expect(safeHref('https://relais.example/guide')).toBe('https://relais.example/guide')
    expect(safeHref('mailto:equipe@relais.example')).toBe('mailto:equipe@relais.example')
    expect(safeHref('/modules/tickets')).toBe('/modules/tickets')
  })

  it('laisse visible, en texte, un lien dont l’adresse est refusée', () => {
    expect(parseInlines('[clique](javascript:alert(1))')).toEqual([
      { type: 'text', value: '[clique](javascript:alert(1))' },
    ])
  })
})

describe('fragments en ligne', () => {
  it('lit le gras, l’italique, le code et les liens', () => {
    expect(
      parseInlines(
        '**Attention** : lancez `composer migrate` puis *vérifiez* le [guide](https://relais.example).',
      ),
    ).toEqual([
      { type: 'strong', value: 'Attention' },
      { type: 'text', value: ' : lancez ' },
      { type: 'code', value: 'composer migrate' },
      { type: 'text', value: ' puis ' },
      { type: 'em', value: 'vérifiez' },
      { type: 'text', value: ' le ' },
      { type: 'link', value: 'guide', href: 'https://relais.example/' },
      { type: 'text', value: '.' },
    ])
  })

  it('ne lit pas de gras à l’intérieur du code', () => {
    expect(parseInlines('`**pas du gras**`')).toEqual([{ type: 'code', value: '**pas du gras**' }])
  })

  it('reconnaît un ticket, mais pas un nombre collé à un mot', () => {
    expect(parseInlines('Voir #142 et (#7), pas C#42 ni #12a')).toEqual([
      { type: 'text', value: 'Voir ' },
      { type: 'ticket', value: '#142', number: 142 },
      { type: 'text', value: ' et (' },
      { type: 'ticket', value: '#7', number: 7 },
      { type: 'text', value: '), pas C#42 ni #12a' },
    ])
  })
})

describe('blocs', () => {
  it('découpe une procédure en titres, listes, citation et code', () => {
    const blocs = parseBlocks(
      [
        '# Restaurer une sauvegarde',
        '',
        'À faire **hors des heures** de pointe.',
        'Compter dix minutes.',
        '',
        '1. Arrêter le worker',
        '2. Restaurer la base',
        '',
        '- vérifier le journal',
        '- prévenir l’équipe',
        '',
        '> Ne jamais restaurer par-dessus la production sans copie.',
        '',
        '```',
        'pg_restore -d saas_db sauvegarde.dump',
        '```',
      ].join('\n'),
    )

    expect(blocs.map((bloc) => bloc.type)).toEqual([
      'heading',
      'paragraph',
      'list',
      'list',
      'quote',
      'code',
    ])
    expect(blocs[0].level).toBe(1)
    expect(blocs[1].inlines.map((fragment) => fragment.value).join('')).toBe(
      'À faire hors des heures de pointe. Compter dix minutes.',
    )
    expect(blocs[2]).toMatchObject({ ordered: true })
    expect(blocs[2].items).toHaveLength(2)
    expect(blocs[3]).toMatchObject({ ordered: false })
    expect(blocs[5]).toEqual({ type: 'code', text: 'pg_restore -d saas_db sauvegarde.dump' })
  })

  it('ne lit rien dans un bloc de code, et garde un bloc jamais refermé', () => {
    expect(parseBlocks('```\n# pas un titre\n**pas du gras**')).toEqual([
      { type: 'code', text: '# pas un titre\n**pas du gras**' },
    ])
  })

  it('accepte les fins de ligne de Windows', () => {
    expect(parseBlocks('# Titre\r\n\r\nTexte').map((bloc) => bloc.type)).toEqual([
      'heading',
      'paragraph',
    ])
  })
})

describe('aperçu', () => {
  it('retire la syntaxe et le code, et coupe proprement', () => {
    expect(plainExcerpt('# Titre\n\n**Gras** et `code`\n\n```\nsecret\n```')).toBe(
      'Titre Gras et code',
    )
    expect(plainExcerpt('a'.repeat(200), 20)).toBe(`${'a'.repeat(19)}…`)
  })
})
