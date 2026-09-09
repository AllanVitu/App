import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { bindInterfaceSounds, play, setSuspended } from './services/sound'
import { useUiStore } from './stores/ui'

// Aucune bibliothèque d'animation n'est importée ici — et c'est délibéré.
//
// « @/animations/gsap » l'était, pour enregistrer ses plugins une fois pour
// toutes. L'effet de bord était que GSAP se retrouvait dans le morceau
// d'entrée, donc chargé par TOUTES les pages : l'écran de connexion payait
// 92 Ko compressés pour animer un logotype.
//
// GSAP a depuis été retiré du projet, mais la règle reste : un composant qui
// anime importe « @/animations/motion » lui-même, et le compilateur place le
// moteur dans un morceau à part, chargé avec ceux qui s'en servent.
import './assets/css/main.css'

const app = createApp(App)

app.use(createPinia())

// Le thème est appliqué avant le montage du routeur : les gardes de
// navigation peuvent déclencher un rendu, qui doit déjà être dans la
// bonne palette.
const ui = useUiStore()
ui.applyTheme()
ui.watchSystemTheme()

// Choix sonore d'une visite précédente. Le contexte audio, lui, ne démarrera
// qu'au premier geste — la politique des navigateurs l'impose.
ui.restoreSound()

// Survols et clics sonores, par délégation sur le document : un bouton
// ajouté demain sonnera sans qu'on ait à le déclarer.
bindInterfaceSounds()

// Les écrans publics sont muets : on n'accueille pas quelqu'un avec du son,
// et la préférence de l'utilisateur est conservée pendant la traversée.
router.afterEach((to, from) => {
  setSuspended(Boolean(to.meta.silent))

  // Changement de PAGE : une brève impulsion, comme un cran.
  //
  // Le chemin, et pas l'adresse entière. Les filtres de chaque écran vivent
  // désormais dans la query : sans cette distinction, chaque pause dans une
  // frappe de recherche déclencherait un « clac ».
  if (to.path !== from.path) play('tick')
})

/**
 * ─────────────────────────────────────────────────────────────────────────
 *  LES QUATRE ENDROITS OÙ UNE ERREUR PEUT DISPARAÎTRE
 * ─────────────────────────────────────────────────────────────────────────
 *
 * `errorHandler` ne couvre qu'un seul d'entre eux : le code exécuté par Vue
 * dans un composant. Les trois autres passaient dans la console, invisibles
 * pour qui n'a pas les outils de développement ouverts — c'est-à-dire tout
 * le monde. L'écran restait tel quel et l'action n'aboutissait pas, sans
 * qu'aucun message ne dise pourquoi.
 */

/**
 * Un seul message à la fois.
 *
 * Une erreur en produit rarement une : une requête qui échoue en boucle, ou
 * un rendu qui rejette à chaque image, empilerait autant de bandeaux qu'il y
 * a d'échecs et recouvrirait l'écran. Le premier suffit à prévenir ; les
 * suivants ne sont plus une information, seulement du bruit.
 */
let dernierSignalement = 0

function signaler(portee, erreur, message) {
  console.error(`[app] ${portee}`, erreur)

  const maintenant = Date.now()

  if (maintenant - dernierSignalement < 5000) return

  dernierSignalement = maintenant
  ui.notify(message, 'error')
}

// 1. Le code d'un composant. Sans ce filet, une exception démonte l'arbre et
//    laisse une page blanche, sans message ni moyen de revenir.
app.config.errorHandler = (error, instance, info) => {
  signaler(info, error, "Une erreur inattendue est survenue. L'action n'a pas abouti.")
}

// 2. Une promesse rejetée que personne n'attrape : un `await` oublié, une
//    écriture lancée sans `catch`. Le plus courant, et le plus silencieux.
window.addEventListener('unhandledrejection', (event) => {
  // Une requête annulée n'est pas un échec : elle a cédé la place à une plus
  // récente, et l'appelant qui l'ignore a raison de le faire.
  if (event.reason?.canceled) return

  signaler('promesse non traitée', event.reason, "L'action n'a pas abouti.")
})

// 3. Une exception hors de Vue — dans un écouteur d'événement posé à la main,
//    dans un `setTimeout`. `event.error` absent signale un échec de
//    chargement de ressource (une image manquante), pas une exception.
window.addEventListener('error', (event) => {
  if (!event.error) return

  signaler('exception', event.error, 'Une erreur inattendue est survenue.')
})

/**
 * 4. Un écran qui ne se charge plus.
 *
 * Toutes les vues sont chargées en différé. Après un déploiement, les
 * morceaux de code portent de nouveaux noms ; un onglet resté ouvert demande
 * encore les anciens, qui n'existent plus. La navigation échoue en silence :
 * l'utilisateur clique, et RIEN ne se passe — il reste sur la page
 * précédente, sans erreur, sans explication, et recommence.
 *
 * Un rechargement résout le cas, et lui seul : le document reprend l'index
 * courant et ses nouveaux noms de morceaux. Il est tenté UNE FOIS, marqué
 * dans la session — si le second essai échoue aussi, la cause n'était pas un
 * déploiement, et recharger en boucle serait pire que le mal.
 */
const CLE_RECHARGEMENT = 'rechargement-morceau'

function estMorceauIntrouvable(erreur) {
  const message = String(erreur?.message ?? '')

  return /dynamically imported module|Importing a module script failed|Failed to fetch/i.test(
    message,
  )
}

/** `sessionStorage` lève en navigation privée stricte : l'absence de marque
 *  vaut mieux qu'une erreur au moment de traiter une erreur. */
function marque(cle, valeur) {
  try {
    if (valeur === undefined) return sessionStorage.getItem(cle)
    if (valeur === null) sessionStorage.removeItem(cle)
    else sessionStorage.setItem(cle, valeur)
  } catch {
    return null
  }

  return null
}

router.onError((error) => {
  if (!estMorceauIntrouvable(error)) {
    signaler('navigation', error, "Cette page n'a pas pu s'ouvrir.")

    return
  }

  if (marque(CLE_RECHARGEMENT)) {
    signaler('navigation', error, "Cette page n'a pas pu s'ouvrir. Réessayez plus tard.")

    return
  }

  marque(CLE_RECHARGEMENT, '1')
  window.location.reload()
})

// Une navigation aboutie efface la marque : le prochain déploiement aura
// droit à son rechargement, comme celui-ci.
router.afterEach(() => marque(CLE_RECHARGEMENT, null))

app.use(router)

app.mount('#app')
