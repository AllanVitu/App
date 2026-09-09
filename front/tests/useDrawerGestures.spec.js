import { defineComponent, ref } from 'vue'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useDrawerGestures } from '@/composables/useDrawerGestures'

/**
 * Les gestes du tiroir latéral.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  DES SEUILS QU'AUCUN TEST NE RETENAIT                               │
 * │                                                                     │
 * │  Quatre nombres gouvernent ce fichier : 28 px de bande de bord,     │
 * │  40 px pour ouvrir, 70 px pour fermer, 8 px de verrou d'axe. Les    │
 * │  changer ne casse rien de visible et ne fait échouer aucune suite — │
 * │  le tiroir devient seulement plus dur, ou trop facile, à ouvrir.    │
 * │                                                                     │
 * │  Et le verrou d'axe, lui, protège quelque chose de précis : sans    │
 * │  lui, un défilement vertical un peu oblique fait glisser le tiroir. │
 * │  C'est invisible sur un poste de développement, où l'on n'a pas de  │
 * │  doigt.                                                             │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * `matchMedia` est remplacé : jsdom n'évalue aucune requête média, et c'est
 * elle qui décide si les écouteurs sont posés. La contrôler permet en outre
 * d'éprouver le passage mobile → bureau, qui doit tout détacher.
 */

let media

/** Un `MediaQueryList` pilotable, dont on peut provoquer le changement. */
function installerMedia(matches) {
  const abonnes = new Set()

  media = {
    matches,
    addEventListener: (_, fn) => abonnes.add(fn),
    removeEventListener: (_, fn) => abonnes.delete(fn),
    /** Simule le passage d'un point de rupture. */
    basculer(valeur) {
      media.matches = valeur
      abonnes.forEach((fn) => fn({ matches: valeur }))
    },
  }

  window.matchMedia = () => media
}

const Harnais = defineComponent({
  props: {
    isOpen: { type: Function, required: true },
    setOpen: { type: Function, required: true },
  },
  setup(props) {
    const tiroir = ref(null)

    useDrawerGestures(tiroir, { isOpen: props.isOpen, setOpen: props.setOpen })

    return { tiroir }
  },
  template: '<aside ref="tiroir" />',
})

/**
 * Les événements de pointeur n'existent pas dans jsdom : un MouseEvent porte
 * clientX/clientY et timeStamp, et l'on y ajoute « pointerType », seul champ
 * supplémentaire que le composable consulte.
 */
function toucher(type, { x = 0, y = 0, at, pointerType = 'touch' } = {}) {
  const event = new MouseEvent(type, { clientX: x, clientY: y, bubbles: true })

  Object.defineProperty(event, 'pointerType', { value: pointerType })

  if (at !== undefined) Object.defineProperty(event, 'timeStamp', { value: at })

  document.dispatchEvent(event)
}

enableAutoUnmount(afterEach)

beforeEach(() => {
  installerMedia(true)
})

