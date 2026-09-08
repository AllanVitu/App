<script setup>
/**
 * Courbe de tendance quotidienne — UNE série, UNE échelle.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  CE COMPOSANT NE TRACE QU'UNE SÉRIE, ET CE N'EST PAS UNE LIMITE.    │
 * │                                                                     │
 * │  Le tableau de bord affiche des déploiements (0 à 5 par jour) et    │
 * │  des erreurs (0 à 40). Superposés sur une échelle unique, les       │
 * │  déploiements sont écrasés contre l'axe et ne disent plus rien.     │
 * │  Sur deux échelles dans un même cadre, le point où les courbes se   │
 * │  croisent ne signifie RIEN — mais le lecteur y lit forcément        │
 * │  quelque chose, et ce quelque chose est faux.                       │
 * │                                                                     │
 * │  D'où deux cadres côte à côte, chacun avec son zéro et son maximum. │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Aucune bibliothèque : quatorze points, deux tracés et une grille tiennent
 * en un SVG écrit à la main. Importer un moteur de graphiques coûterait plus
 * cher que tout le reste de la page réunie.
 *
 * MISE À L'ÉCHELLE. Le SVG travaille dans un repère 0–100 étiré sans
 * conservation des proportions ; le trait garde son épaisseur grâce à
 * « vector-effect ». Ce qui doit rester rond — le point final, le curseur —
 * est en HTML positionné en pourcentage, et non en SVG : un cercle SVG
 * deviendrait une ellipse dès que le cadre n'est plus carré.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps({
  /** Nomme la série : c'est ce qui remplace une légende, inutile ici. */
  title: { type: String, required: true },
  /** @type {{ date: string, value: number }[]} */
  points: { type: Array, required: true },
  /** Jeton de couleur, sans le préfixe : « chart-1 » ou « chart-2 ». */
  color: { type: String, default: 'chart-1' },
  /** Unité, au pluriel — sert à l'infobulle et au résumé vocal. */
  unit: { type: String, default: '' },
})

const plot = ref(null)
const width = ref(0)
const hover = ref(null)

/** Marge haute et basse, en unités du repère : la place du trait de 2 px. */
const PAD = 5

const values = computed(() => props.points.map((point) => point.value))

/**
 * Le maximum est ARRONDI VERS LE HAUT à une valeur ronde, et le minimum est
 * toujours zéro.
 *
 * Un axe qui commencerait à la plus petite valeur observée transformerait une
 * variation de 3 à 5 en un mur : c'est la façon la plus courante de mentir
 * avec un graphique de comptage.
 */
const ceiling = computed(() => {
  const top = Math.max(...values.value, 0)

  if (top <= 5) return 5

  const magnitude = 10 ** Math.floor(Math.log10(top))
  const step = magnitude / 2

  return Math.ceil(top / step) * step
})

/** Abscisse d'un point, en pourcentage — sert au SVG comme au HTML. */
const xAt = (index) => (props.points.length > 1 ? (index / (props.points.length - 1)) * 100 : 50)

/** Ordonnée d'un point, en pourcentage depuis le haut. */
const yAt = (value) => PAD + (1 - value / ceiling.value) * (100 - PAD * 2)

const linePath = computed(() =>
  props.points.map((point, i) => `${i ? 'L' : 'M'}${xAt(i)} ${yAt(point.value)}`).join(' '),
)

/** Le même tracé refermé sur la ligne de base : la surface sous la courbe. */
const areaPath = computed(() => `${linePath.value} L100 ${yAt(0)} L0 ${yAt(0)} Z`)

const last = computed(() => props.points[props.points.length - 1] ?? null)

const total = computed(() => values.value.reduce((sum, value) => sum + value, 0))

/**
 * Au repos, le chiffre de tête est le TOTAL de la fenêtre — pas la valeur du
 * dernier point.
 *
 * Le dernier point est aujourd'hui, et aujourd'hui n'est pas terminé : à
 * 9 h du matin il vaut presque toujours zéro. Un cadre intitulé
 * « déploiements » qui annonce « 0 » en gros donne à croire qu'il ne se passe
 * rien, alors que la courbe juste en dessous en montre quarante.
 */
const active = computed(() => (hover.value === null ? null : (props.points[hover.value] ?? null)))

/** Le repère reste posé sur le dernier jour tant que rien n'est survolé. */
const markerIndex = computed(() => (hover.value === null ? props.points.length - 1 : hover.value))

const markerValue = computed(() => props.points[markerIndex.value]?.value ?? 0)

