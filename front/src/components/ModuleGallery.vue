<script setup>
/**
 * État des modules : spirale ou liste.
 *
 * Ce n'est PAS un second menu — la navigation appartient au menu latéral.
 * Chaque tuile porte l'état réel de son module : combien de travail ouvert,
 * et ce qui demande attention. Sans cela, le tableau de bord se contenterait
 * de répéter la liste de gauche.
 *
 * Les MÊMES éléments du DOM servent aux deux dispositions — seules leurs
 * classes et leurs positions changent. C'est ce qui permet à Flip d'animer
 * le passage : chaque tuile glisse de son ancienne place vers la nouvelle,
 * au lieu de disparaître d'un côté pour réapparaître de l'autre.
 *
 * La spirale n'est qu'un arrangement visuel. Dans le DOM, les tuiles restent
 * des liens dans l'ordre du catalogue : la navigation au clavier et les
 * lecteurs d'écran suivent cet ordre, pas la courbe.
 */
import { computed, nextTick, onMounted, ref } from 'vue'

import { Flip, gsap, prefersReducedMotion } from '@/animations/gsap'
import AppIcon from '@/components/AppIcon.vue'
import { play } from '@/services/sound'
import { modulePath } from '@/utils/modules'

const props = defineProps({ modules: { type: Array, default: () => [] } })

const STORAGE_KEY = 'modules-view'
const MODES = ['spiral', 'list']
const root = ref(null)
const mode = ref(readStoredMode())

function readStoredMode() {
  try {
    const stored = localStorage.getItem(STORAGE_KEY)

    return MODES.includes(stored) ? stored : 'spiral'
  } catch {
    return 'spiral'
  }
}

/**
 * Spirale d'Archimède : r(θ) = r₀ + k·θ. Le rayon croît linéairement avec
 * l'angle, donc l'écart entre tuiles voisines reste régulier — une spirale
 * logarithmique les tasserait au centre et les disperserait à l'extérieur.
 *
 * Quatre contraintes fixent les constantes, aucune n'est choisie à vue.
 *
 *  1. CONTENANCE — après recentrage, la figure entière tient dans le cadre.
 *     C'est la BOÎTE ENGLOBANTE qui compte, pas le rayon maximal : la spirale
 *     étant recentrée, son point le plus lointain n'est pas à 50 du bord.
 *     Ici, demi-boîte 32,7 + demi-tuile 12 = 44,7 ≤ 50.
 *
 *  2. SÉPARATION — deux tuiles voisines ne se chevauchent pas. La corde entre
 *     elles vaut 2·r·sin(TURN/2) ; il la faut supérieure à la largeur d'une
 *     tuile, Y COMPRIS au rayon le plus faible, qui est le cas critique.
 *     Ici, au plus près du centre : 2 × 27,6 × sin(38,7°) ≈ 34,5 pour des
 *     tuiles de 24.
 *
 *  3. CONTINUITÉ — le pas angulaire doit rester assez petit pour que l'œil
 *     suive la courbe d'une tuile à la suivante. Un réglage antérieur à 130°
 *     satisfaisait les deux premières contraintes mais pas celle-ci : sur cinq
 *     tuiles, un pas aussi large fait faire un tour et demi et disperse les
 *     points au lieu de tracer un balayage. 77° enchaîne 310° d'un seul geste.
 *
 *  4. REMPLISSAGE — la figure doit OCCUPER son cadre. Un réglage antérieur
 *     (R0 = 18, K = 2,8) respectait les trois premières contraintes mais ne
 *     couvrait que 44 % de la hauteur : le cadre carré réservait 670 px pour
 *     une figure qui en utilisait 300, et le tableau de bord s'ouvrait sur du
 *     vide. Les rayons sont donc dimensionnés pour que la boîte englobante
 *     remplisse le cadre — 89 % en largeur, 80 % en hauteur, le reste étant
 *     la marge que réclament les tuiles elles-mêmes.
 */
const TURN = 1.35 // écart angulaire entre deux tuiles, en radians (77°)
const R0 = 25 // rayon de départ, en % du conteneur
const K = 3.9 // croissance du rayon par radian

/** Position brute d'une tuile sur la courbe, avant recentrage. */
function pointAt(index) {
  // −π/2 place la première tuile EN HAUT du cadre : sans ce décalage, l'angle
  // nul d'un cercle trigonométrique la mettrait à droite, et la lecture de la
  // spirale ne commencerait pas là où l'œil se pose.
  const angle = index * TURN - Math.PI / 2
  const radius = R0 + K * (index * TURN)

  return { angle, x: radius * Math.cos(angle), y: radius * Math.sin(angle) }
}

