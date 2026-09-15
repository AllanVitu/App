import { createLayout } from 'animejs'

/**
 * Animation de MISE EN PAGE (technique dite « FLIP ») — remplace le plugin
 * Flip de GSAP.
 *
 * Le principe : mesurer où sont les éléments AVANT le changement, laisser le
 * navigateur faire la nouvelle mise en page, mesurer de nouveau, puis les
 * faire glisser de l'ancienne position vers la nouvelle. Sans cela, une ligne
 * qui change de groupe disparaît d'un endroit et réapparaît ailleurs : rien
 * ne dit que c'est la même.
 *
 *   const layout = createLayout(racine, { children: '[data-ligne]' })
 *   layout.record()          // avant
 *   await nextTick()         // Vue applique le changement
 *   layout.animate({ … })    // après
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  POURQUOI CE MODULE EXISTE SÉPARÉMENT DE « motion.js »              │
 * │                                                                     │
 * │  « motion.js » est importé par ToastHost, lui-même monté dans       │
 * │  App.vue : il est donc dans le lot d'ENTRÉE, chargé par toutes les  │
 * │  pages, écran de connexion compris.                                 │
 * │                                                                     │
 * │  Ce qui sort réellement createLayout du chemin public, c'est la     │
 * │  règle « manualChunks » de vite.config.js (mesuré : le lot n'y      │
 * │  apparaît plus). Le ré-export depuis motion.js, lui, était bien     │
 * │  élagué — vérifié en le remettant.                                  │
 * │                                                                     │
 * │  Le module reste néanmoins séparé, parce qu'il dit à la lecture ce  │
 * │  que le fichier de configuration dit à la compilation : ceci ne     │
 * │  sert qu'à deux vues. Deux endroits d'accord valent mieux qu'un     │
 * │  découpage dont la raison n'est écrite nulle part dans le code.     │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Les durées sont en MILLISECONDES, comme partout depuis le passage à
 * anime.js — le piège nº 1 quand on relit du code écrit pour GSAP.
 */
export { createLayout }
