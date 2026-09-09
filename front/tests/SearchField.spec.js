import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

import SearchField from '@/components/ui/SearchField.vue'

/**
 * Le champ de recherche partagé.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  CE FICHIER EXISTE POUR UNE LIGNE PRÉCISE                           │
 * │                                                                     │
 * │  L'écran générique de module temporise sa recherche côté serveur :  │
 * │  une requête après la frappe, pas une par caractère. Il l'accroche  │
 * │  avec « @input ». Avant l'extraction, cet écouteur était posé sur   │
 * │  le champ lui-même ; il est maintenant posé sur le COMPOSANT, donc  │
 * │  reporté par Vue sur sa racine — un « div ». Il ne fonctionne que   │
 * │  parce que l'événement « input » remonte du champ jusqu'à elle.     │
 * │                                                                     │
 * │  Or cet écran n'est atteignable que si un module existe en base     │
 * │  SANS écran dédié, et les cinq en ont un. Ni Playwright ni personne │
 * │  ne passe par cette ligne. Sans ce test, la temporisation pourrait  │
 * │  être morte sans que rien ne le dise : l'écran continuerait de      │
 * │  filtrer — en envoyant une requête par caractère.                   │
 * └─────────────────────────────────────────────────────────────────────┘
 */
describe('SearchField', () => {
  const monter = (props = {}) =>
    mount(SearchField, {
      props: { placeholder: 'Rechercher…', label: 'Rechercher un élément', ...props },
    })

  it('remonte la saisie par v-model', async () => {
    const wrapper = monter({ modelValue: '' })

    await wrapper.get('input').setValue('quota')

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['quota'])
  })

  it('laisse l’événement « input » atteindre le parent', async () => {
    const onInput = vi.fn()
    const wrapper = mount(SearchField, {
      props: { placeholder: 'Rechercher…', label: 'Rechercher un élément' },
      attrs: { onInput },
    })

    await wrapper.get('input').setValue('a')

    // La garantie dont dépend la temporisation de l'écran générique.
    expect(onInput).toHaveBeenCalledTimes(1)
  })

  it('donne au parent de quoi y amener le curseur', async () => {
    // Le module Tickets s'en sert pour la touche « / » : placer le curseur,
    // puis sélectionner, pour qu'une frappe remplace la recherche précédente
    // au lieu de s'y ajouter.
    const wrapper = monter({ modelValue: 'ancienne' })
    document.body.appendChild(wrapper.element)

    wrapper.vm.focus()
    expect(document.activeElement).toBe(wrapper.get('input').element)

    wrapper.vm.select()
    expect(wrapper.get('input').element.selectionEnd).toBe('ancienne'.length)

    wrapper.unmount()
  })

  it('porte un nom accessible, faute d’étiquette visible', () => {
    const wrapper = monter({ label: 'Rechercher un déploiement' })

    // Une loupe n'est pas un intitulé : sans cet attribut, le champ
    // s'annoncerait « zone de recherche », sans dire de quoi.
    expect(wrapper.get('input').attributes('aria-label')).toBe('Rechercher un déploiement')
  })

  it('règle sa taille sur son voisinage, pas sur son écran', () => {
    const compact = monter()
    const normal = monter({ size: 'regular' })

    // Compact dans une barre d'outils de module, normal à côté d'une liste
    // déroulante : c'est un choix, et il doit rester distinguable.
    expect(compact.get('input').classes()).toContain('py-2')
    expect(normal.get('input').classes()).not.toContain('py-2')
  })
})
