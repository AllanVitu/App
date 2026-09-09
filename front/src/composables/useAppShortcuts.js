import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'

/**
 * Raccourcis clavier valables sur TOUTE l'application.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  LE MODULE TICKETS ÉTAIT LE SEUL À AVOIR UN CLAVIER                 │
 * │                                                                     │
 * │  On y crée, priorise, termine et supprime sans toucher la souris.   │
 * │  Partout ailleurs, rien — pas même de quoi savoir que des           │
 * │  raccourcis existent quelque part. Celui qui prend l'habitude sur   │
 * │  un écran la perd en changeant de page, ce qui est pire que de ne   │
 * │  jamais l'avoir prise.                                              │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * DEUX FAMILLES, ET ELLES NE SE MÉLANGENT PAS.
 *
 *   « g » PUIS UNE LETTRE — aller quelque part. Une séquence, pas une
 *   combinaison : « g » seul ne fait rien, il arme l'attente de la lettre
 *   suivante. C'est ce qui permet d'avoir dix destinations sans épuiser les
 *   touches ni marcher sur les raccourcis du navigateur.
 *
 *   « ? » — la liste elle-même. Un raccourci qu'on ne peut pas découvrir
 *   n'existe pas ; celui-ci est le seul qui n'a pas besoin d'être connu,
 *   parce que c'est la convention.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  RIEN NE SE DÉCLENCHE PENDANT UNE SAISIE                            │
 * │                                                                     │
 * │  Écrire « g » dans un champ de recherche ne doit pas armer une      │
 * │  navigation, et « ? » dans un titre de ticket doit rester un point  │
 * │  d'interrogation. Les combinaisons (Ctrl, ⌘, Alt) sont laissées au  │
 * │  navigateur et au système, sans exception.                          │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Le module Tickets garde SES raccourcis à lui : ils portent sur la ligne
 * sélectionnée, pas sur l'application. Les deux cohabitent parce qu'aucune
 * lettre n'est partagée — « g » et « ? » n'y sont pas utilisés.
 */

/** Délai au-delà duquel une séquence commencée est oubliée. */
const SEQUENCE_MS = 1200

/**
 * Destinations, par la lettre qui suit « g ».
 *
 * Les initiales sont choisies pour être devinables : a(ccueil), t(ickets),
 * b(ackend)… Là où deux modules commencent pareil — déploiement et design —
 * la seconde reçoit une lettre distincte plutôt qu'une combinaison à retenir.
 */
export const DESTINATIONS = [
  { key: 'a', label: 'accueil', to: { name: 'dashboard' } },
  { key: 't', label: 'tickets', to: '/modules/tickets' },
  { key: 'b', label: 'backend', to: '/modules/backend' },
  { key: 'd', label: 'déploiement', to: '/modules/deploiement' },
  { key: 's', label: 'supervision', to: '/modules/supervision' },
  { key: 'm', label: 'design (maquettes)', to: '/modules/design' },
  { key: 'p', label: 'profil', to: { name: 'profile' } },
  { key: 'r', label: 'réglages', to: { name: 'settings' } },
]

/** Un champ de saisie a la priorité absolue sur les raccourcis à touche unique. */
function isTyping(element) {
  if (!element) return false

  const tag = element.tagName

  return (
    tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || element.isContentEditable === true
  )
}

export function useAppShortcuts() {
  const router = useRouter()

  /** Feuille des raccourcis, ouverte par « ? ». */
  const helpOpen = ref(false)

  /** Vrai entre « g » et la lettre suivante — affiché pour ne pas laisser en suspens. */
  const pending = ref(false)

  let timer = null

  function disarm() {
    pending.value = false
    clearTimeout(timer)
  }

  function onKeydown(event) {
    // Échap referme la feuille où qu'on soit, y compris depuis un champ :
    // c'est une sortie de secours, elle ne dépend pas du focus.
    if (event.key === 'Escape' && helpOpen.value) {
      helpOpen.value = false
      disarm()

      return
    }

    if (isTyping(event.target)) return
    if (event.metaKey || event.ctrlKey || event.altKey) return

    // Une séquence en cours : la lettre décide.
    if (pending.value) {
      const destination = DESTINATIONS.find((entry) => entry.key === event.key)

      disarm()

      if (destination) {
        event.preventDefault()
        router.push(destination.to)
      }

      return
    }

    if (event.key === 'g') {
      event.preventDefault()
      pending.value = true
      // Oubliée si rien ne suit : « g » tapé par erreur ne doit pas piéger la
      // touche suivante, parfois plusieurs secondes plus tard.
      timer = setTimeout(disarm, SEQUENCE_MS)

      return
    }

    if (event.key === '?') {
      event.preventDefault()
      helpOpen.value = !helpOpen.value
    }
  }

  onMounted(() => document.addEventListener('keydown', onKeydown))

  onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown)
    clearTimeout(timer)
  })

  return { helpOpen, pending }
}
