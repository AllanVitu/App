<script setup>
/**
 * État des cinq modules, en grille.
 *
 * Remplace une spirale d'Archimède qui disposait les mêmes tuiles sur une
 * courbe, avec une bascule spirale/liste et une animation de transition.
 * Elle occupait ~570 px de haut pour cinq nombres, imposait un carré au
 * milieu d'une page en colonnes, et l'ordre le long de la courbe n'encodait
 * RIEN — ni la priorité, ni l'urgence, ni l'usage. Une grille dit la même
 * chose en 140 px, et la position d'une tuile y est stable d'une visite à
 * l'autre : on finit par savoir où regarder sans lire.
 *
 * Chaque tuile est un lien, pas un bouton : c'est une navigation, elle doit
 * s'ouvrir dans un onglet, se copier, s'atteindre au clavier.
 */
import AppIcon from '@/components/AppIcon.vue'
import { moduleLine, modulePath } from '@/utils/modules'

defineProps({
  /** Catalogue enrichi par ModuleMetrics : compteur, unité, signaux. */
  modules: { type: Array, required: true },
})

/**
 * Le ton vient du SERVEUR (cf. ModuleMetrics), qui seul sait ce qui compte
 * dans chaque module. Le nom de classe est écrit en toutes lettres : Tailwind
 * compile en lisant les sources, une classe fabriquée à l'exécution ne serait
 * jamais générée.
 */
const TONES = {
  alert: 'border-brick/40 bg-brick-bg text-brick',
  warn: 'border-ochre/40 bg-ochre-bg text-ochre',
  neutral: 'border-line text-ink-3',
}
</script>

<template>
  <section>
    <h3 class="mb-3 text-[0.95rem] font-semibold">état des modules</h3>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
      <RouterLink
        v-for="module in modules"
        :key="module.id"
        :to="modulePath(module.slug)"
        class="card group flex flex-col justify-between gap-3 p-3.5 transition-colors hover:border-line-2 hover:bg-raised"
      >
        <!-- La tuile porte la ligne de son module en bandeau haut : la grille
             devient un plan du réseau plutôt qu une liste de cartes grises. -->
        <span
          class="ligne -mt-0.5 mb-1 h-1 w-full"
          :class="moduleLine(module.slug)"
          aria-hidden="true"
        />

        <div class="flex items-center gap-2">
          <AppIcon :name="module.icon" :size="15" class="shrink-0 text-ink-3" />
          <span class="truncate text-[0.8rem] font-semibold lowercase">{{ module.name }}</span>
        </div>

        <div>
          <!-- Le chiffre est celui du TRAVAIL RESTANT, jamais un cumul : un
               total grossirait à chaque tâche terminée, c'est-à-dire chaque
               fois que la situation s'améliore. -->
          <p class="text-2xl font-semibold leading-none tabular-nums">{{ module.items_count }}</p>
          <p class="mt-1 truncate text-[0.7rem] text-ink-3">{{ module.unit }}</p>
        </div>

        <!-- Hauteur réservée même sans signal : sans elle, les tuiles
             calmes seraient plus courtes et la grille prendrait un air de
             dents de scie à chaque changement d'état. -->
        <div class="flex min-h-5 flex-wrap gap-1">
          <span
            v-for="signal in module.signals"
            :key="signal.label"
            class="chip"
            :class="TONES[signal.tone] ?? TONES.neutral"
          >
            <span class="font-semibold tabular-nums">{{ signal.value }}</span>
            {{ signal.label }}
          </span>
        </div>
      </RouterLink>
    </div>
  </section>
</template>
