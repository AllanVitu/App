<script setup>
/**
 * Mise en page des écrans publics : connexion, inscription, mot de passe
 * oublié, invitation.
 *
 * À gauche, ce qu'est Relais ; à droite, le formulaire. Le volet de gauche
 * change avec l'écran, parce que ce qu'on a besoin de savoir n'est pas le
 * même : qui revient voit le plan des lignes, qui arrive voit les trois
 * arrêts de son arrivée.
 *
 * Sous 1024 px, le volet disparaît et la marque passe au-dessus du
 * formulaire. « aria-hidden » n'est PAS posé sur le volet : son contenu est
 * du texte utile, et un lecteur d'écran doit pouvoir apprendre ce qu'est le
 * produit avant de créer un compte.
 */
import { computed } from 'vue'
import { useRoute } from 'vue-router'

import { animate, appEnter, DURATION, STAGGER, stagger } from '@/animations/motion'
import RelaisMark from '@/components/RelaisMark.vue'
import { useMotion } from '@/composables/useMotion'
import { splitChars } from '@/utils/text'

const route = useRoute()

const arrivee = computed(() => route.name === 'register')

/**
 * Le plan des lignes : une par module, qui se croisent au relais.
 *
 * Tracé à 45°, comme un plan de transport, et dans les couleurs de ligne de
 * l'application : on reconnaît son module avant même de s'être connecté.
 *
 * Une ligne n'y figure qu'avec son module. Le plan est une promesse faite à
 * quelqu'un qui n'a pas encore de compte : il ne montre rien que
 * l'application ne fasse.
 *
 * Les classes de trait sont écrites EN TOUTES LETTRES : Tailwind ne génère
 * que ce qu'il lit dans les sources.
 */
const LIGNES = [
  {
    slug: 'backend',
    nom: 'BACKEND',
    trace: 'M330 300V170L260 100H110',
    trait: 'stroke-mod-backend',
    station: [330, 170],
    terminus: [110, 100],
    etiquette: { x: 110, y: 80, ancre: 'start' },
  },
  {
    slug: 'deploiement',
    nom: 'DÉPLOIEMENT',
    trace: 'M330 300L450 180V70',
    trait: 'stroke-mod-deploiement',
    station: [450, 180],
    terminus: [450, 70],
    etiquette: { x: 468, y: 74, ancre: 'start' },
  },
  {
    slug: 'supervision',
    nom: 'SUPERVISION',
    trace: 'M330 300L440 410H600',
    trait: 'stroke-mod-supervision',
    station: [440, 410],
    terminus: [600, 410],
    etiquette: { x: 600, y: 440, ancre: 'end' },
  },
  {
    slug: 'design',
    nom: 'DESIGN',
    trace: 'M330 300V470L400 540',
    trait: 'stroke-mod-design',
    station: [330, 470],
    terminus: [400, 540],
    etiquette: { x: 420, y: 545, ancre: 'start' },
  },
  {
    slug: 'tickets',
    nom: 'TICKETS',
    trace: 'M330 300L210 420V520',
    trait: 'stroke-mod-tickets',
    station: [210, 420],
    terminus: [210, 520],
    etiquette: { x: 228, y: 524, ancre: 'start' },
  },
]

/**
 * Les trois arrêts de l'arrivée. Chaque phrase décrit ce que l'application
 * fait réellement : l'espace créé au nom de la personne, l'invitation valable
 * sept jours, la clé d'API qui fait remonter les erreurs.
 */
const ETAPES = [
  {
    quand: 'Maintenant',
    titre: "Créer l'espace",
    texte:
      "Un nom, une adresse, un mot de passe. L'espace porte d'abord votre nom ; vous le renommerez.",
    proche: true,
  },
  {
    quand: 'Ensuite',
    titre: "Inviter l'équipe",
    texte: 'Chacun reçoit un lien valable sept jours, et choisit son propre mot de passe.',
    proche: true,
  },
  {
    quand: 'Quand vous voulez',
    titre: 'Brancher la production',
    texte:
      "Une clé d'API suffit pour que les erreurs de production arrivent seules, groupées par cause.",
    proche: false,
  },
]

/**
 * Entrée des écrans publics.
 *
 * Aucun tracé progressif des lignes, et c'est délibéré : il vit dans le lot
 * « anime-svg », que l'écran de connexion ne doit pas télécharger (cf.
 * scripts/check-chunks.mjs). Le plan entre d'un bloc, avec le texte.
 */
