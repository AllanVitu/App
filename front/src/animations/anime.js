/**
 * ---------------------------------------------------------------------------
 * Point d'entrée unique d'anime.js — moitié PUBLIQUE du front
 *
 * L'application utilise deux moteurs d'animation, et la frontière est stricte :
 *
 *   AuthLayout  (connexion, inscription, mot de passe oublié…)  -> anime.js
 *   AppLayout   (tableau de bord, modules, profil, paramètres)  -> GSAP
 *
 * Ce n'est pas une préférence de style. Les écrans publics n'ont besoin que
 * d'entrées simples ; anime.js les fait pour environ 8 Ko compressés, là où
 * GSAP en coûte 92. L'écran de connexion — celui que voit un visiteur pas
 * encore identifié, souvent sur un réseau qu'il n'a pas choisi — passe ainsi
 * de 172 à ~88 Ko.
 *
 * RÈGLE À TENIR : aucun composant chargé par les DEUX mises en page ne doit
 * importer « @/animations/gsap ». Un seul suffirait à ramener GSAP dans le
 * chemin public et à annuler tout le bénéfice. C'est pour cette raison que
 * ToastHost et SuccessBurst sont ici et non chez GSAP.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  PIÈGE Nº 1 — anime.js compte en MILLISECONDES, GSAP en SECONDES.   │
 * │  « duration: 0.5 » dure une demi-milliseconde chez anime.js :       │
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
  stagger,
  svg,
  utils,
} from 'animejs'

// -----------------------------------------------------------------------------
// Vocabulaire d'animation partagé
//
// Les MÊMES courbes que celles définies pour GSAP (cf. animations/gsap.js) :
// les deux moitiés du front doivent « bouger » de la même façon, sinon le
// passage de l'écran de connexion au tableau de bord se remarque.
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
 * Un ressort et non une courbe de Bézier, comme côté GSAP (CustomBounce) : un
 * rebond a besoin de dépasser puis de revenir plusieurs fois, ce qu'une
 * courbe à quatre points ne sait pas faire.
 */
export const appBounce = createSpring({ stiffness: 120, damping: 11 })

/**
 * Durées, en millisecondes.
 *
 * Aucune ne dépasse 700 ms : une entrée qu'on attend est une entrée ratée.
 * On vient se connecter, pas assister à un générique.
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
 * Contrairement à la moitié GSAP — qui n'utilise que des tweens `from()` et
 * peut donc simplement ne rien jouer — les animations d'ici partent d'un état
 * explicite `[départ, arrivée]`. Ne rien jouer laisserait donc les éléments
 * dans leur état de DÉPART, c'est-à-dire invisibles. Chaque écran doit poser
 * son état final : c'est le rôle de `settle()`.
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
 * Côté GSAP, c'était une courbe « CustomWiggle ». anime.js n'a pas
 * d'équivalent, et n'en a pas besoin : une secousse EST une suite de
 * positions, l'écrire ainsi la rend lisible et réglable au lieu de la cacher
 * derrière un nom de courbe.
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

export { animate, createScope, createTimeline, stagger, svg, utils }
