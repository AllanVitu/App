<script setup>
/**
 * Layout des pages authentifiées.
 *
 * Structure de fenêtre : chaque zone est un panneau distinct séparé par une
 * gouttière, plutôt qu'un flux continu. On lit l'application comme un poste
 * de travail — menu, chemin, contenu, état — et non comme une page web.
 *
 * Le catalogue des modules est chargé ici, une fois pour toutes les pages
 * enfants ; le titre affiché suit la route courante.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'

import AppSidebar from '@/components/AppSidebar.vue'
import CommandPalette from '@/components/CommandPalette.vue'
import ShortcutSheet from '@/components/ShortcutSheet.vue'
import AppTopbar from '@/components/AppTopbar.vue'
import EmailVerificationBanner from '@/components/EmailVerificationBanner.vue'
import SoundGate from '@/components/SoundGate.vue'
import { useAppShortcuts } from '@/composables/useAppShortcuts'
import { useModulesStore } from '@/stores/modules'
import { useUiStore } from '@/stores/ui'

const route = useRoute()
const modulesStore = useModulesStore()
const ui = useUiStore()

// Raccourcis valables sur toute l'application : « g » puis une lettre pour
// naviguer, « ? » pour la feuille. Montés ici, donc actifs sur chaque écran.
const { helpOpen, pending } = useAppShortcuts()

const title = computed(() => {
  if (route.name === 'module') {
    return modulesStore.bySlug(route.params.slug)?.name ?? 'Module'
  }

  return route.meta.title ?? ''
})

/**
 * LE CONTENU EST UNE DESTINATION, PAS SEULEMENT UNE ZONE.
 *
 * Le menu latéral compte une vingtaine d'éléments focalisables, et il est
 * identique sur toutes les pages. Sans point d'entrée direct, quelqu'un qui
 * navigue au clavier les retraverse à CHAQUE changement d'écran pour
 * atteindre ce qu'il vient d'ouvrir.
 */
const main = ref(null)

// La barre du haut ouvre la palette sans simuler de raccourci clavier : un
// clic est un clic, et la palette expose de quoi s'ouvrir (cf. show).
const palette = ref(null)

function focusMain() {
  main.value?.focus()
}

/**
 * Le focus suit la navigation.
 *
 * Dans une application d'une seule page, changer d'écran ne déplace ni le
 * focus ni le curseur virtuel d'un lecteur d'écran : le titre du document
 * change, et rien ne l'annonce. On amène donc le focus sur la région
 * principale, qui porte le nom de la page — le nouvel écran s'annonce, et la
 * touche de tabulation repart de son début plutôt que du menu.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  « path » ET SURTOUT PAS « fullPath »                               │
 * │                                                                     │
 * │  Les filtres de chaque écran vivent maintenant dans l'adresse : une │
 * │  frappe dans une recherche réécrit la query. Observer « fullPath »  │
 * │  arracherait le curseur du champ à chaque lettre.                  │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Rien au premier rendu : au chargement, le focus appartient au document, et
 * le déplacer ferait sauter la page avant même qu'on l'ait lue.
 */
watch(
  () => route.path,
  async () => {
    await nextTick()
    focusMain()
  },
)

onMounted(async () => {
  try {
    await modulesStore.load()
  } catch (error) {
    ui.notify(error.message, 'error')
  }
})
</script>

<template>
  <div class="flex h-screen flex-col bg-paper">
    <!-- PREMIER ÉLÉMENT FOCALISABLE DE LA PAGE, et invisible jusqu'à ce
         qu'on l'atteigne. Il n'existe que pour la première tabulation.

         « href » est conservé pour que ce soit un vrai lien — annoncé comme
         tel, atteignable par la liste des liens d'un lecteur d'écran — mais
         le saut est fait à la main : laisser le navigateur suivre l'ancre
         inscrirait « #contenu » dans l'adresse, où il resterait.

         L'ACTIVATION AU CLAVIER EST TRAITÉE EXPLICITEMENT, et pas seulement
         par le clic que le navigateur synthétise sur « Entrée ». Ce lien
         n'existe QUE pour le clavier : faire dépendre son unique usage d'un
         comportement implicite serait le laisser à la merci du premier
         écouteur qui avale l'événement. « prevent » sur la touche empêche du
         même coup le clic qui suivrait — l'action ne part donc qu'une fois. -->
    <a
      href="#contenu"
      class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-80 focus:rounded-field focus:border focus:border-ink focus:bg-panel focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-ink focus:shadow-e3"
      @click.prevent="focusMain"
      @keydown.enter.prevent="focusMain"
    >
      Aller au contenu
    </a>

    <div class="flex min-h-0 flex-1">
      <AppSidebar />

      <div class="flex min-w-0 flex-1 flex-col">
        <AppTopbar :title="title" @search="palette?.show()" />

        <!-- Le défilement vit DANS le panneau, pas sur la page : le cadre
             reste fixe, comme une fenêtre d'application.

             « tabindex=-1 » le rend focalisable par programme sans l'insérer
             dans l'ordre de tabulation, et « aria-label » donne au repère son
             nom : à l'arrivée, le lecteur d'écran annonce la page. Aucun
             contour ne s'affiche pour autant — la feuille de style ne dessine
             que « :focus-visible », que le focus programmatique ne déclenche
             pas. -->
        <main
          id="contenu"
          ref="main"
          tabindex="-1"
          :aria-label="title || 'Contenu'"
          class="min-h-0 flex-1 overflow-y-auto bg-paper px-4 py-5 lg:px-8 lg:py-7"
        >
          <EmailVerificationBanner />

          <RouterView v-slot="{ Component }">
            <Transition name="fade" mode="out-in">
              <component :is="Component" />
            </Transition>
          </RouterView>
        </main>
      </div>
    </div>

    <!-- Feuille des raccourcis, ouverte par « ? ». Le module Tickets se
         pilotait entièrement au clavier depuis le début — et il fallait le
         savoir. C'est le seul endroit où l'application le dit. -->
    <ShortcutSheet :open="helpOpen" @close="helpOpen = false" />

    <!-- Indicateur de séquence : « g » attend sa lettre. Sans lui, la touche
         semble n'avoir rien fait, et on la retape. -->
    <p
      v-if="pending"
      class="fixed bottom-14 left-1/2 z-60 -translate-x-1/2 rounded-field border border-line bg-panel px-3 py-1 text-[0.72rem] text-ink-2 shadow-e2"
      role="status"
    >
      <kbd class="font-mono text-ink">g</kbd> … puis une lettre
      <span class="text-ink-3">(? pour la liste)</span>
    </p>

    <!-- Palette de recherche, montée UNE FOIS pour toute l'application :
         elle écoute Ctrl/⌘ + K sur le document, donc depuis n'importe quel
         écran, et cherche dans les cinq modules à la fois. -->
    <CommandPalette ref="palette" />

    <!-- Le choix sonore n'est proposé qu'une fois entré : les écrans
         d'identification restent muets. -->
    <SoundGate />
  </div>
</template>
