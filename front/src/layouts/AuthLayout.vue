<script setup>
/**
 * Layout des pages publiques (connexion, inscription).
 *
 * Deux colonnes sur grand écran : présentation à gauche, formulaire à droite.
 * Le panneau de présentation est masqué sur mobile pour laisser toute la
 * place au formulaire.
 */
import { gsap, SplitText } from '@/animations/gsap'
import AppIcon from '@/components/AppIcon.vue'
import ShaderBackground from '@/components/ShaderBackground.vue'
import { useGsap } from '@/composables/useGsap'

const highlights = [
  { icon: 'layout-grid', title: 'Modules métier', text: 'Quatre espaces de travail prêts à l\'emploi.' },
  { icon: 'shield', title: 'Sécurité', text: 'Sessions JWT et mots de passe chiffrés.' },
  { icon: 'chart-bar', title: 'Pilotage', text: 'Indicateurs consolidés sur le tableau de bord.' },
]

/**
 * Ouverture du panneau de présentation : le titre se révèle mot à mot, puis
 * les arguments arrivent en cascade. Une seule timeline, donc un rythme
 * maîtrisé plutôt que trois animations concurrentes.
 */
const root = useGsap(() => {
  const heading = document.querySelector('[data-anim="auth-heading"]')
  const timeline = gsap.timeline()

  if (heading) {
    // SplitText découpe le titre en mots ; le contexte GSAP se charge de
    // restaurer le DOM d'origine au démontage de la vue.
    const split = new SplitText(heading, { type: 'words', wordsClass: 'inline-block' })

    timeline.fromTo(
      split.words,
      { yPercent: 110, opacity: 0 },
      { yPercent: 0, opacity: 1, duration: 0.7, stagger: 0.045 },
    )
  }

  // fromTo systématiquement : les deux extrémités sont écrites, l'animation
  // ne peut pas déduire un état d'arrivée erroné.
  timeline
    .fromTo('[data-anim="auth-brand"]', { y: -12, opacity: 0 }, { y: 0, opacity: 1, duration: 0.5 }, 0)
    .fromTo('[data-anim="auth-lead"]', { y: 12, opacity: 0 }, { y: 0, opacity: 1, duration: 0.5 }, 0.25)
    .fromTo('[data-anim="auth-item"]', { x: -18, opacity: 0 }, { x: 0, opacity: 1, stagger: 0.08 }, 0.35)
    .fromTo('[data-anim="auth-footer"]', { opacity: 0 }, { opacity: 1, duration: 0.6 }, 0.6)
})
</script>

<template>
  <div ref="root" class="flex min-h-screen">
    <!-- Panneau de présentation -->
    <div
      class="relative hidden w-1/2 flex-col justify-between overflow-hidden bg-brand-900 p-12 text-white lg:flex"
    >
      <!-- Dégradé animé (WebGL), assombri pour garder le texte lisible -->
      <ShaderBackground
        color1="#207bd6"
        color2="#910aff"
        color3="#af38ff"
        :brightness="1.5"
        :u-speed="0.3"
        :u-density="0.8"
        :u-frequency="5.5"
        :u-amplitude="7"
        :u-strength="0.4"
        :rotation-z="140"
        :camera-zoom="12.5"
        :c-distance="1.5"
        grain="on"
      />
      <div class="pointer-events-none absolute inset-0 bg-slate-950/35" />

      <div data-anim="auth-brand" class="relative flex items-center gap-3">
        <div class="flex size-10 items-center justify-center rounded-xl bg-white/15 backdrop-blur-sm">
          <AppIcon name="sparkles" :size="22" />
        </div>
        <span class="text-lg font-semibold tracking-tight">SaaS App</span>
      </div>

      <div class="relative max-w-md">
        <h2
          data-anim="auth-heading"
          class="overflow-hidden text-3xl font-bold leading-tight [text-shadow:0_2px_20px_rgb(2_6_23/0.45)]"
        >
          Pilotez votre activité depuis une interface unique.
        </h2>
        <p data-anim="auth-lead" class="mt-4 text-brand-50">
          Tableau de bord, modules métier et paramétrage — tout est réuni au même endroit.
        </p>

        <ul class="mt-10 space-y-5">
          <li v-for="item in highlights" :key="item.title" data-anim="auth-item" class="flex gap-4">
            <div
              class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white/15 backdrop-blur-sm"
            >
              <AppIcon :name="item.icon" :size="18" />
            </div>
            <div>
              <p class="text-sm font-semibold">{{ item.title }}</p>
              <p class="text-sm text-brand-50/90">{{ item.text }}</p>
            </div>
          </li>
        </ul>
      </div>

      <p data-anim="auth-footer" class="relative text-xs text-brand-100/80">
        Vue 3 · PHP 8.3 · PostgreSQL 16 · Docker
      </p>
    </div>

    <!-- Formulaire -->
    <div class="flex w-full items-center justify-center px-5 py-10 lg:w-1/2">
      <div class="w-full max-w-sm">
        <RouterView v-slot="{ Component }">
          <Transition name="fade" mode="out-in">
            <component :is="Component" />
          </Transition>
        </RouterView>
      </div>
    </div>
  </div>
</template>
