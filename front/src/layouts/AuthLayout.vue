<script setup>
/**
 * Layout des pages publiques : connexion, inscription, mot de passe oublié,
 * réinitialisation, confirmation d'adresse.
 *
 * DEUX VOLETS à partir de 1024 px : un panneau de présentation à gauche, le
 * formulaire à droite. En dessous, le panneau disparaît et il ne reste que la
 * colonne centrée — un téléphone n'a pas la largeur pour deux volets, et
 * réduire le panneau à un bandeau ne ferait que repousser le formulaire sous
 * la ligne de flottaison.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  CE QUE LE PANNEAU DE GAUCHE N'EST PAS                              │
 * │                                                                     │
 * │  Ce n'est pas un décor. Deux éléments purement décoratifs ont déjà  │
 * │  été retirés de ce projet — un diagramme au canvas et une spirale — │
 * │  pour la même raison : ils n'encodaient RIEN.                       │
 * │                                                                     │
 * │  Ce panneau dit ce que fait le produit, à quelqu'un qui ne le sait  │
 * │  pas encore. C'est le seul endroit de l'application où cette        │
 * │  question se pose : partout ailleurs, l'utilisateur est déjà entré. │
 * │                                                                     │
 * │  Il n'affiche AUCUNE donnée inventée. Pas de fausses cartes, pas de │
 * │  faux compteurs : un écran d'accueil qui simule un tableau de bord  │
 * │  promet un état qui n'existe pas.                                   │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Ces écrans sont MUETS : la suspension est posée par le routeur
 * (meta.silent), et le choix sonore n'est proposé qu'une fois entré.
 */
import { animate, appEnter, DURATION, STAGGER, stagger } from '@/animations/motion'
import { useMotion } from '@/composables/useMotion'
import { splitChars } from '@/utils/text'

/** Ce que le produit fait, en trois lignes. Aucune ne dépend de la base. */
const POINTS = [
  'Cinq modules métier : tickets, déploiement, supervision, backend, design.',
  'Chaque compte ne voit que ses propres données, isolées en base.',
  'Thème clair ou sombre, et tout le clavier pour aller vite.',
]

/**
 * Entrée : la marque, puis le contenu.
 *
 * Les durées sont en MILLISECONDES (cf. animations/motion.js) — le piège
 * nº 1 quand on relit du code écrit pour GSAP, qui comptait en secondes.
 *
 * Les animations démarrent ensemble, décalées par leur `delay`, plutôt que
 * d'être enchaînées dans une timeline : à cette échelle — cinq éléments,
 * moins d'une seconde — une timeline n'apporterait qu'un objet de plus à
 * révoquer.
 */
