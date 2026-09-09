import { onBeforeUnmount, ref, shallowRef } from 'vue'

/**
 * Glisser-déposer d'une carte d'une colonne à l'autre.
 *
 * Sans bibliothèque, et ce n'est pas de l'obstination : un glisser-déposer
 * est une boucle serrée entre le doigt et l'écran, et tout ce qui s'y insère
 * se voit. Les bibliothèques généralistes recalculent des positions à chaque
 * mouvement pour gérer des cas — listes imbriquées, défilement automatique,
 * réordonnancement au sein d'une colonne — que ce tableau n'a pas.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  LA RÈGLE QUI TIENT LA FLUIDITÉ : UNE SEULE LECTURE DE MISE EN PAGE │
 * │                                                                     │
 * │  Les rectangles des colonnes sont mesurés UNE FOIS, au moment où le │
 * │  glissement COMMENCE vraiment — et non à l'appui : entre les deux,  │
 * │  la page a pu bouger. Ensuite, chaque déplacement n'est plus que de │
 * │  l'arithmétique sur des nombres déjà en mémoire, et une seule       │
 * │  écriture — « transform » —, que le navigateur compose sans         │
 * │  recalculer la page.                                                │
 * │                                                                     │
 * │  Mesurer à chaque « pointermove » forcerait un recalcul complet de  │
 * │  la mise en page juste après que Vue vient d'écrire dedans. C'est   │
 * │  exactement le va-et-vient qui fait saccader un glissement.         │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Le défilement pendant le geste est empêché par « touch-action: none » posé
 * en CSS sur les cartes — pas par un preventDefault dans un écouteur, ce qui
 * obligerait le navigateur à attendre notre réponse avant chaque défilement.
 *
 * CE N'EST QU'UNE COUCHE. Le clavier reste le chemin principal du module
 * (touches de statut, curseur de sélection) : si ce code ne s'exécute pas,
 * rien n'est perdu.
 *
 * @param {{ onDrop: (id: string, column: string) => void }} options
 */
