import { defineComponent } from 'vue'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { DESTINATIONS, useAppShortcuts } from '@/composables/useAppShortcuts'
import router from '@/router'

/**
 * Les raccourcis de toute l'application.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  UN RACCOURCI SE VÉRIFIE MAL DANS UN NAVIGATEUR                     │
 * │                                                                     │
 * │  Playwright sait taper « g » puis « t » et constater la page. Il ne │
 * │  sait pas attendre 1,2 seconde entre les deux sans allonger la      │
 * │  suite d'autant, ni prouver qu'aucun écouteur ne survit au          │
 * │  démontage. Ces cas-là sont ceux qui cassent en silence : une       │
 * │  séquence qui ne s'oublie jamais piège la touche suivante, un       │
 * │  écouteur oublié fait naviguer une page qui n'est plus là.          │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * `useRouter` est remplacé pour observer la destination sans naviguer pour de
 * bon — une navigation réelle déclencherait la garde d'authentification, qui
 * n'a rien à voir avec ce qu'on vérifie ici. Le VRAI routeur est tout de même
 * importé : le dernier test lui demande de résoudre chaque destination.
 */
const { push } = vi.hoisted(() => ({ push: vi.fn() }))

vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal()),
  useRouter: () => ({ push }),
}))

/** Un composant minimal, seul moyen d'obtenir `onMounted` et son écouteur. */
const Harness = defineComponent({
  setup: () => useAppShortcuts(),
  template: '<div />',
})

/** Frappe une touche là où le ferait un utilisateur : sur l'élément focalisé. */
function frappe(key, cible = document) {
  const event = new KeyboardEvent('keydown', { key, bubbles: true })
  cible.dispatchEvent(event)

  return event
}

// Démonte tout composant monté ici, MÊME quand une assertion a échoué avant
// le démontage manuel : l'écouteur d'un composant survivant se déclencherait
// dans les tests suivants, transformant un échec isolé en cascade.
enableAutoUnmount(afterEach)

afterEach(() => {
  vi.useRealTimers()
  document.body.innerHTML = ''
})

describe('useAppShortcuts', () => {
  it('« g » puis une lettre mène à la destination', () => {
    const wrapper = mount(Harness)

    frappe('g')
    // Le témoin « g… » est affiché : une séquence armée sans retour visuel
    // laisse l'utilisateur devant un clavier qui ne répond plus.
    expect(wrapper.vm.pending).toBe(true)

    frappe('t')

    expect(push).toHaveBeenCalledWith('/modules/tickets')
    expect(wrapper.vm.pending).toBe(false)
  })

  it('« g » seul ne navigue pas, et s’oublie au bout de 1,2 s', async () => {
    vi.useFakeTimers()
    const wrapper = mount(Harness)

    frappe('g')
    expect(push).not.toHaveBeenCalled()

    vi.advanceTimersByTime(1200)
    await Promise.resolve()

    expect(wrapper.vm.pending).toBe(false)

    // LE POINT DU DÉLAI : sans oubli, un « g » tapé par erreur resterait armé,
    // et le « t » d'une saisie commencée bien plus tard ferait changer de page
    // sans que rien ne l'explique.
    frappe('t')
    expect(push).not.toHaveBeenCalled()
  })

  it('une lettre sans destination désarme la séquence sans naviguer', () => {
    const wrapper = mount(Harness)

    frappe('g')
    const event = frappe('z')

    expect(push).not.toHaveBeenCalled()
    expect(wrapper.vm.pending).toBe(false)

    // Non capturée : la touche appartient à la page, pas au raccourci.
    expect(event.defaultPrevented).toBe(false)
  })

  it('ne se déclenche pas pendant une saisie', () => {
    const wrapper = mount(Harness)

    const champ = document.createElement('input')
    document.body.appendChild(champ)

    frappe('g', champ)
    frappe('t', champ)

    expect(push).not.toHaveBeenCalled()
    expect(wrapper.vm.pending).toBe(false)

    // « ? » doit rester un point d'interrogation dans un titre de ticket.
    frappe('?', champ)
    expect(wrapper.vm.helpOpen).toBe(false)
  })

  it('ne se déclenche pas non plus dans une zone de texte enrichi', () => {
    mount(Harness)

    // `isContentEditable` n'est pas déduit du nom de balise : un éditeur riche
    // est un `div`, et ce cas se serait perdu si le test ne visait que
    // « input » et « textarea ».
    const zone = document.createElement('div')
    Object.defineProperty(zone, 'isContentEditable', { value: true })
    document.body.appendChild(zone)

    frappe('g', zone)
    frappe('t', zone)

    expect(push).not.toHaveBeenCalled()
  })

  it('laisse les combinaisons au navigateur', () => {
    const wrapper = mount(Harness)

    for (const modifier of ['ctrlKey', 'metaKey', 'altKey']) {
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'g', [modifier]: true }))
      expect(wrapper.vm.pending).toBe(false)
    }

    expect(push).not.toHaveBeenCalled()
  })

  it('« ? » ouvre et referme la feuille, Échap la referme même depuis un champ', () => {
    const wrapper = mount(Harness)

    frappe('?')
    expect(wrapper.vm.helpOpen).toBe(true)

    frappe('?')
    expect(wrapper.vm.helpOpen).toBe(false)

    frappe('?')

    // Échap est une sortie de secours : elle ne dépend pas du focus, sinon la
    // feuille resterait ouverte devant un champ qu'elle recouvre.
    const champ = document.createElement('input')
    document.body.appendChild(champ)
    frappe('Escape', champ)

    expect(wrapper.vm.helpOpen).toBe(false)
  })

  it('ne laisse aucun écouteur derrière lui', () => {
    const wrapper = mount(Harness)
    wrapper.unmount()

    frappe('g')
    frappe('t')

    // Un écouteur oublié survit à la page qui l'a posé : il ferait naviguer
    // depuis n'importe où, et rien à l'écran ne dirait d'où ça vient.
    expect(push).not.toHaveBeenCalled()
  })

  it('chaque destination annoncée correspond à une route existante', () => {
    // Le seul test qui interroge le VRAI routeur. Une entrée mal écrite dans
    // la liste ne casse rien à la compilation, ne casse rien à l'affichage de
    // la feuille d'aide — elle mène simplement à une page vide, le jour où
    // quelqu'un l'essaie.
    //
    // Constater qu'une destination « résout » ne dirait rigoureusement rien :
    // DEUX routes de repli acceptent n'importe quoi. L'attrape-tout
    // « not-found », et surtout « module » — la vue générique de
    // « modules/:slug », qui recevrait « /modules/tikets » sans broncher et
    // afficherait un module vide. C'est donc l'identité de la route qu'on
    // vérifie, pas son existence.
    //
    // (Une destination désignée par son nom, elle, fait lever `resolve` si le
    // nom n'existe pas : le test échoue alors avant même l'assertion.)
    const REPLIS = ['not-found', 'module']

    for (const destination of DESTINATIONS) {
      const resolue = router.resolve(destination.to)

      expect(REPLIS, `« g ${destination.key} » (${destination.label})`).not.toContain(resolue.name)
    }
  })

  it('n’attribue jamais deux fois la même lettre', () => {
    const lettres = DESTINATIONS.map((entry) => entry.key)

    // Un doublon serait invisible : `find` retient la première, la seconde
    // destination deviendrait inatteignable tout en restant affichée dans la
    // feuille d'aide.
    expect(new Set(lettres).size).toBe(lettres.length)
  })
})