const root = useMotion(() => {
  animate('[data-anim="orb"]', {
    scale: [0.6, 1],
    opacity: [0, 1],
    duration: DURATION.feature,
    ease: appEnter,
  })

  // Le nom est découpé en lettres animables ; le texte entier est reporté sur
  // le conteneur pour qu'un lecteur d'écran ne l'épelle pas.
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
    <!-- ======================= VOLET DE PRÉSENTATION ======================= -->
    <aside
      class="relative hidden w-[46%] max-w-[41.25rem] shrink-0 flex-col border-r border-line bg-panel px-11 pb-9 pt-7 lg:flex"
    >
      <header class="flex items-center gap-3">
        <span data-anim="orb" class="block">
          <RelaisMark class="size-8" />
        </span>
        <p data-anim="brand-name" class="text-lg font-extrabold tracking-[-0.01em]">Relais</p>
      </header>

      <template v-if="!arrivee">
        <svg
          data-anim="claim"
          class="mt-5 h-auto w-full max-w-[36rem]"
          viewBox="40 40 580 530"
          fill="none"
          role="img"
          aria-labelledby="plan-des-lignes"
        >
          <title id="plan-des-lignes">
            Plan des lignes : les modules de Relais se croisent en un seul point.
          </title>

          <g stroke-width="12" stroke-linecap="round" stroke-linejoin="round">
            <path v-for="ligne in LIGNES" :key="ligne.slug" :d="ligne.trace" :class="ligne.trait" />
          </g>

          <g class="fill-ink stroke-panel" stroke-width="3">
            <circle
              v-for="ligne in LIGNES"
              :key="ligne.slug"
              :cx="ligne.station[0]"
              :cy="ligne.station[1]"
              r="7"
            />
          </g>

          <g class="fill-panel stroke-ink" stroke-width="4">
            <rect
              v-for="ligne in LIGNES"
              :key="ligne.slug"
              :x="ligne.terminus[0] - 8"
              :y="ligne.terminus[1] - 8"
              width="16"
              height="16"
            />
          </g>

          <circle cx="330" cy="300" r="26" class="fill-ink stroke-panel" stroke-width="8" />
          <circle cx="330" cy="300" r="9" class="fill-panel" />

          <g class="fill-ink-3 text-[11px] font-semibold tracking-[0.12em] [font-stretch:82%]">
            <text
              v-for="ligne in LIGNES"
              :key="ligne.slug"
              :x="ligne.etiquette.x"
              :y="ligne.etiquette.y"
              :text-anchor="ligne.etiquette.ancre"
            >
              {{ ligne.nom }}
            </text>
          </g>
        </svg>

        <div class="mt-auto flex flex-col gap-3.5">
          <h2
            data-anim="claim"
            class="text-[2.5rem] font-bold leading-[1.02] tracking-[-0.02em] [font-stretch:78%] [text-wrap:balance]"
          >
            Du ticket à la production, sans changer d'outil.
          </h2>
          <p data-anim="claim" class="max-w-[30rem] text-[0.97rem] text-ink-2 [text-wrap:pretty]">
            Tickets, déploiements, erreurs et fichiers de design se croisent au même endroit. Quand
            quelque chose casse, vous voyez aussi ce qui l'a cassé.
          </p>
        </div>
      </template>

      <template v-else>
        <h2
          data-anim="claim"
          class="mt-18 max-w-[31rem] text-[2.75rem] font-bold leading-[1.02] tracking-[-0.02em] [font-stretch:78%] [text-wrap:balance]"
        >
          Trois arrêts, et l'équipe est à bord.
        </h2>

        <ol data-anim="claim" class="mt-12 flex flex-col">
          <li
            v-for="(etape, index) in ETAPES"
            :key="etape.titre"
            class="relative flex gap-6.5 pb-11 last:pb-0"
          >
            <!-- Le tronçon vers l'arrêt suivant : plein jusqu'où l'on va tout
                 de suite, pâle vers ce qui peut attendre. -->
            <span
              v-if="index < ETAPES.length - 1"
              class="absolute bottom-0 left-2 top-5.5 w-1.5"
              :class="ETAPES[index + 1].proche ? 'bg-ink' : 'bg-line-2'"
              aria-hidden="true"
            />
            <span
              class="relative size-5.5 shrink-0 border-[5px] bg-panel"
              :class="etape.proche ? 'border-ink' : 'border-line-2'"
              aria-hidden="true"
            />
            <span class="flex flex-col gap-1">
              <span class="label-caps">{{ etape.quand }}</span>
              <span class="text-lg font-semibold" :class="etape.proche ? '' : 'text-ink-2'">
                {{ etape.titre }}
              </span>
              <span
                class="max-w-[26rem] text-sm"
                :class="etape.proche ? 'text-ink-2' : 'text-ink-3'"
              >
                {{ etape.texte }}
              </span>
            </span>
          </li>
        </ol>
      </template>
    </aside>

    <!-- ============================ FORMULAIRE ============================ -->
    <div class="flex min-w-0 flex-1 flex-col px-5 py-10 sm:px-10">
      <!-- La marque n'apparaît ici QUE sur mobile, où le volet de gauche est
           masqué : la montrer deux fois sur grand écran ferait douter qu'il
           s'agit de la même page. -->
      <header class="flex shrink-0 flex-col items-center gap-3 lg:hidden">
        <RelaisMark class="size-12" />
        <p class="text-lg font-extrabold tracking-[-0.01em]">Relais</p>
      </header>

      <!-- Centré verticalement sur grand écran, où la colonne est seule dans
           sa moitié. Sur mobile, la marque occupe déjà le haut : un centrage
           y pousserait le formulaire sous la ligne de flottaison. -->
      <main
        data-anim="panel"
        class="mt-10 flex w-full flex-1 flex-col justify-start lg:mt-0 lg:justify-center"
      >
        <div class="mx-auto w-full max-w-[26.25rem]">
          <RouterView v-slot="{ Component }">
            <Transition name="fade" mode="out-in">
              <component :is="Component" />
            </Transition>
          </RouterView>
        </div>
      </main>

      <footer
        data-anim="foot"
        class="mt-10 flex shrink-0 flex-wrap items-center justify-center gap-x-4 gap-y-1 text-[0.8rem] text-ink-3 lg:mt-0"
      >
        <RouterLink :to="{ name: 'terms' }" class="transition-colors hover:text-ink-2">
          Conditions générales
        </RouterLink>
      </footer>
    </div>
  </div>
</template>
