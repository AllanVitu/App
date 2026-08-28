<script setup>
/**
 * Fenêtre modale accessible.
 *
 *  - fermeture au clic sur l'arrière-plan et à la touche Échap ;
 *  - défilement de la page bloqué pendant l'affichage ;
 *  - rôle dialog + aria-modal pour les lecteurs d'écran.
 */
import { onBeforeUnmount, useId, watch } from 'vue'

import { gsap, motionDuration } from '@/animations/gsap'

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
  size: { type: String, default: 'md', validator: (v) => ['sm', 'md', 'lg'].includes(v) },
})

const emit = defineEmits(['close'])

const titleId = useId()

const SIZES = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-2xl' }

function onKeydown(event) {
  if (event.key === 'Escape') emit('close')
}

watch(
  () => props.open,
  (isOpen) => {
    document.body.classList.toggle('overflow-hidden', isOpen)

    if (isOpen) {
      document.addEventListener('keydown', onKeydown)
    } else {
      document.removeEventListener('keydown', onKeydown)
    }
  },
)

// Un démontage pendant l'ouverture laisserait le <body> bloqué.
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown)
  document.body.classList.remove('overflow-hidden')
})

/**
 * Ouverture : le voile apparaît, puis le panneau monte avec un léger
 * dépassement — il « arrive » plutôt qu'il ne surgit.
 * Fermeture : deux fois plus rapide. Une sortie lente donne l'impression
 * que l'interface hésite.
 */
function onEnter(element, done) {
  gsap
    .timeline({ onComplete: done })
    .from(element.querySelector('[data-backdrop]'), {
      opacity: 0,
      duration: motionDuration(0.2),
    })
    .from(
      element.querySelector('[data-panel]'),
      {
        y: 26,
        scale: 0.96,
        opacity: 0,
        duration: motionDuration(0.4),
        ease: 'appOvershoot',
      },
      0.04,
    )
}

function onLeave(element, done) {
  gsap
    .timeline({ onComplete: done })
    .to(element.querySelector('[data-panel]'), {
      y: 10,
      scale: 0.98,
      opacity: 0,
      duration: motionDuration(0.18),
      ease: 'appExit',
    })
    .to(
      element.querySelector('[data-backdrop]'),
      { opacity: 0, duration: motionDuration(0.16) },
      0,
    )
}
</script>

<template>
  <Teleport to="body">
    <Transition :css="false" @enter="onEnter" @leave="onLeave">
      <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="title ? titleId : undefined"
      >
        <div data-backdrop class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" @click="emit('close')" />

        <div
          data-panel
          class="card relative z-10 w-full overflow-hidden shadow-xl"
          :class="SIZES[size]"
        >
          <header
            v-if="title"
            class="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-800"
          >
            <h2 :id="titleId" class="text-base font-semibold">{{ title }}</h2>
            <button
              type="button"
              class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200"
              aria-label="Fermer"
              @click="emit('close')"
            >
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M18 6 6 18M6 6l12 12" />
              </svg>
            </button>
          </header>

          <div class="max-h-[70vh] overflow-y-auto px-5 py-4">
            <slot />
          </div>

          <footer
            v-if="$slots.footer"
            class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3 dark:border-slate-800 dark:bg-slate-950/50"
          >
            <slot name="footer" />
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
