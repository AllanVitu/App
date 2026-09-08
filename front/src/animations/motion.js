/**
 * ---------------------------------------------------------------------------
 * Point d'entrée unique du mouvement — TOUT le front
 *
 * Il y avait ici deux moteurs et une frontière à tenir : anime.js pour les
 * écrans publics, GSAP pour l'application. La frontière coûtait cher — un
 * seul composant partagé important GSAP suffisait à le ramener dans le
 * chemin public — et surtout elle n'était plus justifiée : anime.js 4.5
 * couvre nativement les quatre plugins pour lesquels GSAP avait été retenu.
 *
 *   Flip           -> createLayout   (isolé dans animations/layout.js, voir là-bas)
 *   MorphSVGPlugin -> morphTo
 *   ScrollTrigger  -> IntersectionObserver, natif du navigateur
 *   Draggable      -> événements pointeur, natifs eux aussi
 *
 * Les deux derniers ne passent volontairement par AUCUNE bibliothèque : un
 * observateur d'intersection ne travaille que lorsqu'un élément traverse le
 * bord de l'écran, là où ScrollTrigger recalcule à chaque défilement. Pour
 * « révéler des lignes en approchant » c'est à la fois plus léger et plus
 * juste.
 *
 * Résultat : un seul moteur, ~17 Ko compressés au lieu de 92, et plus de
 * règle à tenir dans la tête.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  PIÈGE — anime.js compte en MILLISECONDES, GSAP comptait en         │
 * │  SECONDES. « duration: 0.5 » dure une demi-milliseconde ici :       │
 * │  invisible, et sans erreur pour le signaler. D'où les durées        │
 * │  nommées ci-dessous, qu'on utilise plutôt que des nombres écrits    │
 * │  à la main dans les composants.                                     │
 * └─────────────────────────────────────────────────────────────────────┘
 * ---------------------------------------------------------------------------
 */

import {
  animate,
  createScope,
  createSpring,
  createTimeline,
  cubicBezier,
  createDrawable,
  morphTo,
  stagger,
  utils,
} from 'animejs'

// -----------------------------------------------------------------------------
// Vocabulaire d'animation partagé
// -----------------------------------------------------------------------------

/** Sortie franche puis freinage doux — mouvement d'entrée par défaut. */
export const appEnter = cubicBezier(0.16, 1, 0.3, 1)

/** Accélération nette pour les sorties : rien ne doit traîner à l'écran. */
export const appExit = cubicBezier(0.7, 0, 0.84, 0)

/** Léger dépassement, pour les éléments qui « arrivent ». */
export const appOvershoot = cubicBezier(0.34, 1.56, 0.64, 1)

/**
 * Rebond discret : validation, coche de succès.
 *
 * Un ressort et non une courbe de Bézier : un rebond a besoin de dépasser
 * puis de revenir plusieurs fois, ce qu'une courbe à quatre points ne sait
 * pas faire.
 */
export const appBounce = createSpring({ stiffness: 120, damping: 11 })

/**
 * Durées, en millisecondes.
 *
 * Aucune ne dépasse 700 ms : une entrée qu'on attend est une entrée ratée.
 */
export const DURATION = {
  /** Micro-retour : bascule, pastille. */
  quick: 220,
  /** Apparition d'un élément secondaire. */
  base: 500,
  /** Geste principal d'un écran — le seul qu'on ait le droit d'appuyer. */
  feature: 620,
}

/** Décalage entre deux éléments d'une même série. */
export const STAGGER = {
  /** Lettres d'un mot : au-delà, le mot se lit comme une file d'attente. */
  letters: 28,
  /** Blocs empilés (champs, lignes). */
  blocks: 55,
}

// -----------------------------------------------------------------------------
// Mouvement réduit
// -----------------------------------------------------------------------------

/**
 * Respect du réglage système « réduire les animations ».
 *
 * Les animations d'ici partent d'un état explicite `[départ, arrivée]`. Ne
 * rien jouer laisserait donc les éléments dans leur état de DÉPART,
 * c'est-à-dire invisibles. Chaque écran doit POSER son état final : c'est le
 * rôle de `settle()`. C'est la différence essentielle avec l'ancien code
 * GSAP, qui n'utilisait que des tweens `from()`.
 */
export const prefersReducedMotion = () =>
  window.matchMedia('(prefers-reduced-motion: reduce)').matches

/**
 * Pose immédiatement l'état final sur des éléments, sans transition.
 *
 * Utilisé quand le mouvement est refusé, et comme filet : un écran doit être
 * complet même si son animation ne se joue jamais.
 */
export function settle(targets) {
  utils.set(targets, { opacity: 1, y: 0, x: 0, scale: 1 })
}

/**
 * Durée effective : nulle si l'utilisateur a demandé la réduction des
 * animations. Conserve la mécanique (rappels de fin) sans le mouvement.
 */
export const motionDuration = (ms) => (prefersReducedMotion() ? 0 : ms)

// -----------------------------------------------------------------------------
// Gestes partagés
// -----------------------------------------------------------------------------

/**
 * Secousse latérale : identifiants refusés, formulaire invalide.
 *
 * Le mouvement précède la lecture du message — on sait qu'on a échoué avant
 * même d'avoir lu pourquoi.
 *
 * Une secousse EST une suite de positions : l'écrire ainsi la rend lisible
 * et réglable, au lieu de la cacher derrière un nom de courbe.
 *
 * Ne fait rien en mouvement réduit : contrairement aux entrées, il n'y a pas
 * d'état final à poser — l'élément est déjà en place.
 */
export function shake(target) {
  if (!target || prefersReducedMotion()) return

  animate(target, {
    // Amplitude décroissante : la secousse s'éteint d'elle-même.
    translateX: [0, -9, 7, -5, 3, -1, 0],
    duration: 480,
    ease: 'linear',
  })
}

// -----------------------------------------------------------------------------

// Les fonctions SVG sont exportées UNE À UNE, et non par leur objet « svg ».
// Importer le namespace empêche l'élagage : SuccessBurst n'a besoin que de
// createDrawable, mais tirait aussi morphTo et createMotionPath — donc
// l'écran de connexion les chargeait.
export { animate, createDrawable, createScope, createTimeline, morphTo, stagger, utils }