/** Date courte : « 4 sept. ». L'année n'apporte rien sur deux semaines. */
const shortDate = (iso) =>
  new Date(`${iso}T00:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })

/**
 * La largeur est mise en cache plutôt que relue à chaque déplacement.
 *
 * Lire « clientWidth » dans un gestionnaire de pointeur force le navigateur à
 * recalculer la mise en page — juste après que Vue vient d'écrire dans le
 * DOM. Alterner lecture et écriture des dizaines de fois par seconde est
 * exactement ce qui fait saccader une page.
 */
let observer = null

onMounted(() => {
  if (!plot.value) return

  observer = new ResizeObserver(([entry]) => {
    width.value = entry.contentRect.width
  })
  observer.observe(plot.value)
})

onBeforeUnmount(() => {
  observer?.disconnect()
  observer = null
})

function onMove(event) {
  if (!width.value || props.points.length < 2) return

  const ratio = event.offsetX / width.value

  hover.value = Math.min(
    props.points.length - 1,
    Math.max(0, Math.round(ratio * (props.points.length - 1))),
  )
}
</script>

<template>
  <figure class="card flex flex-col p-4">
    <figcaption class="flex items-baseline justify-between gap-3">
      <span class="label-caps">{{ title }}</span>
      <span class="text-[0.72rem] text-ink-3">14 jours</span>
    </figcaption>

    <!-- Une seule valeur affichée à la fois : un nombre sur chacun des
         quatorze points serait un tableau déguisé en courbe. Au repos le
         total de la fenêtre, au survol le jour pointé. -->
    <p class="mt-1 flex items-baseline gap-2">
      <span class="text-2xl font-semibold tabular-nums">
        {{ active ? active.value : total }}
      </span>
      <span class="truncate text-[0.72rem] text-ink-3">
        {{ unit }} · {{ active ? shortDate(active.date) : 'au total' }}
      </span>
    </p>

    <div
      ref="plot"
      class="relative mt-3 h-20 cursor-crosshair"
      @pointermove="onMove"
      @pointerleave="hover = null"
    >
      <svg
        class="size-full overflow-visible"
        viewBox="0 0 100 100"
        preserveAspectRatio="none"
        aria-hidden="true"
      >
        <!-- Grille en retrait : deux traits, le plancher et le plafond. Une
             grille qui se remarque autant que la donnée entre en concurrence
             avec elle. -->
        <line
          v-for="value in [0, ceiling]"
          :key="value"
          x1="0"
          x2="100"
          :y1="yAt(value)"
          :y2="yAt(value)"
          stroke="var(--c-line)"
          stroke-width="1"
          vector-effect="non-scaling-stroke"
        />

        <path :d="areaPath" :fill="`var(--c-${color})`" opacity="0.12" />

        <path
          :d="linePath"
          fill="none"
          :stroke="`var(--c-${color})`"
          stroke-width="2"
          stroke-linecap="round"
          stroke-linejoin="round"
          vector-effect="non-scaling-stroke"
        />

        <!-- Curseur vertical, sous le point : il situe sans masquer. -->
        <line
          v-if="hover !== null"
          :x1="xAt(hover)"
          :x2="xAt(hover)"
          y1="0"
          y2="100"
          stroke="var(--c-line-2)"
          stroke-width="1"
          vector-effect="non-scaling-stroke"
        />
      </svg>

      <!-- Point actif en HTML : un cercle SVG s'ovaliserait avec l'étirement
           du repère. Anneau de la couleur du fond pour qu'il se détache même
           posé sur le trait. -->
      <span
        v-if="points.length"
        class="pointer-events-none absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-pill ring-2 ring-panel"
        :style="{
          left: `${xAt(markerIndex)}%`,
          top: `${yAt(markerValue)}%`,
          background: `var(--c-${color})`,
        }"
      />

      <!-- Plafond de l'échelle, annoncé une fois. -->
      <span class="pointer-events-none absolute left-0 top-0 text-[0.68rem] text-ink-3">
        {{ ceiling }}
      </span>
    </div>

    <div class="mt-1.5 flex justify-between text-[0.68rem] text-ink-3">
      <span>{{ points.length ? shortDate(points[0].date) : '' }}</span>
      <span>{{ last ? shortDate(last.date) : '' }}</span>
    </div>

    <!-- Équivalent textuel : une courbe n'est pas lisible au lecteur d'écran,
         et un « aria-label » résumé ne donnerait pas les valeurs. Le tableau
         les donne toutes, sans occuper un pixel. -->
    <table class="sr-only">
      <caption>
        {{
          title
        }}
        , par jour
      </caption>
      <thead>
        <tr>
          <th scope="col">Date</th>
          <th scope="col">{{ unit }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="point in points" :key="point.date">
          <th scope="row">{{ shortDate(point.date) }}</th>
          <td>{{ point.value }}</td>
        </tr>
      </tbody>
    </table>
  </figure>
</template>
