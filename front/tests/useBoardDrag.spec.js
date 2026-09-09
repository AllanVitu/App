import { defineComponent } from 'vue'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useBoardDrag } from '@/composables/useBoardDrag'

/**
 * Le glisser-déposer du tableau.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  219 LIGNES QUI N'ÉTAIENT ÉPROUVÉES QUE PAR UN PARCOURS             │
 * │                                                                     │
 * │  Un seul test Playwright les traversait — celui qui a flaké une     │
 * │  fois sur six. Or ce fichier a déjà livré DEUX vrais défauts, tous  │
 * │  deux trouvés par hasard en poursuivant ce flottement : les         │
 * │  colonnes mesurées à l'appui plutôt qu'au début du geste, et        │
 * │  l'avaleur de clic qui restait armé indéfiniment.                   │
 * │                                                                     │
 * │  Les deux corrections vivent aujourd'hui dans le code sans que rien │
 * │  ne les retienne. Ce sont les deux premiers tests écrits ici, et    │
 * │  les seuls qui tombent si on les défait.                            │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Les événements de pointeur n'existent pas dans jsdom. « onPointerDown » est
 * appelé DIRECTEMENT, comme le fait le composant, avec le strict nécessaire ;
 * les mouvements et le relâchement passent par de vrais événements sur
 * « window », puisque c'est là que le composable les écoute.
 */

const Harnais = defineComponent({
  props: { onDrop: { type: Function, required: true } },
  setup: (props) => useBoardDrag({ onDrop: props.onDrop }),
  template: '<div />',
})

/** jsdom ne calcule aucune géométrie : chaque rectangle est posé à la main. */
function poser(element, { left, top, width = 100, height = 200 }) {
  element.getBoundingClientRect = () => ({
    left,
    top,
    right: left + width,
    bottom: top + height,
    width,
    height,
    x: left,
    y: top,
  })
}

/** Un tableau à deux colonnes, et une carte posée dans la première. */
function tableau() {
  const root = document.createElement('div')
  root.innerHTML = '<div data-column="a-faire"></div><div data-column="en-cours"></div>'
  document.body.appendChild(root)

  const [gauche, droite] = root.querySelectorAll('[data-column]')
  poser(gauche, { left: 0, top: 0 })
  poser(droite, { left: 100, top: 0 })

  const carte = document.createElement('div')
  carte.dataset.boardCard = 'T-1'
  poser(carte, { left: 10, top: 10, width: 80, height: 40 })
  gauche.appendChild(carte)

  return { root, gauche, droite, carte }
}

/** L'appui, tel que le composant le transmet. */
const appuyer = (onPointerDown, carte, root, { x, y, button = 0 } = { x: 20, y: 20 }) =>
  onPointerDown(
    { button, clientX: x, clientY: y, pointerId: 1, currentTarget: carte },
    carte.dataset.boardCard,
    root,
  )

const bouger = (x, y) =>
  window.dispatchEvent(new MouseEvent('pointermove', { clientX: x, clientY: y }))
const relacher = () => window.dispatchEvent(new MouseEvent('pointerup'))

enableAutoUnmount(afterEach)

beforeEach(() => {
  document.body.innerHTML = ''
})

afterEach(() => {
  vi.useRealTimers()
})

