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

const STYLES = {
  success: {
    icon: 'check',
    classes: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300',
  },
  error: {
    icon: 'alert',
    classes: 'border-red-200 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300',
  },
  info: {
    icon: 'info',
    classes: 'border-slate-200 bg-white text-slate-800 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200',
  },
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
        class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border px-4 py-3 shadow-lg"
        :class="(STYLES[toast.type] ?? STYLES.info).classes"
      >
        <AppIcon :name="(STYLES[toast.type] ?? STYLES.info).icon" :size="18" class="mt-0.5 shrink-0" />
        <p class="flex-1 text-sm">{{ toast.message }}</p>

        <button
          type="button"
          class="shrink-0 opacity-60 transition hover:opacity-100"
          aria-label="Fermer la notification"
          @click="ui.dismiss(toast.id)"
        >
          <AppIcon name="close" :size="16" />
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>
