<script setup>
/**
 * Fenêtre modale accessible.
 *
 *  - fermeture au clic sur l'arrière-plan et à la touche Échap ;
 *  - défilement de la page bloqué pendant l'affichage ;
 *  - rôle dialog + aria-modal, et le PIÈGE DE FOCUS qui va avec.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  « aria-modal » EST UNE PROMESSE, PAS UNE DÉCORATION                │
 * │                                                                     │
 * │  L'attribut annonce aux technologies d'assistance que le reste de   │
 * │  la page est inerte. Il ne le rend pas inerte : sans le code        │
 * │  ci-dessous, la touche Tab sortait de la fenêtre et continuait à    │
 * │  parcourir les champs du formulaire derrière le voile — invisibles, │
 * │  mais focalisables. On tapait dans une page qu'on ne voyait plus.   │
 * │                                                                     │
 * │  Trois obligations, toutes trois nécessaires :                      │
 * │   1. porter le focus DANS la fenêtre à l'ouverture ;                │
 * │   2. faire boucler Tab entre le premier et le dernier élément ;     │
 * │   3. RENDRE le focus à l'élément qui l'avait, à la fermeture —      │
 * │      sinon on rouvre au clavier depuis le début de la page.         │
 * └─────────────────────────────────────────────────────────────────────┘
 */
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'

import { animate, appExit, appOvershoot, motionDuration } from '@/animations/motion'
import { play } from '@/services/sound'

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
  size: { type: String, default: 'md', validator: (v) => ['sm', 'md', 'lg'].includes(v) },
})

const emit = defineEmits(['close'])

const titleId = useId()
const panel = ref(null)

const SIZES = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-2xl' }

/**
 * Sélecteur des éléments focalisables.
 *
 * Recalculé à CHAQUE Tab, jamais mis en cache : le contenu d'une fenêtre
 * change en cours de route — un bouton se désactive pendant un enregistrement,
 * une liste se charge. Une liste figée à l'ouverture piégerait le focus sur
 * des éléments disparus.
 */
const FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',')

const focusable = () =>
  [...(panel.value?.querySelectorAll(FOCUSABLE) ?? [])].filter(
    (element) => element.offsetParent !== null,
  )

/** Élément qui avait le focus avant l'ouverture, pour le lui rendre. */
let restoreTo = null

function onKeydown(event) {
  if (event.key === 'Escape') {
    emit('close')

    return
  }

  if (event.key !== 'Tab') return

  const elements = focusable()

  // Une fenêtre sans rien de focalisable : on garde le focus sur le panneau
  // plutôt que de le laisser repartir derrière le voile.
  if (elements.length === 0) {
    event.preventDefault()
    panel.value?.focus()

    return
  }

  const first = elements[0]
  const last = elements[elements.length - 1]
  const active = document.activeElement

  if (event.shiftKey && (active === first || !panel.value?.contains(active))) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && active === last) {
    event.preventDefault()
    first.focus()
  }
}

watch(
  () => props.open,
  async (isOpen) => {
    document.body.classList.toggle('overflow-hidden', isOpen)

    if (!isOpen) {
      document.removeEventListener('keydown', onKeydown)
      // Rendu APRÈS le retrait de l'écouteur : l'élément retrouvé peut être
      // celui qui rouvre la fenêtre.
      restoreTo?.focus?.()
      restoreTo = null

      return
    }

    restoreTo = document.activeElement
    document.addEventListener('keydown', onKeydown)

    // Le panneau n'existe qu'après le rendu.
    await nextTick()

    // Le premier élément focalisable plutôt que le panneau : on arrive
    // directement sur ce qu'il y a à faire. À défaut, le panneau lui-même,
    // pour que le lecteur d'écran annonce le titre.
    const [first] = focusable()

    ;(first ?? panel.value)?.focus()
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
  play('open')

  animate(element.querySelector('[data-backdrop]'), {
    opacity: [0, 1],
    duration: motionDuration(200),
  })

  // C'est le PANNEAU qui rend la main, pas le voile : il finit le dernier, et
  // « done » démonte le nœud. L'appeler sur le voile couperait le panneau en
  // pleine montée.
  animate(element.querySelector('[data-panel]'), {
    translateY: [26, 0],
    scale: [0.96, 1],
    opacity: [0, 1],
    duration: motionDuration(400),
    delay: motionDuration(40),
    ease: appOvershoot,
    onComplete: done,
  })
}

function onLeave(element, done) {
  play('close')

  animate(element.querySelector('[data-backdrop]'), {
    opacity: 0,
    duration: motionDuration(160),
  })

  animate(element.querySelector('[data-panel]'), {
    translateY: 10,
    scale: 0.98,
    opacity: 0,
    duration: motionDuration(180),
    ease: appExit,
    onComplete: done,
  })
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
        <div data-backdrop class="absolute inset-0 bg-ink/45" @click="emit('close')" />

        <!-- « tabindex="-1" » : le panneau n'entre pas dans l'ordre de
             tabulation, mais peut recevoir le focus par programme — c'est le
             repli quand la fenêtre ne contient rien de focalisable. -->
        <div
          ref="panel"
          data-panel
          tabindex="-1"
          class="panel relative z-10 flex max-h-[calc(100dvh-2rem)] w-full flex-col overflow-hidden border-ink-3 focus:outline-none"
          :class="SIZES[size]"
        >
          <header
            v-if="title"
            class="flex shrink-0 items-center justify-between border-b border-line bg-raised px-4 py-2.5"
          >
            <h2 :id="titleId" class="text-[0.8rem] font-semibold tracking-wide">{{ title }}</h2>
            <button
              type="button"
              class="p-1 text-ink-3 transition-colors hover:text-ink"
              aria-label="Fermer"
              @click="emit('close')"
            >
              <svg
                width="18"
                height="18"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
              >
                <path d="M18 6 6 18M6 6l12 12" />
              </svg>
            </button>
          </header>

          <!-- « min-h-0 » : sans lui, un enfant flex refuse de rétrécir
               sous sa hauteur de contenu et le débordement sort du panneau au
               lieu de défiler dedans. C'est le piège classique de flexbox.
               La hauteur est bornée sur le PANNEAU (« 100dvh » et non « vh » :
               sur mobile, « vh » ignore la barre d'adresse et déborde). -->
          <div class="min-h-0 flex-1 overflow-y-auto px-4 py-4">
            <slot />
          </div>

          <footer
            v-if="$slots.footer"
            class="flex shrink-0 justify-end gap-2 border-t border-line bg-raised px-4 py-3"
          >
            <slot name="footer" />
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
