<script setup>
/**
 * Layout des pages publiques : connexion, inscription, mot de passe oublié,
 * réinitialisation, confirmation d'adresse.
 *
 * Composition volontairement différente de l'application : une colonne
 * centrée sur le noir, la marque en haut, le formulaire au milieu, rien
 * d'autre. Un écran d'identification n'a pas à exposer un tableau de bord
 * qu'on ne peut pas encore utiliser.
 *
 * Ces écrans sont MUETS : la suspension est posée par le routeur
 * (meta.silent), et le choix sonore n'est proposé qu'une fois entré.
 */
import { animate, appEnter, DURATION, STAGGER, stagger } from '@/animations/anime'
import { useAnime } from '@/composables/useAnime'
import { splitChars } from '@/utils/text'

/**
 * Entrée : la marque, puis le contenu.
 *
 * Écrite avec anime.js et non GSAP : cet écran appartient à la moitié
 * publique, qui doit rester légère (cf. animations/anime.js). Les durées
 * sont en MILLISECONDES — le piège nº 1 quand les deux moteurs cohabitent.
 *
 * Les animations démarrent ensemble, décalées par leur `delay`, plutôt que
 * d'être enchaînées dans une timeline : à cette échelle — quatre éléments,
 * moins d'une seconde — une timeline n'apporterait qu'un objet de plus à
 * révoquer.
 */
const root = useAnime(() => {
  animate('[data-anim="orb"]', {
    scale: [0.6, 1],
    opacity: [0, 1],
    duration: DURATION.feature,
    ease: appEnter,
  })

  // Le nom se compose lettre à lettre — le seul geste appuyé de l'écran.
  const letters = splitChars(document.querySelector('[data-anim="brand-name"]'))

  animate(letters, {
    translateY: ['120%', '0%'],
    opacity: [0, 1],
    duration: DURATION.feature,
    delay: stagger(STAGGER.letters),
    ease: appEnter,
  })

  animate('[data-anim="panel"]', {
    translateY: [22, 0],
    opacity: [0, 1],
    duration: DURATION.feature,
    delay: 250,
    ease: appEnter,
  })

  animate('[data-anim="foot"]', {
    opacity: [0, 1],
    duration: DURATION.base,
    delay: 450,
    ease: appEnter,
  })
})
</script>

<template>
  <div ref="root" class="flex min-h-screen flex-col items-center bg-paper px-5 py-10">
    <!-- Marque -->
    <header class="flex shrink-0 flex-col items-center gap-4">
      <!-- Sphère : un dégradé radial tracé en CSS. Le halo la décolle du noir. -->
      <div
        data-anim="orb"
        class="size-16 rounded-full"
        style="
          background: radial-gradient(
            circle at 34% 30%,
            #d6ffe8 0%,
            #7ee2a8 26%,
            #2f9d7a 55%,
            #0d2b22 100%
          );
          box-shadow: 0 0 50px -14px #7ee2a8;
        "
        aria-hidden="true"
      />

      <div class="text-center">
        <p data-anim="brand-name" class="text-[1.05rem] font-semibold tracking-tight">saas os</p>
        <p class="mt-0.5 text-[0.78rem] text-ink-3">tableau de bord &amp; modules métier</p>
      </div>
    </header>

    <!-- Formulaire.
         Marge fixe plutôt que centrage vertical : avec « flex-1 », la marque
         restait collée en haut et le formulaire flottait au milieu, comme
         deux écrans superposés. Un écart constant les relie. -->
    <main data-anim="panel" class="mt-10 w-full sm:mt-12">
      <div class="mx-auto w-full max-w-88">
        <RouterView v-slot="{ Component }">
          <Transition name="fade" mode="out-in">
            <component :is="Component" />
          </Transition>
        </RouterView>
      </div>
    </main>

    <!-- Pousse le pied de page en bas sans étirer le formulaire -->
    <div class="flex-1" aria-hidden="true" />

    <footer
      data-anim="foot"
      class="flex shrink-0 flex-wrap items-center justify-center gap-x-4 gap-y-1 text-[0.72rem] text-ink-3"
    >
      <RouterLink :to="{ name: 'terms' }" class="transition-colors hover:text-ink-2">
        conditions générales
      </RouterLink>
      <span aria-hidden="true">·</span>
      <span>vue 3 · php 8.3 · postgresql</span>
    </footer>
  </div>
</template>
