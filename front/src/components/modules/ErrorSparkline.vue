<script setup>
/**
 * Occurrences d'erreurs par jour, sur la période reçue.
 *
 * SÉRIE UNIQUE : c'est une aire et non une ligne nue, et il n'y a pas de
 * légende — le titre à côté nomme déjà la série. Une légende d'un seul
 * élément n'apprend rien et occupe de la place.
 *
 * La teinte est celle des MARQUES de graphique (--c-chart-alert), pas celle
 * du texte de statut : sur fond noir, la couleur de statut sort de la bande
 * de clarté admise pour un aplat et éblouit. Les deux thèmes ont chacun leur
 * pas, mesuré plutôt que déduit par inversion.
 *
 * Le texte ne porte jamais la couleur de la série : les libellés restent en
 * encre, et c'est la marque colorée à côté qui porte l'identité.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue'

const props = defineProps({
  /** [{ date: 'AAAA-MM-JJ', count: n }] */
  points: { type: Array, default: () => [] },
})

// Repère fixe : les proportions sont préservées à l'affichage, et le tracé
// garde son épaisseur grâce à vector-effect.
const W = 280
const H = 44
const PAD = 4

const hovered = ref(null)

/** Le maximum sert d'échelle. À zéro partout, la courbe est plate au bas. */
const max = computed(() => Math.max(1, ...props.points.map((point) => point.count)))

const coords = computed(() => {
  const count = props.points.length

  if (count === 0) return []

  const span = count > 1 ? (W - PAD * 2) / (count - 1) : 0

  return props.points.map((point, index) => ({
    ...point,
    x: PAD + index * span,
    y: H - PAD - (point.count / max.value) * (H - PAD * 2),
  }))
})

const linePath = computed(() =>
  coords.value.length
    ? `M${coords.value.map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' L')}`
    : '',
)

/** La MÊME courbe, refermée sur la ligne de base : l'aire ne peut pas
 *  diverger du tracé puisqu'elle en est dérivée. */
const areaPath = computed(() => {
  if (!coords.value.length) return ''

  const first = coords.value[0]
  const last = coords.value[coords.value.length - 1]

  return `${linePath.value} L${last.x.toFixed(1)},${H - PAD} L${first.x.toFixed(1)},${H - PAD} Z`
})

const lastPoint = computed(() => coords.value[coords.value.length - 1] ?? null)

/** Largeur d'une zone de survol : plus large que la marque, pour être
 *  atteignable sans viser. */
const hitWidth = computed(() =>
  coords.value.length > 1 ? (W - PAD * 2) / (coords.value.length - 1) : W,
)