describe('useBoardDrag', () => {
  const monter = (onDrop = vi.fn()) => {
    const wrapper = mount(Harnais, { props: { onDrop } })

    return { wrapper, vm: wrapper.vm, onDrop }
  }

  // ───────────────────────────────────────────── le seuil et le geste normal

  it('un déplacement sous le seuil reste un clic', () => {
    const { vm, onDrop } = monter()
    const { root, carte } = tableau()

    appuyer(vm.onPointerDown, carte, root, { x: 20, y: 20 })
    bouger(23, 22)
    relacher()

    // Six pixels séparent un doigt qui tremble d'un doigt qui déplace. En
    // dessous, la carte ne doit RIEN faire : ni se détacher, ni changer de
    // colonne au relâchement.
    expect(vm.draggingId).toBeNull()
    expect(carte.style.position).toBe('')
    expect(onDrop).not.toHaveBeenCalled()
  })

  it('au-delà du seuil, la carte se détache et suit le pointeur', () => {
    const { vm } = monter()
    const { root, carte } = tableau()

    appuyer(vm.onPointerDown, carte, root, { x: 20, y: 20 })
    bouger(40, 20)

    expect(vm.draggingId).toBe('T-1')

    // Sa largeur est figée : sortie de sa colonne, une carte en flux
    // s'effondrerait à zéro.
    expect(carte.style.position).toBe('fixed')
    expect(carte.style.width).toBe('80px')
    expect(carte.style.transform).toBe('translate(20px, 0px)')
  })

  it('déposée sur une colonne, elle annonce laquelle', () => {
    const { vm, onDrop } = monter()
    const { root, carte } = tableau()

    appuyer(vm.onPointerDown, carte, root, { x: 20, y: 20 })
    bouger(150, 50)
    relacher()

    expect(onDrop).toHaveBeenCalledWith('T-1', 'en-cours')
  })

  it('relâchée hors de toute colonne, elle ne change rien', () => {
    const { vm, onDrop } = monter()
    const { root, carte } = tableau()

    appuyer(vm.onPointerDown, carte, root, { x: 20, y: 20 })
    bouger(400, 400)
    relacher()

    // C'est le moyen d'annuler un geste commencé par erreur : on l'emmène
    // hors du tableau et on lâche.
    expect(onDrop).not.toHaveBeenCalled()
    expect(vm.draggingId).toBeNull()
  })

  it('rend ses styles à la carte une fois le geste fini', () => {
    const { vm } = monter()
    const { root, carte } = tableau()

    appuyer(vm.onPointerDown, carte, root, { x: 20, y: 20 })
    bouger(150, 50)
    relacher()

    // Une carte laissée en « position: fixed » flotterait au-dessus de
    // l'écran pour toujours.
    for (const propriete of ['position', 'left', 'top', 'width', 'transform', 'zIndex']) {
      expect(carte.style[propriete], propriete).toBe('')
    }
  })

  it('ignore le bouton droit', () => {
    const { vm, onDrop } = monter()
    const { root, carte } = tableau()

    // Un clic droit ouvre un menu contextuel : le tableau n'a pas à le
    // transformer en déplacement.
    appuyer(vm.onPointerDown, carte, root, { x: 20, y: 20, button: 2 })
    bouger(150, 50)
    relacher()

    expect(vm.draggingId).toBeNull()
    expect(onDrop).not.toHaveBeenCalled()
  })

  // ──────────────────────────────── le premier défaut trouvé, et sa garde

  it('mesure les colonnes AU DÉBUT DU GESTE, pas à l’appui', () => {
    const { vm, onDrop } = monter()
    const { root, carte, gauche, droite } = tableau()

    appuyer(vm.onPointerDown, carte, root, { x: 20, y: 20 })

    // ┌─────────────────────────────────────────────────────────────────┐
    // │  LA PAGE BOUGE ENTRE L'APPUI ET LE GESTE                        │
    // │                                                                 │
    // │  Ces écrans relisent leurs compteurs en arrière-plan ; la        │
    // │  réponse redessine l'en-tête quand elle arrive, et tout descend. │
    // │  Des rectangles mesurés à l'appui désigneraient alors les        │
    // │  mauvaises colonnes pendant TOUT le reste du geste.              │
    // │                                                                 │
    // │  Ici, les deux colonnes descendent de 300 px après l'appui. Le   │
    // │  point de dépôt (150, 350) n'est dans « en-cours » qu'après ce   │
    // │  déplacement.                                                    │
    // └─────────────────────────────────────────────────────────────────┘
    poser(gauche, { left: 0, top: 300 })
    poser(droite, { left: 100, top: 300 })

    bouger(40, 20)
    bouger(150, 350)
    relacher()

    expect(onDrop).toHaveBeenCalledWith('T-1', 'en-cours')
  })

  // ──────────────────────────────── le second défaut trouvé, et sa garde

  it('avale le clic de synthèse qui suit un glissement', () => {
    const { vm } = monter()
    const { root, carte } = tableau()

    appuyer(vm.onPointerDown, carte, root, { x: 20, y: 20 })
    bouger(150, 50)
    relacher()

    // Le navigateur émet un « click » après « pointerup » dès que les deux
    // tombent sur le même élément. Sans filtre, une carte reposée près de son
    // point de départ est à la fois DÉPLACÉE et OUVERTE.
    const synthese = new MouseEvent('click', { bubbles: true, cancelable: true })
    window.dispatchEvent(synthese)

    expect(synthese.defaultPrevented).toBe(true)
  })

  it('désarme l’avaleur au tour suivant, même si aucun clic n’est venu', () => {
    vi.useFakeTimers()

    const { vm } = monter()
    const { root, carte } = tableau()

    appuyer(vm.onPointerDown, carte, root, { x: 20, y: 20 })
    bouger(150, 50)
    relacher()

    // ┌─────────────────────────────────────────────────────────────────┐
    // │  LE PIÈGE, ET POURQUOI CE TEST NE DOIT PAS ENVOYER LE CLIC      │
    // │  DE SYNTHÈSE D'ABORD                                            │
    // │                                                                 │
    // │  Un geste qui finit AILLEURS que sur son point de départ ne     │
    // │  produit aucun clic. « once » ne se déclenche donc jamais,      │
    // │  l'écouteur reste posé, et c'est un clic parfaitement légitime  │
    // │  — des minutes plus tard, à l'autre bout de l'écran — qui se    │
    // │  fait avaler sans que rien ne l'explique.                       │
    // │                                                                 │
    // │  La première version de ce test envoyait le clic de synthèse    │
    // │  avant de vérifier le désarmement : « once » consommait alors   │
    // │  l'écouteur, et le test passait même en retirant la correction. │
    // │  Il ne prouvait rien. Ici, AUCUN clic ne suit le relâchement —  │
    // │  c'est le seul cas où le minuteur est le seul recours.          │
    // └─────────────────────────────────────────────────────────────────┘
    vi.advanceTimersByTime(1)

    const legitime = new MouseEvent('click', { bubbles: true, cancelable: true })
    window.dispatchEvent(legitime)

    expect(legitime.defaultPrevented).toBe(false)
  })

  it('n’avale rien quand aucun glissement n’a eu lieu', () => {
    const { vm } = monter()
    const { root, carte } = tableau()

    appuyer(vm.onPointerDown, carte, root, { x: 20, y: 20 })
    relacher()

    // Un simple clic sur une carte doit ouvrir son panneau : l'avaleur ne se
    // pose que si un déplacement a réellement commencé.
    const clic = new MouseEvent('click', { bubbles: true, cancelable: true })
    window.dispatchEvent(clic)

    expect(clic.defaultPrevented).toBe(false)
  })

  // ───────────────────────────────────────────────── ce qui ne doit pas fuir

  it('ne laisse aucun écouteur après le relâchement', () => {
    const { vm, onDrop } = monter()
    const { root, carte } = tableau()

    appuyer(vm.onPointerDown, carte, root, { x: 20, y: 20 })
    bouger(150, 50)
    relacher()

    // Un « pointermove » qui continuerait d'être écouté déplacerait une carte
    // que plus personne ne tient.
    bouger(50, 50)
    relacher()

    expect(onDrop).toHaveBeenCalledTimes(1)
    expect(carte.style.transform).toBe('')
  })

  it('ne laisse aucun écouteur après le démontage', () => {
    const { wrapper, vm, onDrop } = monter()
    const { root, carte } = tableau()

    appuyer(vm.onPointerDown, carte, root, { x: 20, y: 20 })
    wrapper.unmount()

    bouger(150, 50)
    relacher()

    expect(onDrop).not.toHaveBeenCalled()
  })

  it('ignore un second appui pendant qu’une carte est déjà tenue', () => {
    const { vm, onDrop } = monter()
    const { root, carte, droite } = tableau()

    const seconde = document.createElement('div')
    seconde.dataset.boardCard = 'T-2'
    poser(seconde, { left: 110, top: 10, width: 80, height: 40 })
    droite.appendChild(seconde)

    appuyer(vm.onPointerDown, carte, root, { x: 20, y: 20 })
    bouger(40, 20)

    // Deux doigts sur une tablette, ou une souris pendant un geste tactile.
    // Sans garde, le composable oublie la première carte pour la seconde : la
    // première reste alors en « position: fixed » POUR TOUJOURS, puisque plus
    // rien ne la remettra en place.
    appuyer(vm.onPointerDown, seconde, root, { x: 150, y: 20 })
    bouger(160, 60)
    relacher()

    expect(vm.draggingId).toBeNull()
    expect(seconde.style.position).toBe('')
    expect(carte.style.position).toBe('')
    expect(onDrop).toHaveBeenCalledWith('T-1', 'en-cours')
  })
})
