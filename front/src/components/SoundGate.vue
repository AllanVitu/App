<script setup>
/**
 * Écran d'entrée : le son est proposé, jamais imposé.
 *
 * Ce n'est pas qu'une intention de conception. Les navigateurs refusent de
 * démarrer un contexte audio tant qu'aucun geste utilisateur n'a eu lieu :
 * il FAUT un clic pour que le son puisse exister. Autant en faire un choix
 * explicite plutôt qu'une surprise au premier survol.
 *
 * L'écran n'apparaît qu'une fois — la réponse est mémorisée.
 */
import { onMounted, ref } from 'vue'

import { gsap, prefersReducedMotion } from '@/animations/gsap'
import * as sound from '@/services/sound'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()

const root = ref(null)
const leaving = ref(false)

onMounted(() => {
  if (prefersReducedMotion() || !root.value) return

  gsap.fromTo(
    root.value.querySelectorAll('[data-gate]'),
    { y: 18, opacity: 0 },
    { y: 0, opacity: 1, duration: 0.7, stagger: 0.09, ease: 'appEnter', delay: 0.15 },
  )
})

function choose(withSound) {
  leaving.value = true

  // Deux temps, et l'ordre compte.
  //
  // 1. Déverrouiller le moteur MAINTENANT : nous sommes dans un gestionnaire
  //    de clic, seul moment où le navigateur autorise la création d'un
  //    contexte audio. On passe par le service, pas par le store.
  sound.setEnabled(withSound)

  if (withSound) {
    sound.play('switchOn')
    sound.startAmbient()
  }

  // 2. Ne valider le choix qu'à la fin du fondu : `ui.setSound` bascule le
  //    drapeau qui conditionne l'affichage de cet écran, et le démonterait
  //    au milieu de son animation de sortie.
  const commit = () => ui.setSound(withSound)

  if (prefersReducedMotion() || !root.value) {
    commit()

    return
  }

  gsap.to(root.value, {
    opacity: 0,
    duration: 0.5,
    ease: 'appExit',
    onComplete: commit,
  })
}
</script>

<template>
  <div
    v-if="ui.soundUndecided"
    ref="root"
    class="fixed inset-0 z-70 flex flex-col items-center justify-center gap-8 bg-paper px-6"
    role="dialog"
    aria-modal="true"
    aria-labelledby="gate-title"
  >
    <!-- Marque : un dégradé radial animé, tracé en CSS -->
    <div
      data-gate
      class="size-20 rounded-full"
      style="
        background: radial-gradient(
          circle at 34% 30%,
          #d6ffe8 0%,
          #7ee2a8 26%,
          #2f9d7a 55%,
          #0d2b22 100%
        );
        box-shadow: 0 0 60px -18px #7ee2a8;
      "
      aria-hidden="true"
    />

    <div data-gate class="text-center">
      <h1 id="gate-title" class="text-lg font-semibold tracking-tight">saas os</h1>
      <p class="mt-1 text-[0.85rem] text-ink-2">tableau de bord &amp; modules métier</p>
    </div>

    <div data-gate class="flex flex-col items-center gap-3 sm:flex-row">
      <button
        type="button"
        class="rounded-pill border border-ink bg-ink px-6 py-2.5 text-[0.85rem] font-medium text-paper transition-opacity hover:opacity-85"
        :disabled="leaving"
        @click="choose(true)"
      >
        entrer avec le son
      </button>

      <button
        type="button"
        class="rounded-pill border border-line px-6 py-2.5 text-[0.85rem] text-ink-2 transition-colors hover:border-ink-3 hover:text-ink"
        :disabled="leaving"
        @click="choose(false)"
      >
        sans le son
      </button>
    </div>

    <p data-gate class="text-center text-[0.72rem] text-ink-3">
      Le son n'est qu'un accompagnement : tout reste lisible sans lui.<br />
      Modifiable à tout moment depuis la barre supérieure.
    </p>
  </div>
</template>
