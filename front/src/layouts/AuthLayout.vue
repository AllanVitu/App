<script setup>
/**
 * Layout des pages publiques (connexion, inscription, mot de passe oublié,
 * confirmation d'adresse).
 *
 * Même cadre que l'application authentifiée : deux panneaux séparés par une
 * gouttière, barre d'état en pied. L'utilisateur reconnaît l'endroit avant
 * même de s'être connecté.
 */
import { gsap, SplitText } from '@/animations/gsap'
import AppIcon from '@/components/AppIcon.vue'
import TechnicalDiagram from '@/components/TechnicalDiagram.vue'
import { useGsap } from '@/composables/useGsap'

const highlights = [
  { icon: 'layout-grid', title: 'modules', text: "Quatre espaces de travail prêts à l'emploi." },
  { icon: 'shield', title: 'sécurité', text: 'Sessions JWT, mots de passe chiffrés.' },
  { icon: 'chart-bar', title: 'pilotage', text: "Indicateurs consolidés à l'accueil." },
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
    .fromTo(
      '[data-anim="auth-brand"]',
      { y: -12, opacity: 0 },
      { y: 0, opacity: 1, duration: 0.5 },
      0,
    )
    .fromTo(
      '[data-anim="auth-lead"]',
      { y: 12, opacity: 0 },
      { y: 0, opacity: 1, duration: 0.5 },
      0.25,
    )
    .fromTo(
      '[data-anim="auth-item"]',
      { x: -18, opacity: 0 },
      { x: 0, opacity: 1, stagger: 0.08 },
      0.35,
    )
    .fromTo('[data-anim="auth-diagram"]', { opacity: 0 }, { opacity: 1, duration: 1.1 }, 0.2)
})
</script>

<template>
  <div ref="root" class="flex h-screen flex-col gap-1.5 bg-paper p-1.5 lg:gap-2 lg:p-2">
    <div class="flex min-h-0 flex-1 gap-1.5 lg:gap-2">
      <!-- Panneau de présentation -->
      <section
        class="panel relative hidden min-w-0 flex-1 flex-col justify-between p-8 lg:flex xl:p-10"
      >
        <div data-anim="auth-brand" class="relative z-10 flex items-baseline gap-2.5">
          <div class="flex size-8 items-center justify-center border border-line bg-raised">
            <AppIcon name="sparkles" :size="16" />
          </div>
          <div>
            <p class="text-[0.9rem] font-bold tracking-[0.08em]">SAAS OS</p>
            <p class="text-[0.68rem] text-ink-3">v1.0.0</p>
          </div>
        </div>

        <!-- Diagramme : ancré à droite et atténué, pour que le texte garde un
             champ libre à gauche. Il ne capte jamais le pointeur. -->
        <div
          data-anim="auth-diagram"
          class="pointer-events-none absolute right-[-14%] top-1/2 aspect-square w-[62%] -translate-y-1/2 opacity-45"
        >
          <TechnicalDiagram :satellites="7" />
        </div>

        <div class="relative z-10 max-w-sm">
          <h2
            data-anim="auth-heading"
            class="overflow-hidden text-[1.75rem] font-bold leading-[1.15] xl:text-[2rem]"
          >
            Pilotez votre activité depuis une interface unique.
          </h2>
          <p data-anim="auth-lead" class="mt-3 text-[0.82rem] leading-relaxed text-ink-2">
            Tableau de bord, modules métier et paramétrage — réunis au même endroit.
          </p>

          <ul class="mt-8 space-y-3.5">
            <li
              v-for="item in highlights"
              :key="item.title"
              data-anim="auth-item"
              class="flex gap-3"
            >
              <div
                class="flex size-7 shrink-0 items-center justify-center border border-line bg-raised text-ink-2"
              >
                <AppIcon :name="item.icon" :size="14" />
              </div>
              <div class="min-w-0">
                <p class="label-caps">{{ item.title }}</p>
                <p class="text-[0.78rem] leading-snug text-ink-2">{{ item.text }}</p>
              </div>
            </li>
          </ul>
        </div>

        <p class="relative z-10 text-[0.68rem] tracking-wide text-ink-3">
          vue 3 · php 8.3 · postgresql 16 · docker
        </p>
      </section>

      <!-- Formulaire -->
      <section
        class="panel flex min-w-0 flex-1 items-center justify-center overflow-y-auto px-5 py-10"
      >
        <div class="w-full max-w-sm">
          <RouterView v-slot="{ Component }">
            <Transition name="fade" mode="out-in">
              <component :is="Component" />
            </Transition>
          </RouterView>
        </div>
      </section>
    </div>

    <!-- Barre d'état, sans données de session -->
    <footer
      class="panel flex h-9 shrink-0 items-center gap-3 px-3 text-[0.7rem] tracking-wide text-ink-3"
    >
      <span class="flex items-center gap-1.5">
        <span class="size-1.5 bg-ink-3" aria-hidden="true" />
        non authentifié
      </span>
      <span class="ml-auto">v1.0.0</span>
    </footer>
  </div>
</template>
