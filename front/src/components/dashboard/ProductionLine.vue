<script setup>
/**
 * La ligne de production : les dernières 24 heures sur un seul axe.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  UNE CAUSE ET SON EFFET, SANS QUE PERSONNE AIT À LES RELIER             │
 * │                                                                         │
 * │  Les mises en production sont les stations de la ligne ; les erreurs,   │
 * │  des barres par heure au-dessus. Une station en échec suivie d'un pic   │
 * │  se lit d'un coup d'œil — la même information, en deux courbes          │
 * │  quotidiennes dans deux cadres, demandait de comparer des dates.        │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Dessinée en boîtes positionnées plutôt qu'en SVG : les étiquettes gardent
 * leur taille quand le cadre s'élargit, là où un SVG mis à l'échelle les
 * étirerait. Les positions passent par des styles liés, que Vue pose par le
 * CSSOM — un attribut « style » écrit en dur serait refusé par la politique
 * de sécurité du contenu.
 *
 * Le dessin est muet pour un lecteur d'écran ; la table qui le suit porte les
 * mêmes faits, en phrases.
 */
import { computed } from 'vue'

import { barHeights, hourTicks, labelShift, placeStations } from '@/utils/dashboard'

const props = defineProps({
  line: { type: Object, required: true },
})

/** Hauteur de la barre la plus haute, en pixels : celle de sa zone. */
const BAR_MAX = 38

const STATUTS = {
  ready: 'réussie',
  error: 'en échec',
  canceled: 'annulée',
  queued: 'en attente',
  building: 'en cours',
}

const ticks = computed(() => hourTicks(props.line.from, props.line.to))
const heights = computed(() => barHeights(props.line.hours, BAR_MAX))
const stations = computed(() =>
  placeStations(props.line.deployments, props.line.from, props.line.to),
)

const totalErrors = computed(() => props.line.hours.reduce((total, n) => total + n, 0))
const lastHour = computed(() => props.line.hours.at(-1) ?? 0)
const empty = computed(() => props.line.deployments.length === 0 && totalErrors.value === 0)

const placed = (position) => ({ left: `${position * 100}%` })

const anchored = (position) => ({
  left: `${position * 100}%`,
  transform: `translateX(${labelShift(position)})`,
})

const heure = (iso) =>
  new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
</script>

<template>
  <section class="card px-4.5 pb-2 pt-3.5" aria-labelledby="ligne-production">
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
      <h2 id="ligne-production" class="label-caps">Ligne de production · dernières 24 h</h2>

      <div class="flex flex-wrap items-center gap-4.5 text-xs text-ink-3" aria-hidden="true">
        <span class="flex items-center gap-1.75">
          <span class="size-2.5 border-2 border-ink" />
          mise en production
        </span>
        <span class="flex items-center gap-1.75">
          <span class="h-2.75 w-1 bg-mod-supervision" />
          erreurs par heure
        </span>
        <span class="flex items-center gap-1.75">
          <span class="size-1.75 rounded-pill bg-brick" />
          échec
        </span>
      </div>
    </div>

    <div class="relative mt-2 h-26" aria-hidden="true">
      <span
        v-for="tick in ticks"
        :key="tick.position"
        class="absolute top-0 whitespace-nowrap font-mono text-[0.6875rem] text-ink-3"
        :style="anchored(tick.position)"
      >
        {{ tick.label }}
      </span>

      <div class="absolute inset-x-0 top-5 flex h-9.5 items-end gap-0.75">
        <span
          v-for="(height, index) in heights"
          :key="index"
          class="flex-1 bg-mod-supervision"
          :style="{ height: `${height}px` }"
        />
      </div>

      <span class="absolute inset-x-0 top-16 h-1.5 rounded-pill bg-mod-deploiement" />

      <div v-for="station in stations" :key="station.id" class="contents">
        <span
          class="absolute top-[3.75rem] size-3.5 -translate-x-1/2 border-[3px] border-ink bg-panel"
          :style="placed(station.position)"
        />
        <span
          v-if="station.failed"
          class="absolute top-13 size-2 translate-x-[5px] rounded-pill bg-brick"
          :style="placed(station.position)"
        />
        <span
          v-if="station.labelled"
          class="absolute top-21 whitespace-nowrap font-mono text-[0.6875rem]"
          :class="station.failed ? 'text-brick' : 'text-ink-2'"
          :style="anchored(station.position)"
        >
          {{ station.failed ? `${station.sha} · échec` : station.sha }}
        </span>
      </div>

      <p v-if="empty" class="absolute inset-x-0 top-8 text-center text-[0.8125rem] text-ink-3">
        Ni mise en production ni erreur depuis 24 heures.
      </p>
    </div>

    <table class="sr-only">
      <caption>
        Mises en production et erreurs des dernières 24 heures
      </caption>
      <thead>
        <tr>
          <th scope="col">Heure</th>
          <th scope="col">Fait</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="station in stations" :key="station.id">
          <td>{{ heure(station.at) }}</td>
          <td>
            Mise en production {{ station.sha }} depuis {{ station.branch }} :
            {{ STATUTS[station.status] ?? station.status }}
          </td>
        </tr>
        <tr>
          <td>24 heures</td>
          <td>{{ totalErrors }} erreurs, dont {{ lastHour }} dans la dernière heure</td>
        </tr>
      </tbody>
    </table>
  </section>
</template>
