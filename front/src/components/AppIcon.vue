<script setup>
/**
 * Jeu d'icônes SVG inline.
 *
 * Aucune dépendance ni requête réseau : les icônes héritent de la couleur du
 * texte (currentColor) et s'adaptent donc au thème sans configuration.
 * Le nom peut venir de la base (colonne modules.icon).
 */
import { computed } from 'vue'

const props = defineProps({
  name: { type: String, required: true },
  size: { type: [Number, String], default: 20 },
  strokeWidth: { type: [Number, String], default: 1.5 },
})

/** Chaque entrée est la liste des tracés composant l'icône. */
const ICONS = {
  home: ['M3 10.5 12 3l9 7.5V20a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z', 'M9 22v-9h6v9'],
  'layout-grid': ['M4 4h6v6H4z', 'M14 4h6v6h-6z', 'M4 14h6v6H4z', 'M14 14h6v6h-6z'],
  'chart-bar': ['M3 21h18', 'M7 21V11', 'M12 21V4', 'M17 21v-6'],
  folder: [
    'M4 21h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-7.5L10 4.5A2 2 0 0 0 8.6 4H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2z',
  ],
  sparkles: [
    'm12 3 1.9 5.6L19.5 10l-5.6 1.9L12 17.5l-1.9-5.6L4.5 10l5.6-1.4z',
    'M19 16v4',
    'M17 18h4',
  ],
  user: ['M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2', 'M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z'],
  // Le même buste, décalé et tronqué : un groupe se lit à la superposition,
  // pas au nombre de silhouettes.
  users: [
    'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2',
    'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
    'M22 21v-2a4 4 0 0 0-3-3.9',
    'M16 3.1a4 4 0 0 1 0 7.8',
  ],
  settings: [
    'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
    'M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
  ],
  logout: ['M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4', 'm16 17 5-5-5-5', 'M21 12H9'],
  // Un mât et ses ondes : une sonde émet, puis attend qu'on lui réponde.
  signal: [
    'M12 13v8',
    'M8.5 9.5a5 5 0 0 1 7 0',
    'M5.2 6.2a9.5 9.5 0 0 1 13.6 0',
    'M12 13a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z',
  ],
  menu: ['M4 6h16', 'M4 12h16', 'M4 18h16'],
  close: ['M18 6 6 18', 'm6 6 12 12'],
  plus: ['M12 5v14', 'M5 12h14'],
  search: ['M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16z', 'm21 21-4.3-4.3'],
  pencil: ['M12 20h9', 'M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z'],
  trash: [
    'M3 6h18',
    'M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2',
    'M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6',
    'M10 11v6',
    'M14 11v6',
  ],
  sun: [
    'M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10z',
    'M12 1v2',
    'M12 21v2',
    'M4.2 4.2l1.4 1.4',
    'M18.4 18.4l1.4 1.4',
    'M1 12h2',
    'M21 12h2',
    'M4.2 19.8l1.4-1.4',
    'M18.4 5.6l1.4-1.4',
  ],
  moon: ['M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z'],
  monitor: ['M3 4h18v12H3z', 'M8 20h8', 'M12 16v4'],
  check: ['m20 6-11 11-5-5'],
  alert: ['M12 3 2 20h20z', 'M12 9v5', 'M12 17.5v.5'],
  info: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M12 11v5', 'M12 7.5V8'],
  inbox: ['M22 12h-6l-2 3h-4l-2-3H2', 'M5.5 5h13l3.5 7v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6z'],
  mail: ['M3 5h18v14H3z', 'm3 6 9 6 9-6'],
  lock: ['M5 11h14v10H5z', 'M8 11V7a4 4 0 0 1 8 0v4'],
  calendar: ['M4 5h16v16H4z', 'M4 10h16', 'M8 3v4', 'M16 3v4'],
  clock: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M12 7v5l3 2'],
  'chevron-left': ['m15 18-6-6 6-6'],
  'chevron-right': ['m9 18 6-6-6-6'],
  'chevron-down': ['m6 9 6 6 6-6'],
  'arrow-right': ['M5 12h14', 'm12 5 7 7-7 7'],
  shield: ['M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z'],

  // --- Modules -------------------------------------------------------------
  // Formes génériques et non figuratives : elles disent la fonction, pas la
  // marque. Reprendre le logo d'un service tiers dans une interface qui n'y
  // est pas connectée laisserait croire à une intégration.
  database: [
    'M4 6c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3z',
    'M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6',
    'M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6',
  ],
  rocket: [
    'M12 2.5c3 2 5 5.6 5 9.5l-2.6 2.6H9.6L7 12c0-3.9 2-7.5 5-9.5z',
    'M9.6 14.6 8 19l2.6-1.2M14.4 14.6 16 19l-2.6-1.2',
    'M12 9.5v.01',
  ],
  'list-check': [
    'M9 6h11',
    'M9 12h11',
    'M9 18h11',
    'm3 6 1.4 1.4L7 4.8',
    'M3.5 12h1.6',
    'M3.5 18h1.6',
  ],
  bug: [
    'M8 7a4 4 0 0 1 8 0',
    'M6 9h12v5a6 6 0 0 1-12 0z',
    'M6 11H3M21 11h-3M5 6 3.5 4.5M19 6l1.5-1.5M5.5 17 3.5 19M18.5 17l2 2',
  ],
  shapes: ['M12 3l4.5 7.5h-9z', 'M4 14h6v6H4z', 'M17 14a3 3 0 1 0 0 6 3 3 0 0 0 0-6z'],
}

// Un module dont l'icône n'est pas connue reste affichable.
const paths = computed(() => ICONS[props.name] ?? ICONS['layout-grid'])
</script>

<template>
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    :stroke-width="strokeWidth"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
  >
    <path v-for="(d, index) in paths" :key="index" :d="d" />
  </svg>
</template>
