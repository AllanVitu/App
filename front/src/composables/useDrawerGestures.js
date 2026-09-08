import { onBeforeUnmount, onMounted } from 'vue'

/**
 * Gestes tactiles du tiroir latéral (mobile uniquement).
 *
 * Remplace le couple Draggable + Observer de GSAP, qui tirait aussi
 * InertiaPlugin : trois plugins parmi les plus lourds de la bibliothèque,
 * pour deux gestes. Les événements pointeur du navigateur les font tenir en
 * une soixantaine de lignes.
 *
 * TROIS RÈGLES, qui expliquent la forme du code.
 *
 * 1. TOUS LES ÉCOUTEURS SONT PASSIFS. Aucun n'appelle preventDefault, donc
 *    le navigateur n'a jamais à attendre notre réponse pour décider s'il
 *    peut faire défiler la page. C'est ce qui distingue un geste fluide
 *    d'un geste qui accroche.
 *
 * 2. LE GESTE HORIZONTAL DOIT SE DÉCLARER. Tant qu'on n'a pas dépassé
 *    8 px ET constaté que le mouvement est plus horizontal que vertical, on
 *    ne touche à rien : sans cela, un défilement vertical un peu oblique
 *    ferait glisser le tiroir.
 *
 * 3. RIEN N'EST ÉCRIT EN DEHORS DE « transform ». La position du tiroir
 *    appartient aux classes CSS ; on n'emprunte le style en ligne que le
 *    temps du geste, et on le rend à la fin. Deux sources de vérité pour une
 *    même position, c'est la garantie d'un tiroir coincé un jour.
 *
 * Si ce code ne s'exécute pas — souris, écran large, navigateur ancien — le
 * menu reste pilotable au bouton. Les gestes ne sont qu'une couche de plus.
 *
 * @param {import('vue').Ref<HTMLElement|null>} element  le tiroir
 * @param {{ isOpen: () => boolean, setOpen: (open: boolean) => void }} api
 */
export function useDrawerGestures(element, { isOpen, setOpen }) {
  /** Distance parcourue vers la gauche au-delà de laquelle on ferme. */
  const CLOSE_DISTANCE = 70
  /** Bande, au bord gauche de l'écran, où un geste vers la droite ouvre. */
  const EDGE_WIDTH = 28
  /** Distance vers la droite à parcourir depuis le bord pour ouvrir. */
  const OPEN_DISTANCE = 40
  /** Seuil à partir duquel un geste est reconnu comme horizontal. */
  const AXIS_LOCK = 8

  const query = window.matchMedia('(max-width: 1023px)')

  let startX = 0
  let startY = 0
  let startTime = 0
  let dragging = false
  let fromEdge = false
  let active = false

  const setTransform = (value) => {
    const node = element.value

    if (node) node.style.transform = value
  }

  /** Rend la position aux classes CSS, seule source de vérité. */
  const release = () => {
    const node = element.value

    if (node) {
      node.style.transform = ''
      node.style.transition = ''
    }
  }

  function onPointerDown(event) {
    if (event.pointerType === 'mouse') return

    startX = event.clientX
    startY = event.clientY
    startTime = event.timeStamp
    dragging = false
    // Un geste amorcé hors de la bande de bord ne peut pas ouvrir : ailleurs,
    // l'utilisateur fait défiler ou navigue.
    fromEdge = !isOpen() && startX < EDGE_WIDTH
    active = fromEdge || isOpen()
  }

  function onPointerMove(event) {
    if (!active) return

    const dx = event.clientX - startX
    const dy = event.clientY - startY

    if (!dragging) {
      if (Math.abs(dx) < AXIS_LOCK || Math.abs(dx) <= Math.abs(dy)) return

      dragging = true

      // Le tiroir suit le doigt : la transition CSS ferait traîner chaque
      // image d'un cran derrière lui.
      if (isOpen() && element.value) element.value.style.transition = 'none'
    }

    if (fromEdge) {
      if (dx > OPEN_DISTANCE) {
        setOpen(true)
        active = false
      }

      return
    }

    // Vers la gauche uniquement : tirer un tiroir ouvert vers la droite
    // n'aurait aucun sens, il est déjà contre son bord.
    setTransform(`translateX(${Math.min(0, dx)}px)`)
  }

  function onPointerUp(event) {
    if (!active) {
      release()

      return
    }

    const dx = event.clientX - startX

    if (dragging && !fromEdge) {
      // Fermeture si le tiroir a été suffisamment tiré OU lancé vivement :
      // le geste rapide doit suffire, sans exiger d'aller au bout.
      const elapsed = Math.max(1, event.timeStamp - startTime)
      const flung = dx < -20 && Math.abs(dx) / elapsed > 0.5

      if (dx < -CLOSE_DISTANCE || flung) setOpen(false)
    }

    dragging = false
    active = false
    release()
  }

  const options = { passive: true }

  function attach() {
    document.addEventListener('pointerdown', onPointerDown, options)
    document.addEventListener('pointermove', onPointerMove, options)
    document.addEventListener('pointerup', onPointerUp, options)
    document.addEventListener('pointercancel', onPointerUp, options)
  }

  function detach() {
    document.removeEventListener('pointerdown', onPointerDown, options)
    document.removeEventListener('pointermove', onPointerMove, options)
    document.removeEventListener('pointerup', onPointerUp, options)
    document.removeEventListener('pointercancel', onPointerUp, options)
    release()
  }

  /** Passage bureau <-> mobile : au-delà de 1023 px il n'y a plus de tiroir. */
  const onQueryChange = (media) => (media.matches ? attach() : detach())
  const listener = (event) => onQueryChange(event)

  onMounted(() => {
    onQueryChange(query)
    query.addEventListener('change', listener)
  })

  onBeforeUnmount(() => {
    query.removeEventListener('change', listener)
    detach()
  })
}
