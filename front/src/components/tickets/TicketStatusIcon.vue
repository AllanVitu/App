<script setup>
/**
 * Glyphe de statut : un anneau qui se remplit à mesure que le ticket avance.
 *
 * Une seule figure pour les cinq états, plutôt que cinq icônes sans rapport.
 * Le remplissage EST l'information — on lit l'avancement d'un coup d'œil sur
 * une colonne entière, sans avoir à déchiffrer chaque symbole.
 *
 * L'arc est tracé avec stroke-dasharray sur un cercle : la portion visible
 * du trait donne la fraction remplie, sans calcul de chemin.
 */
import { computed } from 'vue'

import { statusOf } from '@/utils/tickets'

const props = defineProps({
  status: { type: String, required: true },
  size: { type: [Number, String], default: 14 },
})

const RADIUS = 6
const CIRCUMFERENCE = 2 * Math.PI * RADIUS

/** Fraction de l'anneau remplie, par statut. */
const FILL = {
  backlog: 0,
  todo: 0,
  in_progress: 0.5,
  done: 1,
  canceled: 0,
}

const meta = computed(() => statusOf(props.status))

const fill = computed(() => FILL[props.status] ?? 0)

/**
 * « En attente » se distingue de « à faire » par un contour pointillé : le
 * travail n'est pas encore engagé. Les deux ont un anneau vide, la nuance
 * doit donc passer par le trait.
 */
const dashed = computed(() => props.status === 'backlog')
</script>

<template>
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 16 16"
    fill="none"
    :class="meta.tone"
    role="img"
    :aria-label="meta.label"
  >
    <circle
      cx="8"
      cy="8"
      :r="RADIUS"
      stroke="currentColor"
      stroke-width="1.6"
      :stroke-dasharray="dashed ? '2.2 2.2' : undefined"
      :opacity="dashed ? 0.75 : 1"
    />

    <!-- Arc de progression : démarré en haut (rotation -90°) pour que le
         remplissage se lise dans le sens horaire, comme une horloge. -->
    <circle
      v-if="fill > 0 && fill < 1"
      cx="8"
      cy="8"
      :r="RADIUS"
      stroke="currentColor"
      stroke-width="3.2"
      :stroke-dasharray="`${fill * CIRCUMFERENCE} ${CIRCUMFERENCE}`"
      transform="rotate(-90 8 8)"
    />

    <!-- Terminé : disque plein plutôt qu'un arc complet, plus franc à 14 px. -->
    <circle v-if="fill === 1" cx="8" cy="8" r="3.4" fill="currentColor" />

    <!-- Annulé : barre oblique. Le ticket a été fermé sans être fait. -->
    <path
      v-if="status === 'canceled'"
      d="M5.5 10.5 10.5 5.5"
      stroke="currentColor"
      stroke-width="1.6"
    />
  </svg>
</template>