export function useBoardDrag({ onDrop }) {
  /** Identifiant de la carte en cours de déplacement, null au repos. */
  const draggingId = ref(null)
  /** Colonne actuellement survolée — sert à la mettre en évidence. */
  const overColumn = ref(null)

  /** Élément déplacé. `shallowRef` : un nœud DOM n'a pas à être réactif. */
  const card = shallowRef(null)

  /** Seuil au-delà duquel un appui devient un glissement. */
  const THRESHOLD = 6

  let startX = 0
  let startY = 0
  let origin = null
  /** Racine du tableau, retenue à l'appui et mesurée au début du glissement. */
  let rootEl = null
  let columns = []
  let armed = false
  /** Identifiant annoncé par l'appelant, retenu jusqu'au début du geste. */
  let pendingId = null

  /** Mesure les colonnes UNE fois. Voir l'encadré ci-dessus. */
  function measure(root) {
    columns = [...root.querySelectorAll('[data-column]')].map((element) => ({
      value: element.dataset.column,
      rect: element.getBoundingClientRect(),
    }))
  }

  /** Colonne sous le pointeur, par comparaison arithmétique pure. */
  function columnAt(x, y) {
    const found = columns.find(
      ({ rect }) => x >= rect.left && x <= rect.right && y >= rect.top && y <= rect.bottom,
    )

    return found?.value ?? null
  }

  function reset() {
    if (card.value) {
      card.value.style.transform = ''
      card.value.style.width = ''
      card.value.style.position = ''
      card.value.style.left = ''
      card.value.style.top = ''
      card.value.style.zIndex = ''
      card.value.style.pointerEvents = ''
    }

    card.value = null
    draggingId.value = null
    overColumn.value = null
    origin = null
    rootEl = null
    pendingId = null
    columns = []
    armed = false
  }

  /**
   * @param {PointerEvent} event
   * @param {string} id     identifiant de l'élément porté par la carte
   * @param {HTMLElement} root racine du tableau, qui contient les colonnes
   */
  function onPointerDown(event, id, root) {
    // Bouton principal seulement : un clic droit ouvre un menu contextuel.
    if (event.button !== 0 || !root) return

    // ┌───────────────────────────────────────────────────────────────────┐
    // │  UNE CARTE À LA FOIS                                              │
    // │                                                                   │
    // │  Deux doigts sur une tablette, ou une souris pendant un geste     │
    // │  tactile : sans cette garde, le second appui écrasait « card » et │
    // │  la PREMIÈRE carte restait en « position: fixed » pour toujours — │
    // │  plus rien ne la remettait en place, puisque « reset » ne connaît │
    // │  que la carte courante.                                           │
    // │                                                                   │
    // │  Trouvé en écrivant le test, pas en le corrigeant : c'est le      │
    // │  troisième défaut de ce fichier.                                  │
    // └───────────────────────────────────────────────────────────────────┘
    if (armed) return

    const element = event.currentTarget

    startX = event.clientX
    startY = event.clientY
    card.value = element
    pendingId = id
    armed = true
    draggingId.value = null
    // Retenue pour mesurer plus tard : la mesure n'a lieu qu'au début RÉEL du
    // glissement, voir onPointerMove.
    rootEl = root

    element.setPointerCapture?.(event.pointerId)

    window.addEventListener('pointermove', onPointerMove)
    window.addEventListener('pointerup', onPointerUp)
    window.addEventListener('pointercancel', onPointerUp)
  }

  function onPointerMove(event) {
    if (!armed || !card.value) return

    const dx = event.clientX - startX
    const dy = event.clientY - startY

    if (!draggingId.value) {
      if (Math.hypot(dx, dy) < THRESHOLD) return

      // ─────────────────────────────────────────────────────────────────
      // LA MESURE A LIEU ICI, pas à l'appui.
      //
      // Entre l'appui et le franchissement du seuil, la page a pu bouger :
      // ces écrans rafraîchissent leurs compteurs en arrière-plan, sans
      // attendre, et la réponse redessine l'en-tête quand elle arrive. Des
      // rectangles mesurés avant ce redessin désigneraient les mauvaises
      // colonnes pendant tout le reste du geste.
      //
      // La règle « une seule lecture de mise en page » est intacte : c'est la
      // même lecture unique, simplement faite au dernier moment utile — quand
      // l'utilisateur a réellement engagé le glissement.
      // ─────────────────────────────────────────────────────────────────
      origin = card.value.getBoundingClientRect()
      measure(rootEl)

      // Le geste est reconnu : la carte quitte le flux pour suivre le doigt.
      // Sa largeur est figée, sans quoi elle s'effondrerait à zéro une fois
      // sortie de sa colonne.
      //
      // L'IDENTIFIANT VIENT DE L'APPELANT, pas du DOM. Il était jusqu'ici
      // relu dans « dataset.boardCard » — et le paramètre « id », pourtant
      // reçu et documenté, ne servait à rien. Outre le doublon, l'attribut
      // manquant donnait « undefined » : la condition ci-dessus restant
      // vraie, on remesurait à CHAQUE mouvement, ce qui défait précisément la
      // règle que ce fichier existe pour tenir.
      draggingId.value = pendingId
      card.value.style.position = 'fixed'
      card.value.style.left = `${origin.left}px`
      card.value.style.top = `${origin.top}px`
      card.value.style.width = `${origin.width}px`
      card.value.style.zIndex = '50'
      // La carte ne doit pas s'intercepter elle-même sous le pointeur.
      card.value.style.pointerEvents = 'none'
    }

    card.value.style.transform = `translate(${dx}px, ${dy}px)`
    overColumn.value = columnAt(event.clientX, event.clientY)
  }

  /**
   * Avale le clic qui suit un glissement.
   *
   * Le navigateur émet un « click » après « pointerup » dès que les deux
   * tombent sur le même élément. Sans ce filtre, une carte reposée près de
   * son point de départ est à la fois DÉPLACÉE et OUVERTE : on relâche, et le
   * panneau de détail s'affiche sans qu'on l'ait demandé.
   *
   * En phase de CAPTURE, donc avant que le clic n'atteigne la carte.
   *
   * ┌───────────────────────────────────────────────────────────────────┐
   * │  ET DÉSARMÉ AU TOUR DE BOUCLE SUIVANT, sans quoi il devient un    │
   * │  piège. Un glissement qui se termine AILLEURS que sur son point   │
   * │  de départ ne produit aucun clic : « once » ne se déclenche donc  │
   * │  jamais, l'écouteur reste en place, et c'est un clic parfaitement │
   * │  légitime — des minutes plus tard, à l'autre bout de l'écran —    │
   * │  qui se fait avaler sans que rien ne l'explique.                  │
   * │                                                                   │
   * │  Le clic de synthèse, lui, arrive dans la même tâche que le       │
   * │  relâchement : un « setTimeout » à zéro passe forcément après.    │
   * └───────────────────────────────────────────────────────────────────┘
   */
  function swallowNextClick() {
    const avaler = (event) => {
      event.stopPropagation()
      event.preventDefault()
    }

    window.addEventListener('click', avaler, { capture: true, once: true })
    setTimeout(() => window.removeEventListener('click', avaler, { capture: true }), 0)
  }

  function onPointerUp() {
    window.removeEventListener('pointermove', onPointerMove)
    window.removeEventListener('pointerup', onPointerUp)
    window.removeEventListener('pointercancel', onPointerUp)

    const id = draggingId.value
    const column = overColumn.value

    reset()

    if (id) swallowNextClick()

    // Relâchée hors de toute colonne, la carte revient à sa place : c'est le
    // moyen d'annuler un geste commencé par erreur.
    if (id && column) onDrop(id, column)
  }

  onBeforeUnmount(() => {
    window.removeEventListener('pointermove', onPointerMove)
    window.removeEventListener('pointerup', onPointerUp)
    window.removeEventListener('pointercancel', onPointerUp)
  })

  return { draggingId, overColumn, onPointerDown }
}