/**
 * Décalage de recentrage.
 *
 * Une spirale n'est pas centrée sur son origine : la matière se concentre
 * d'un côté. Placer l'origine au milieu du cadre laisserait un grand vide
 * d'un bord et un débordement de l'autre. On centre donc la BOÎTE
 * ENGLOBANTE des tuiles, pas la courbe.
 */
const offset = computed(() => {
  const points = props.modules.map((_, index) => pointAt(index))

  if (points.length === 0) return { x: 0, y: 0 }

  const xs = points.map((point) => point.x)
  const ys = points.map((point) => point.y)

  return {
    x: -(Math.min(...xs) + Math.max(...xs)) / 2,
    y: -(Math.min(...ys) + Math.max(...ys)) / 2,
  }
})

const layout = computed(() =>
  props.modules.map((module, index) => {
    const { angle, x, y } = pointAt(index)

    return {
      module,
      // Coordonnées en pourcentage : la spirale suit la taille du conteneur,
      // qui reste carré — sinon les pourcentages horizontaux et verticaux ne
      // représenteraient plus la même distance et la courbe s'aplatirait.
      x: 50 + x + offset.value.x,
      y: 50 + y + offset.value.y,
      // Les tuiles grandissent vers l'extérieur : le regard suit la courbe
      // dans le sens de lecture du catalogue.
      scale: 0.88 + index * 0.03,
      // Inclinaison tangentielle, BORNÉE. Sans borne, l'angle cumulé dépasse
      // 200° sur la dernière tuile et le texte devient illisible pour un gain
      // purement décoratif.
      rotation: Math.max(-5, Math.min(5, (((angle * 180) / Math.PI) % 360) * 0.04)),
    }
  }),
)

/** Tracé de la courbe, échantillonné finement pour rester lisse. */
const spiralPath = computed(() => {
  const points = []
  const last = Math.max(props.modules.length - 1, 0) * TURN
  // Le MÊME décalage que les tuiles : sans lui, la courbe ne passerait plus
  // par elles et l'arrangement paraîtrait accidentel.
  const { x: dx, y: dy } = offset.value

  for (let theta = 0; theta <= last + 0.3; theta += 0.05) {
    const angle = theta - Math.PI / 2
    const radius = R0 + K * theta

    points.push(
      `${(50 + radius * Math.cos(angle) + dx).toFixed(2)},${(50 + radius * Math.sin(angle) + dy).toFixed(2)}`,
    )
  }

  return points.length ? `M${points.join(' L')}` : ''
})

/** Couleur d'un signal — « alerte » est la seule qui doit accrocher l'œil. */
const TONES = {
  alert: 'text-brick',
  good: 'text-moss',
  neutral: 'text-ink-2',
}

const toneOf = (signal) => TONES[signal.tone] ?? TONES.neutral

/** Un module qui réclame une action se signale aussi par son cadre. */
const hasAlert = (module) => (module.signals ?? []).some((signal) => signal.tone === 'alert')

async function setMode(next) {
  if (next === mode.value) return

  play(next === 'spiral' ? 'switchOn' : 'switchOff')

  const state =
    prefersReducedMotion() || !root.value
      ? null
      : Flip.getState(root.value.querySelectorAll('[data-tile]'))

  mode.value = next

  try {
    localStorage.setItem(STORAGE_KEY, next)
  } catch {
    /* stockage indisponible : le choix vaut pour la session */
  }

  if (!state) return

  await nextTick()

  Flip.from(state, { duration: 0.72, ease: 'appEnter', stagger: 0.045, absolute: true })
}

onMounted(() => {
  if (prefersReducedMotion() || !root.value) return

  // Seule l'opacité est animée. En mode spirale, chaque tuile porte sa propre
  // échelle et sa propre rotation dans un transform en ligne : animer « scale »
  // ferait réécrire ce transform par GSAP, et la spirale s'aplatirait.
  gsap.fromTo(
    root.value.querySelectorAll('[data-tile]'),
    { opacity: 0 },
    { opacity: 1, duration: 0.6, stagger: 0.07, ease: 'appEnter', overwrite: 'auto' },
  )
})
</script>

