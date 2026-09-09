<script setup>
/**
 * Zone d'affichage des notifications, montée une seule fois dans App.vue.
 *
 * aria-live="polite" : le message est annoncé aux lecteurs d'écran sans
 * interrompre la lecture en cours.
 */
import { storeToRefs } from 'pinia'

import { animate, appExit, appOvershoot, motionDuration } from '@/animations/motion'
import AppIcon from '@/components/AppIcon.vue'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()
const { toasts } = storeToRefs(ui)

/**
 * Ce composant est monté dans App.vue : il est chargé par les DEUX moitiés du
 * front. C'est précisément pourquoi il utilise anime.js et non GSAP — un seul
 * import de GSAP ici ramènerait ses 92 Ko dans le chemin de l'écran de
 * connexion, et annulerait tout le bénéfice de la séparation.
 *
 * Durées en MILLISECONDES.
 */

/** Le message entre par la droite, avec un léger dépassement. */
function onEnter(element, done) {
  animate(element, {
    translateX: [44, 0],
    opacity: [0, 1],
    duration: motionDuration(420),
    ease: appOvershoot,
    onComplete: done,
  })
}

/**
 * Sortie : le message part ET sa hauteur se referme, sinon les messages
 * restants sautent d'un coup pour combler le vide.
 *
 * La hauteur de départ n'est pas déclarée : anime.js lit la valeur calculée
 * de l'élément, qui est à l'écran donc mesurable. L'écrire en dur figerait la
 * hauteur d'un message d'une ligne sur un message qui en fait trois.
 */
function onLeave(element, done) {
  animate(element, {
    translateX: 32,
    opacity: 0,
    height: 0,
    marginTop: 0,
    paddingTop: 0,
    paddingBottom: 0,
    duration: motionDuration(240),
    ease: appExit,
    onComplete: done,
  })
}

/**
 * Le message garde le fond papier ; seule une bande de 2 px sur son flanc
 * gauche porte la couleur. Un aplat teinté plein écran jurerait avec la
 * sobriété du reste.
 */
const STYLES = {
  success: { icon: 'check', classes: 'border-l-moss text-ink', mark: 'text-moss' },
  error: { icon: 'alert', classes: 'border-l-brick text-ink', mark: 'text-brick' },
  info: { icon: 'info', classes: 'border-l-ink-3 text-ink', mark: 'text-ink-3' },
}

/**
 * Déclenche l'action d'un message, puis le referme.
 *
 * Le message est retiré AVANT que l'action n'aboutisse : la fenêtre
 * d'annulation est passée, et laisser le bouton cliquable une seconde de plus
 * inviterait à le presser deux fois — ce qui, sur une restauration, produit un
 * second appel qui échouera en 404 puisque l'élément est déjà revenu.
 *
 * L'erreur éventuelle est rendue à l'appelant, qui sait comment la dire.
 */
function run(toast) {
  ui.dismiss(toast.id)
  toast.action.run()
}
</script>

<template>
  <!-- sm:top-16 place la pile SOUS la barre supérieure (h-16) : sinon le
       premier message recouvre le menu du compte. -->
  <div
    class="pointer-events-none fixed inset-x-0 bottom-0 z-60 flex flex-col items-center gap-2 p-4 sm:inset-x-auto sm:right-0 sm:top-16 sm:items-end"
    aria-live="polite"
  >
    <TransitionGroup :css="false" @enter="onEnter" @leave="onLeave">
      <div
        v-for="toast in toasts"
        :key="toast.id"
        class="pointer-events-auto flex w-full max-w-sm items-start gap-2.5 rounded-field border border-line border-l-2 bg-panel px-4 py-3"
        :class="(STYLES[toast.type] ?? STYLES.info).classes"
      >
        <AppIcon
          :name="(STYLES[toast.type] ?? STYLES.info).icon"
          :size="16"
          class="mt-0.5 shrink-0"
          :class="(STYLES[toast.type] ?? STYLES.info).mark"
        />
        <p class="flex-1 text-[0.8rem] leading-snug">{{ toast.message }}</p>

        <!-- L'ACTION DE RATTRAPAGE, quand il y en a une.
             C'est par ici que passe « Annuler » après une suppression : la
             donnée n'a jamais quitté la base, seul le chemin de retour
             manquait. Un vrai bouton, atteignable au clavier comme le reste —
             et non un lien dans le texte, qu'un lecteur d'écran annoncerait au
             milieu d'une phrase. -->
        <button
          v-if="toast.action"
          type="button"
          class="shrink-0 font-semibold text-ink underline underline-offset-4 transition-opacity hover:opacity-70"
          @click="run(toast)"
        >
          {{ toast.action.label }}
        </button>

        <button
          type="button"
          class="shrink-0 text-ink-3 transition-colors hover:text-ink"
          aria-label="Fermer la notification"
          @click="ui.dismiss(toast.id)"
        >
          <AppIcon name="close" :size="14" />
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>