const root = useMotion(() => {
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

  animate('[data-anim="claim"]', {
    translateY: [14, 0],
    opacity: [0, 1],
    duration: DURATION.feature,
    delay: stagger(STAGGER.blocks, { start: 180 }),
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
  <div ref="root" class="flex min-h-screen bg-paper">
    <!-- ======================= VOLET DE PRÉSENTATION =======================
         Masqué sous 1024 px. « aria-hidden » n'y est PAS posé : son contenu
         est du texte utile, pas un décor — un lecteur d'écran doit pouvoir
         apprendre ce qu'est le produit avant de créer un compte. -->
    <aside
      class="relative hidden w-[46%] max-w-2xl shrink-0 flex-col justify-between overflow-hidden border-r border-line p-10 lg:flex xl:p-14"
    >
      <!-- Fond : une GRILLE DE MESURE, pas un lavis.
           Deux dégradés radiaux pastel occupaient cette place — un halo mou,
           hérité de la direction précédente. La direction « signal » tient à
           l'arête nette : une grille tracée aux jetons du thème dit la même
           chose qu'un fond travaillé, sans rien diffuser. Elle s'efface vers
           les bords par un masque, pour ne pas concurrencer le texte.

           Deux dégradés linéaires : aucune image, aucune requête, et rien qui
           s'anime — le coût de peinture est celui d'un aplat. -->
      <div
        class="pointer-events-none absolute inset-0"
        style="
          background-image:
            linear-gradient(to right, var(--c-line) 1px, transparent 1px),
            linear-gradient(to bottom, var(--c-line) 1px, transparent 1px);
          background-size: 56px 56px;
          mask-image: radial-gradient(80% 65% at 22% 18%, #000 0%, transparent 100%);
        "
        aria-hidden="true"
      />

      <header class="relative flex items-center gap-3">
        <!-- LA MARQUE : trois barres de signal sur une plaque.
             Une sphère en dégradé radial, cerclée d'un halo, occupait cette
             place. Elle était jolie et elle ne disait rien — et surtout elle
             portait quatre couleurs écrites en dur, qui ne suivaient ni le
             thème ni la palette. Ces barres-là ne coûtent aucune image, se
             colorent aux jetons, et disent ce que fait l'application. -->
        <div
          data-anim="orb"
          class="flex size-11 shrink-0 items-end justify-center gap-0.75 rounded-card bg-focus p-2.5"
          aria-hidden="true"
        >
          <span class="h-[35%] w-0.75 rounded-[1px] bg-paper" />
          <span class="h-[65%] w-0.75 rounded-[1px] bg-paper" />
          <span class="h-full w-0.75 rounded-[1px] bg-paper" />
        </div>

        <div>
          <p data-anim="brand-name" class="text-[1.05rem] font-semibold tracking-tight">saas os</p>
          <p class="text-[0.74rem] text-ink-3">tableau de bord &amp; modules métier</p>
        </div>
      </header>

      <div class="relative max-w-md">
        <h1 data-anim="claim" class="text-[1.75rem] font-semibold leading-tight tracking-tight">
          Vos modules métier, dans un seul espace de travail.
        </h1>

        <ul class="mt-7 space-y-3.5">
          <li
            v-for="point in POINTS"
            :key="point"
            data-anim="claim"
            class="flex gap-3 text-[0.86rem] leading-relaxed text-ink-2"
          >
            <span class="mt-2 size-1.5 shrink-0 rounded-pill bg-moss" aria-hidden="true" />
            {{ point }}
          </li>
        </ul>
      </div>

      <p data-anim="claim" class="relative text-[0.72rem] text-ink-3">
        vue 3 · php 8.3 · postgresql · docker
      </p>
    </aside>

    <!-- ============================ FORMULAIRE ============================ -->
    <div class="flex min-w-0 flex-1 flex-col px-5 py-10 sm:px-8">
      <!-- La marque n'apparaît ici QUE sur mobile, où le volet de gauche est
           masqué : la montrer deux fois sur grand écran ferait douter qu'il
           s'agit de la même page. -->
      <header class="flex shrink-0 flex-col items-center gap-3 lg:hidden">
        <div
          class="flex size-14 items-end justify-center gap-1 rounded-card bg-focus p-3.5"
          aria-hidden="true"
        >
          <span class="h-[35%] w-1 rounded-[1px] bg-paper" />
          <span class="h-[65%] w-1 rounded-[1px] bg-paper" />
          <span class="h-full w-1 rounded-[1px] bg-paper" />
        </div>
        <div class="text-center">
          <p class="text-[1.05rem] font-semibold tracking-tight">saas os</p>
          <p class="mt-0.5 text-[0.78rem] text-ink-3">tableau de bord &amp; modules métier</p>
        </div>
      </header>

      <!-- Centré verticalement sur grand écran, où la colonne est seule dans
           sa moitié. Sur mobile, la marque occupe déjà le haut : un centrage
           y pousserait le formulaire sous la ligne de flottaison.
           « flex-1 » plutôt qu'une marge automatique : « lg:mt-0 » annulerait
           le « my-auto », et le formulaire remonterait en haut sans que rien
           ne le signale. -->
      <main
        data-anim="panel"
        class="mt-10 flex w-full flex-1 flex-col justify-start lg:mt-0 lg:justify-center"
      >
        <div class="mx-auto w-full max-w-96">
          <RouterView v-slot="{ Component }">
            <Transition name="fade" mode="out-in">
              <component :is="Component" />
            </Transition>
          </RouterView>
        </div>
      </main>

      <footer
        data-anim="foot"
        class="mt-10 flex shrink-0 flex-wrap items-center justify-center gap-x-4 gap-y-1 text-[0.72rem] text-ink-3 lg:mt-0"
      >
        <RouterLink :to="{ name: 'terms' }" class="transition-colors hover:text-ink-2">
          conditions générales
        </RouterLink>
        <span aria-hidden="true" class="lg:hidden">·</span>
        <span class="lg:hidden">vue 3 · php 8.3 · postgresql</span>
      </footer>
    </div>
  </div>
</template>