describe('useDrawerGestures', () => {
  const monter = (ouvert = false) => {
    const setOpen = vi.fn()
    const wrapper = mount(Harnais, { props: { isOpen: () => ouvert, setOpen } })

    return { wrapper, setOpen, tiroir: wrapper.vm.tiroir }
  }

  // ──────────────────────────────────────────────────────────── l'ouverture

  it('un glissement depuis le bord ouvre le tiroir', () => {
    const { setOpen } = monter(false)

    toucher('pointerdown', { x: 10, y: 300 })
    toucher('pointermove', { x: 60, y: 302 })

    expect(setOpen).toHaveBeenCalledWith(true)
  })

  it('le même glissement commencé hors du bord n’ouvre rien', () => {
    const { setOpen } = monter(false)

    // Ailleurs qu'au bord, l'utilisateur fait défiler ou navigue. Une bande
    // de 28 px est ce qui sépare les deux intentions.
    toucher('pointerdown', { x: 200, y: 300 })
    toucher('pointermove', { x: 250, y: 302 })

    expect(setOpen).not.toHaveBeenCalled()
  })

  it('un glissement trop court depuis le bord n’ouvre pas', () => {
    const { setOpen } = monter(false)

    toucher('pointerdown', { x: 10, y: 300 })
    toucher('pointermove', { x: 40, y: 302 })
    toucher('pointerup', { x: 40, y: 302 })

    expect(setOpen).not.toHaveBeenCalled()
  })

  // ─────────────────────────────────────────────────────────── la fermeture

  it('tiré vers la gauche, le tiroir suit le doigt', () => {
    const { tiroir } = monter(true)

    toucher('pointerdown', { x: 200, y: 300 })
    toucher('pointermove', { x: 150, y: 302 })

    expect(tiroir.style.transform).toBe('translateX(-50px)')
  })

  it('ne se laisse pas tirer vers la droite', () => {
    const { tiroir } = monter(true)

    toucher('pointerdown', { x: 100, y: 300 })
    toucher('pointermove', { x: 180, y: 302 })

    // Un tiroir ouvert est déjà contre son bord : l'étirer vers la droite
    // n'aurait aucun sens et laisserait un trou derrière lui.
    expect(tiroir.style.transform).toBe('translateX(0px)')
  })

  it('se ferme quand on l’a suffisamment tiré', () => {
    const { setOpen } = monter(true)

    toucher('pointerdown', { x: 200, y: 300, at: 0 })
    toucher('pointermove', { x: 100, y: 302, at: 400 })
    toucher('pointerup', { x: 100, y: 302, at: 400 })

    expect(setOpen).toHaveBeenCalledWith(false)
  })

  it('se ferme aussi sur un geste bref et vif', () => {
    const { setOpen } = monter(true)

    // 40 px en 30 ms : loin des 70 px exigés, mais l'intention ne fait aucun
    // doute. Sans ce cas, il faudrait accompagner le tiroir jusqu'au bout,
    // ce que personne ne fait sur un téléphone.
    toucher('pointerdown', { x: 200, y: 300, at: 0 })
    toucher('pointermove', { x: 160, y: 302, at: 30 })
    toucher('pointerup', { x: 160, y: 302, at: 30 })

    expect(setOpen).toHaveBeenCalledWith(false)
  })

  it('ne se ferme pas sur un geste court et lent', () => {
    const { setOpen } = monter(true)

    // Même distance que ci-dessus, mais en une seconde : c'est une hésitation,
    // pas une intention.
    toucher('pointerdown', { x: 200, y: 300, at: 0 })
    toucher('pointermove', { x: 160, y: 302, at: 1000 })
    toucher('pointerup', { x: 160, y: 302, at: 1000 })

    expect(setOpen).not.toHaveBeenCalled()
  })

  it('rend sa position aux classes CSS à la fin du geste', () => {
    const { tiroir } = monter(true)

    toucher('pointerdown', { x: 200, y: 300, at: 0 })
    toucher('pointermove', { x: 180, y: 302, at: 400 })
    expect(tiroir.style.transform).not.toBe('')

    toucher('pointerup', { x: 180, y: 302, at: 400 })

    // Deux sources de vérité pour une même position, c'est la garantie d'un
    // tiroir coincé un jour.
    expect(tiroir.style.transform).toBe('')
    expect(tiroir.style.transition).toBe('')
  })

  // ────────────────────────────────────────────────────────── le verrou d'axe

  it('un défilement vertical oblique ne touche pas au tiroir', () => {
    const { tiroir, setOpen } = monter(true)

    // 20 px de côté pour 80 px vers le bas : c'est un défilement. Sans le
    // verrou, le tiroir suivrait le pouce à chaque lecture de liste.
    toucher('pointerdown', { x: 200, y: 100 })
    toucher('pointermove', { x: 180, y: 180 })

    expect(tiroir.style.transform).toBe('')
    expect(setOpen).not.toHaveBeenCalled()
  })

  it('un frémissement horizontal ne déclenche rien non plus', () => {
    const { tiroir } = monter(true)

    toucher('pointerdown', { x: 200, y: 300 })
    toucher('pointermove', { x: 195, y: 300 })

    expect(tiroir.style.transform).toBe('')
  })

  // ────────────────────────────────────────────────── ce qui n'est pas visé

  it('ignore la souris', () => {
    const { setOpen, tiroir } = monter(true)

    // Sur un écran large il n'y a pas de tiroir, et sur un écran tactile la
    // souris n'est pas le geste visé. Un glissement à la souris sélectionne
    // du texte, il ne ferme pas un menu.
    toucher('pointerdown', { x: 200, y: 300, pointerType: 'mouse' })
    toucher('pointermove', { x: 80, y: 302, pointerType: 'mouse' })
    toucher('pointerup', { x: 80, y: 302, pointerType: 'mouse' })

    expect(setOpen).not.toHaveBeenCalled()
    expect(tiroir.style.transform).toBe('')
  })

  it('ne s’installe pas du tout sur un écran large', () => {
    installerMedia(false)

    const { setOpen } = monter(false)

    toucher('pointerdown', { x: 10, y: 300 })
    toucher('pointermove', { x: 60, y: 302 })

    expect(setOpen).not.toHaveBeenCalled()
  })

  it('se détache en passant du mobile au bureau, et revient', () => {
    const { setOpen } = monter(false)

    media.basculer(false)

    toucher('pointerdown', { x: 10, y: 300 })
    toucher('pointermove', { x: 60, y: 302 })
    expect(setOpen).not.toHaveBeenCalled()

    // Une fenêtre qu'on redimensionne dans les deux sens : le tiroir doit
    // redevenir gesticulable, pas rester mort jusqu'au rechargement.
    media.basculer(true)

    toucher('pointerdown', { x: 10, y: 300 })
    toucher('pointermove', { x: 60, y: 302 })

    expect(setOpen).toHaveBeenCalledWith(true)
  })

  it('ne laisse aucun écouteur après le démontage', () => {
    const { wrapper, setOpen } = monter(false)

    wrapper.unmount()

    toucher('pointerdown', { x: 10, y: 300 })
    toucher('pointermove', { x: 60, y: 302 })

    expect(setOpen).not.toHaveBeenCalled()
  })
})