const formatDay = (date) =>
  new Date(`${date}T00:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })

const total = computed(() => props.points.reduce((sum, point) => sum + point.count, 0))

// --- Tracé à l'arrivée des données -------------------------------------------
//
// La courbe se dessine UNE FOIS, quand les données arrivent : c'est ce qui la
// distingue d'un décor. Elle ne rejoue ni au survol ni au rafraîchissement des
// compteurs.
//
// Sans bibliothèque : un trait qui se dessine, c'est un pointillé de la
// longueur du tracé dont on ramène le décalage à zéro. `getTotalLength()` la
// donne, une transition CSS fait le reste. GSAP a un plugin pour cela
// (DrawSVG) mais il a été retiré du lot — le garder pour une seule courbe
// coûterait plus que ces huit lignes.

const line = ref(null)
let drawn = false

async function draw() {
  if (drawn || !props.points.length) return

  await nextTick()

  const path = line.value

  if (!path || typeof path.getTotalLength !== 'function') return

  drawn = true

  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches
  const length = path.getTotalLength()

  if (reduced || length === 0) return

  path.style.strokeDasharray = String(length)
  path.style.strokeDashoffset = String(length)
  // Lecture forcée du style calculé : sans elle, le navigateur regroupe les
  // deux écritures et il n'y a aucune transition à animer.
  void path.getBoundingClientRect()
  path.style.transition = 'stroke-dashoffset 900ms cubic-bezier(0.16, 1, 0.3, 1)'
  path.style.strokeDashoffset = '0'
}

onMounted(draw)
watch(() => props.points.length, draw)
</script>

<template>
  <figure class="relative m-0 w-full max-w-70">
    <figcaption class="sr-only">
      Occurrences d'erreurs par jour sur les {{ points.length }} derniers jours, {{ total }} au
      total.
    </figcaption>

    <svg
      :viewBox="`0 0 ${W} ${H}`"
      class="h-11 w-full"
      preserveAspectRatio="xMidYMid meet"
      role="img"
      aria-hidden="true"
    >
      <!-- Ligne de base : discrète, elle situe le zéro sans concurrencer la
           courbe. -->
      <line
        :x1="PAD"
        :y1="H - PAD"
        :x2="W - PAD"
        :y2="H - PAD"
        stroke="currentColor"
        class="text-line"
        stroke-width="1"
        vector-effect="non-scaling-stroke"
      />

      <path :d="areaPath" fill="currentColor" class="text-chart-alert" opacity="0.16" />

      <path
        ref="line"
        :d="linePath"
        fill="none"
        stroke="currentColor"
        class="text-chart-alert"
        stroke-width="2"
        stroke-linejoin="round"
        stroke-linecap="round"
        vector-effect="non-scaling-stroke"
      />

      <!-- Extrémité mise en avant : c'est la valeur d'aujourd'hui, la seule
           qu'on lise vraiment sur une courbe de cette taille. -->
      <circle
        v-if="lastPoint"
        :cx="lastPoint.x"
        :cy="lastPoint.y"
        r="3.2"
        fill="currentColor"
        class="text-chart-alert"
        stroke="var(--c-panel)"
        stroke-width="1.5"
      />

      <!-- Point survolé, souligné par un anneau de la couleur du fond : il
           se détache même posé sur l'aire. -->
      <circle
        v-if="hovered !== null && coords[hovered]"
        :cx="coords[hovered].x"
        :cy="coords[hovered].y"
        r="3.6"
        fill="currentColor"
        class="text-chart-alert"
        stroke="var(--c-panel)"
        stroke-width="2"
      />

      <!-- Zones de survol : une par jour, sur toute la hauteur. -->
      <rect
        v-for="(point, index) in coords"
        :key="point.date"
        :x="point.x - hitWidth / 2"
        y="0"
        :width="hitWidth"
        :height="H"
        fill="transparent"
        @mouseenter="hovered = index"
        @mouseleave="hovered = null"
      />
    </svg>

    <!-- Infobulle en HTML plutôt qu'en SVG : elle hérite ainsi de la
         typographie et des jetons de couleur du reste de l'interface. -->
    <div
      v-if="hovered !== null && coords[hovered]"
      class="pointer-events-none absolute -top-1 z-10 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-field border border-line bg-panel px-2 py-1 text-[0.68rem] text-ink shadow-sm"
      :style="{ left: `${(coords[hovered].x / W) * 100}%` }"
    >
      <span class="text-ink-3">{{ formatDay(coords[hovered].date) }}</span>
      <span class="ml-1.5 font-semibold tabular-nums">{{ coords[hovered].count }}</span>
    </div>

    <!-- Repli tabulaire : la courbe est décorative pour un lecteur d'écran,
         les chiffres, eux, doivent rester atteignables. -->
    <table class="sr-only">
      <caption>
        Occurrences par jour
      </caption>
      <thead>
        <tr>
          <th scope="col">Jour</th>
          <th scope="col">Occurrences</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="point in points" :key="point.date">
          <th scope="row">{{ formatDay(point.date) }}</th>
          <td>{{ point.count }}</td>
        </tr>
      </tbody>
    </table>
  </figure>
</template>
