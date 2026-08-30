/**
 * ---------------------------------------------------------------------------
 * Point d'entrée unique de GSAP — moitié APPLICATION du front
 *
 * Le front utilise deux moteurs d'animation, et la frontière est stricte :
 *
 *   AppLayout   (tableau de bord, modules, profil, paramètres)  -> GSAP
 *   AuthLayout  (connexion, inscription, mot de passe oublié…)  -> anime.js
 *
 * Ce module N'EST PLUS importé par main.js. Il l'était, pour enregistrer les
 * plugins une fois pour toutes — mais l'effet de bord était de placer GSAP
 * dans le morceau d'entrée, donc de le faire charger par toutes les pages, y
 * compris l'écran de connexion. Il s'auto-enregistre à son premier import,
 * quel qu'il soit : le compilateur le range dans un morceau à part, chargé
 * seulement avec les composants qui en ont besoin.
 *
 * RÈGLE À TENIR : aucun composant chargé par les DEUX mises en page ne doit
 * importer ce module. C'est pourquoi ToastHost — monté dans App.vue — utilise
 * anime.js. Un test de bout en bout garde cet invariant (animation.spec.js).
 *
 * Tous les plugins sont enregistrés ICI, une seule fois : impossible
 * d'oublier un registerPlugin, et l'inventaire de ce qui est chargé reste
 * lisible à un seul endroit.
 *
 * Note : depuis GSAP 3.13, l'intégralité des plugins autrefois réservés au
 * Club GreenSock (SplitText, MorphSVG, DrawSVG, ScrollSmoother, Inertia…)
 * est distribuée sous licence gratuite dans le paquet `gsap`.
 * ---------------------------------------------------------------------------
 */

import { gsap } from 'gsap'

// --- Eases -------------------------------------------------------------------
import { CustomEase } from 'gsap/CustomEase'
import { CustomWiggle } from 'gsap/CustomWiggle' // dépend de CustomEase

// --- Plugins d'exécution -----------------------------------------------------
//
// Seuls les plugins RÉELLEMENT utilisés sont importés : chacun est du code
// expédié au navigateur. Enregistrer l'ensemble du catalogue « au cas où »
// double le poids de l'application (mesuré : ~106 Ko gzip contre ~40 Ko).
//
// Les absents volontaires, et leur raison :
//   ScrollSmoother  — détourne le défilement natif, néfaste sur des listes
//   MotionPathPlugin — aucun trajet courbe justifié ici
//   PixiPlugin / EaselPlugin — ponts vers des bibliothèques absentes du projet
//   PhysicsPropsPlugin — redondant avec InertiaPlugin
//
// SIX plugins sont partis avec le partage GSAP / anime.js — non par arbitrage,
// mais parce que leurs seuls utilisateurs étaient dans la moitié publique et
// tournent maintenant sous anime.js :
//   SplitText        — logotype de AuthLayout        -> utils/text.js
//   DrawSVGPlugin    — tracé de SuccessBurst          -> svg.createDrawable
//   Physics2DPlugin  — gerbe de SuccessBurst          -> x = vx·t, y = vy·t + ½gt²
//   CustomBounce     — rebond de SuccessBurst         -> createSpring
//   ScrambleTextPlugin — prénom du tableau de bord    -> retiré (décoratif)
//   EasePack         — courbe « slow » des compteurs  -> compteurs retirés
//
// Réactiver l'un d'eux = une ligne d'import et une entrée dans registerPlugin.
import { Draggable } from 'gsap/Draggable'
import { Flip } from 'gsap/Flip'
import { InertiaPlugin } from 'gsap/InertiaPlugin'
import { MorphSVGPlugin } from 'gsap/MorphSVGPlugin'
import { Observer } from 'gsap/Observer'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { ScrollToPlugin } from 'gsap/ScrollToPlugin'

gsap.registerPlugin(
  CustomEase,
  CustomWiggle,
  Draggable,
  Flip,
  InertiaPlugin,
  MorphSVGPlugin,
  Observer,
  ScrollTrigger,
  ScrollToPlugin,
)

/**
 * Outils de mise au point (GSDevTools, MotionPathHelper) : chargés
 * UNIQUEMENT en développement. Ils embarquent leur propre interface, inutile
 * de l'expédier aux utilisateurs.
 *
 * En console : `window.GSDevTools.create()` pour piloter la timeline globale.
 */
if (import.meta.env.DEV) {
  Promise.all([import('gsap/GSDevTools'), import('gsap/MotionPathHelper')])
    .then(([{ GSDevTools }, { MotionPathHelper }]) => {
      gsap.registerPlugin(GSDevTools, MotionPathHelper)
      window.GSDevTools = GSDevTools
      window.MotionPathHelper = MotionPathHelper
      window.gsap = gsap
    })
    .catch(() => {
      /* outils de dev indisponibles : sans conséquence */
    })
}

// -----------------------------------------------------------------------------
// Vocabulaire d'animation partagé
//
// Définir les courbes une fois donne une signature homogène à l'interface :
// deux écrans différents « bougent » de la même façon.
// -----------------------------------------------------------------------------

/** Sortie franche puis freinage doux — mouvement d'entrée par défaut. */
CustomEase.create('appEnter', '0.16, 1, 0.3, 1')

/** Accélération nette pour les sorties : rien ne doit traîner à l'écran. */
CustomEase.create('appExit', '0.7, 0, 0.84, 0')

/** Léger dépassement, pour les éléments qui « arrivent » (modales, pastilles). */
CustomEase.create('appOvershoot', '0.34, 1.56, 0.64, 1')

// « appBounce » vivait ici. Son unique usage — la coche de SuccessBurst —
// appartient à la moitié publique : il est devenu un ressort anime.js
// (cf. animations/anime.js). Le garder aurait maintenu CustomBounce dans le
// lot GSAP pour personne.

/** Secousse latérale : champ de formulaire refusé. */
CustomWiggle.create('appShake', { wiggles: 6, type: 'easeOut' })

// -----------------------------------------------------------------------------
// Réglages globaux
// -----------------------------------------------------------------------------

gsap.defaults({
  duration: 0.5,
  ease: 'appEnter',
})

/**
 * Respect du réglage système « réduire les animations ».
 *
 * Règle de conception qui en découle : on n'utilise QUE des tweens `from()`
 * et on ne masque jamais un élément en CSS. Ne pas jouer l'animation laisse
 * donc l'interface dans son état final correct, sans code de secours.
 */
export const prefersReducedMotion = () =>
  window.matchMedia('(prefers-reduced-motion: reduce)').matches

/**
 * Durée effective d'une animation : nulle si l'utilisateur a demandé la
 * réduction des animations. Pratique pour les transitions dont on veut
 * conserver la mécanique (callbacks) sans le mouvement.
 */
export const motionDuration = (seconds) => (prefersReducedMotion() ? 0 : seconds)

export {
  gsap,
  CustomEase,
  CustomWiggle,
  Draggable,
  Flip,
  InertiaPlugin,
  MorphSVGPlugin,
  Observer,
  ScrollTrigger,
  ScrollToPlugin,
}
