/**
 * ---------------------------------------------------------------------------
 * Point d'entrée unique de GSAP
 *
 * Tous les plugins sont enregistrés ICI, une seule fois. Les composants
 * importent `gsap` depuis ce module plutôt que depuis le paquet : impossible
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
import { CustomBounce } from 'gsap/CustomBounce' // dépend de CustomEase
import { CustomWiggle } from 'gsap/CustomWiggle' // dépend de CustomEase
import { RoughEase, ExpoScaleEase, SlowMo } from 'gsap/EasePack'

// --- Plugins d'exécution -----------------------------------------------------
//
// Seuls les plugins RÉELLEMENT utilisés sont importés : chacun est du code
// expédié au navigateur. Enregistrer l'ensemble du catalogue « au cas où »
// double le poids de l'application (mesuré : ~106 Ko gzip contre ~40 Ko).
//
// Les absents volontaires, et leur raison, sont documentés dans PLAN.md :
//   ScrollSmoother  — détourne le défilement natif, néfaste sur des listes
//   MotionPathPlugin — aucun trajet courbe justifié ici
//   PixiPlugin / EaselPlugin — ponts vers des bibliothèques absentes du projet
//   PhysicsPropsPlugin — redondant avec InertiaPlugin
//   TextPlugin — recouvert par ScrambleTextPlugin
// Réactiver l'un d'eux = une ligne d'import et une entrée dans registerPlugin.
import { Draggable } from 'gsap/Draggable'
import { DrawSVGPlugin } from 'gsap/DrawSVGPlugin'
import { Flip } from 'gsap/Flip'
import { InertiaPlugin } from 'gsap/InertiaPlugin'
import { MorphSVGPlugin } from 'gsap/MorphSVGPlugin'
import { Observer } from 'gsap/Observer'
import { Physics2DPlugin } from 'gsap/Physics2DPlugin'
import { ScrambleTextPlugin } from 'gsap/ScrambleTextPlugin'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { ScrollToPlugin } from 'gsap/ScrollToPlugin'
import { SplitText } from 'gsap/SplitText'

gsap.registerPlugin(
  CustomEase,
  CustomBounce,
  CustomWiggle,
  RoughEase,
  ExpoScaleEase,
  SlowMo,
  Draggable,
  DrawSVGPlugin,
  Flip,
  InertiaPlugin,
  MorphSVGPlugin,
  Observer,
  Physics2DPlugin,
  ScrambleTextPlugin,
  ScrollTrigger,
  ScrollToPlugin,
  SplitText,
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

/** Rebond discret : validation, coche de succès. */
CustomBounce.create('appBounce', { strength: 0.4, squash: 1.2 })

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
  CustomBounce,
  CustomWiggle,
  Draggable,
  DrawSVGPlugin,
  Flip,
  InertiaPlugin,
  MorphSVGPlugin,
  Observer,
  Physics2DPlugin,
  ScrambleTextPlugin,
  ScrollTrigger,
  ScrollToPlugin,
  SplitText,
}