<template>
  <section ref="root">
    <header class="mb-5 flex flex-wrap items-center justify-between gap-3">
      <h3 class="text-[0.95rem] font-semibold">état des modules</h3>

      <!-- Bascule : deux boutons plutôt qu'un interrupteur, pour que le mode
           courant se lise sans avoir à l'interpréter. -->
      <div
        class="flex items-center gap-0.5 rounded-pill border border-line bg-panel p-0.5"
        role="group"
        aria-label="Disposition des modules"
      >
        <button
          v-for="option in [
            { value: 'spiral', label: 'spirale' },
            { value: 'list', label: 'liste' },
          ]"
          :key="option.value"
          type="button"
          class="rounded-pill px-3 py-1 text-[0.75rem] transition-colors"
          :class="
            mode === option.value
              ? 'bg-ink text-paper'
              : 'text-ink-2 hover:bg-raised hover:text-ink'
          "
          :aria-pressed="mode === option.value"
          @click="setMode(option.value)"
        >
          {{ option.label }}
        </button>
      </div>
    </header>

    <!-- ============================ SPIRALE ============================ -->
    <div v-if="mode === 'spiral'" class="relative mx-auto aspect-square w-full max-w-2xl">
      <!-- Courbe guide : sans elle, les tuiles paraissent éparpillées plutôt
           qu'arrangées. -->
      <svg
        class="pointer-events-none absolute inset-0 size-full"
        viewBox="0 0 100 100"
        preserveAspectRatio="none"
        aria-hidden="true"
      >
        <path
          :d="spiralPath"
          fill="none"
          stroke="currentColor"
          stroke-width="1"
          stroke-dasharray="4 5"
          class="text-ink-3/50"
          vector-effect="non-scaling-stroke"
        />
      </svg>

      <RouterLink
        v-for="(tile, index) in layout"
        :key="tile.module.id"
        data-tile
        :to="modulePath(tile.module.slug)"
        class="group absolute w-[24%] rounded-card border bg-panel p-3 transition-colors hover:bg-raised"
        :class="
          hasAlert(tile.module)
            ? 'border-brick/50 hover:border-brick'
            : 'border-line hover:border-ink-3'
        "
        :style="{
          left: `${tile.x}%`,
          top: `${tile.y}%`,
          transform: `translate(-50%, -50%) scale(${tile.scale}) rotate(${tile.rotation}deg)`,
          zIndex: index + 1,
        }"
      >
        <div class="flex items-start justify-between gap-2">
          <span
            class="flex size-8 items-center justify-center rounded-pill border border-line bg-raised text-ink"
          >
            <AppIcon :name="tile.module.icon" :size="15" />
          </span>
          <span class="label-caps tabular-nums">{{ String(index + 1).padStart(2, '0') }}</span>
        </div>

        <p class="mt-2.5 truncate text-[0.84rem] font-semibold lowercase">{{ tile.module.name }}</p>

        <!-- Le chiffre est l'information principale de la tuile : c'est lui
             qui dit où en est le module. -->
        <p class="mt-1 text-[0.72rem] text-ink-2 tabular-nums">
          <span class="text-[0.95rem] font-semibold text-ink">{{ tile.module.items_count }}</span>
          {{ tile.module.unit }}
        </p>

        <p
          v-for="signal in tile.module.signals"
          :key="signal.label"
          class="mt-0.5 text-[0.68rem] tabular-nums"
          :class="toneOf(signal)"
        >
          {{ signal.value }} {{ signal.label }}
        </p>
      </RouterLink>
    </div>

    <!-- ============================= LISTE ============================= -->
    <div v-else class="flex flex-col gap-2">
      <RouterLink
        v-for="(tile, index) in layout"
        :key="tile.module.id"
        data-tile
        :to="modulePath(tile.module.slug)"
        class="group flex items-center gap-4 rounded-card border bg-panel p-4 transition-colors hover:bg-raised"
        :class="
          hasAlert(tile.module)
            ? 'border-brick/50 hover:border-brick'
            : 'border-line hover:border-ink-3'
        "
      >
        <span class="label-caps w-6 shrink-0 tabular-nums">
          {{ String(index + 1).padStart(2, '0') }}
        </span>

        <span
          class="flex size-9 shrink-0 items-center justify-center rounded-pill border border-line bg-raised text-ink"
        >
          <AppIcon :name="tile.module.icon" :size="17" />
        </span>

        <span class="min-w-0 flex-1">
          <span class="block text-[0.9rem] font-semibold lowercase">{{ tile.module.name }}</span>
          <span class="mt-0.5 line-clamp-1 block text-[0.78rem] text-ink-2">
            {{ tile.module.description }}
          </span>
        </span>

        <span class="flex shrink-0 items-center gap-3 text-[0.72rem] tabular-nums">
          <span
            v-for="signal in tile.module.signals"
            :key="signal.label"
            class="hidden sm:inline"
            :class="toneOf(signal)"
          >
            {{ signal.value }} {{ signal.label }}
          </span>

          <span class="text-ink-2">
            <span class="text-[0.9rem] font-semibold text-ink">{{ tile.module.items_count }}</span>
            {{ tile.module.unit }}
          </span>
        </span>

        <AppIcon
          name="arrow-right"
          :size="16"
          class="shrink-0 text-ink-3 transition-transform group-hover:translate-x-0.5"
        />
      </RouterLink>
    </div>
  </section>
</template>
