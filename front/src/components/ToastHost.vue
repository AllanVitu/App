<script setup>
/**
 * Zone d'affichage des notifications, montée une seule fois dans App.vue.
 *
 * aria-live="polite" : le message est annoncé aux lecteurs d'écran sans
 * interrompre la lecture en cours.
 */
import { storeToRefs } from 'pinia'

import { gsap, motionDuration } from '@/animations/gsap'
import AppIcon from '@/components/AppIcon.vue'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()
const { toasts } = storeToRefs(ui)

/** Le message entre par la droite, avec un léger dépassement. */
function onEnter(element, done) {
  gsap.from(element, {
    x: 44,
    opacity: 0,
    duration: motionDuration(0.42),
    ease: 'appOvershoot',
    onComplete: done,
  })
}

/**
 * Sortie : le message part ET sa hauteur se referme, sinon les messages
 * restants sautent d'un coup pour combler le vide.
 */
function onLeave(element, done) {
  gsap.to(element, {
    x: 32,
    opacity: 0,
    height: 0,
    marginTop: 0,
    paddingTop: 0,
    paddingBottom: 0,
    duration: motionDuration(0.24),
    ease: 'appExit',
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
