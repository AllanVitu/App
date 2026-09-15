<script setup>
/**
 * L'état EN LIGNE de chaque branche, avec son URL.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  LA PROMESSE QUE CE PANNEAU TIENT                                   │
 * │                                                                     │
 * │  Le module annonce « des mises en production liées à Git, avec une  │
 * │  URL de prévisualisation PAR BRANCHE ». Le tableau, lui, range les  │
 * │  déploiements par statut : on y voyait quatre lignes « en ligne »   │
 * │  sans jamais savoir laquelle est l'adresse courante d'une branche.  │
 * │                                                                     │
 * │  Or c'est la question qu'on se pose vraiment devant cet écran :     │
 * │  « où est ma branche en ce moment ? » — pas « combien de           │
 * │  déploiements ont réussi cette semaine ».                           │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * UNE SEULE LIGNE PAR BRANCHE, la plus récente. Un déploiement est un
 * événement : les vingt tentatives passées d'une branche appartiennent au
 * tableau, pas ici. Ce panneau répond à « où en est-on », le tableau à
 * « que s'est-il passé ».
 *
 * Le statut affiché est celui du dernier déploiement, même s'il a échoué :
 * masquer un échec derrière la dernière URL qui a marché ferait croire la
 * branche saine.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  UNE HAUTEUR BORNÉE, PARCE QUE LES BRANCHES S'ACCUMULENT            │
 * │                                                                     │
 * │  Le panneau grandissait d'une ligne par branche, sans limite. À     │
 * │  dix-huit branches, il occupait tout l'écran et écrasait le tableau │
 * │  des déploiements à une hauteur nulle : ses cartes n'étaient plus   │
 * │  atteignables. Constaté le 14 septembre 2026, quand deux parcours   │
 * │  navigateur ont échoué pour cette seule raison — une équipe qui     │
 * │  ouvre une branche par fonctionnalité y serait arrivée en un mois.  │
 * │                                                                     │
 * │  La liste défile désormais dans son cadre, et le tableau garde sa   │
 * │  place quel que soit le nombre de branches.                         │
 * └─────────────────────────────────────────────────────────────────────┘
 */
import { computed } from 'vue'

import AppIcon from '@/components/AppIcon.vue'

const props = defineProps({
  /** Déploiements chargés, tous statuts confondus. */
  deployments: { type: Array, required: true },
  /** Vocabulaire du module : [{ value, label, tone, dot }]. */
  statuses: { type: Array, required: true },
})

const statusOf = (value) =>
  props.statuses.find((status) => status.value === value) ?? props.statuses[0]

/**
 * Dernier déploiement de chaque branche.
 *
 * La liste arrive déjà triée du plus récent au plus ancien (cf.
 * DeploymentRepository) : le PREMIER rencontré pour une branche est donc le
 * bon. Trier de nouveau ici referait un travail déjà fait, et surtout
 * dupliquerait une règle d'ordre qui n'appartient pas à ce composant.
 */
const branches = computed(() => {
  const latest = new Map()

  for (const row of props.deployments) {
    if (!latest.has(row.branch)) latest.set(row.branch, row)
  }

  return [...latest.values()]
})
</script>

<template>
  <section v-if="branches.length" class="card shrink-0 overflow-hidden">
    <header class="flex items-baseline gap-2 border-b border-line px-4 py-2">
      <h3 id="etat-par-branche" class="label-caps">état par branche</h3>
      <span class="text-[0.7rem] tabular-nums text-ink-3">{{ branches.length }}</span>
    </header>

    <!-- Défilement DANS le cadre. « tabindex » rend la zone atteignable au
         clavier : une région qui défile sans pouvoir recevoir le focus ne
         défile qu'à la souris. -->
    <ul
      class="max-h-48 divide-y divide-line overflow-y-auto"
      tabindex="0"
      aria-labelledby="etat-par-branche"
    >
      <li
        v-for="row in branches"
        :key="row.branch"
        class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2"
      >
        <span
          class="size-2 shrink-0 rounded-pill"
          :class="statusOf(row.status).dot"
          aria-hidden="true"
        />

        <span class="min-w-0 flex-1 truncate font-mono text-[0.78rem]">{{ row.branch }}</span>

        <span
          class="shrink-0 text-[0.7rem]"
          :class="row.environment === 'production' ? 'font-semibold text-ink' : 'text-ink-3'"
        >
          {{ row.environment === 'production' ? 'production' : 'prévisu.' }}
        </span>

        <span class="w-20 shrink-0 text-right text-[0.72rem]" :class="statusOf(row.status).tone">
          {{ statusOf(row.status).label }}
        </span>

        <!-- L'URL n'existe que sur un déploiement abouti. Réserver sa place
             évite que les lignes sans adresse ne se terminent ailleurs que
             les autres. -->
        <a
          v-if="row.url"
          :href="row.url"
          target="_blank"
          rel="noopener noreferrer"
          class="chip w-28 shrink-0 justify-center border-line text-ink-2 transition-colors hover:border-ink-3 hover:text-ink"
        >
          ouvrir
          <AppIcon name="arrow-right" :size="12" />
        </a>
        <span v-else class="w-28 shrink-0 text-right text-[0.7rem] text-ink-3">aucune URL</span>
      </li>
    </ul>
  </section>
</template>
