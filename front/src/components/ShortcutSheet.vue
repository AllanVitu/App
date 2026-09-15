<script setup>
/**
 * La feuille des raccourcis, ouverte par « ? ».
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  UN RACCOURCI QU'ON NE PEUT PAS DÉCOUVRIR N'EXISTE PAS              │
 * │                                                                     │
 * │  Le module Tickets se pilote entièrement au clavier depuis le       │
 * │  début — et il fallait le savoir. Cette feuille est le seul endroit │
 * │  où l'application dit ce qu'elle sait faire sans souris.            │
 * │                                                                     │
 * │  « ? » est le seul raccourci qui n'a pas besoin d'être appris :     │
 * │  c'est la convention, c'est ce qu'on essaie en premier.             │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Les raccourcis PROPRES aux tickets ne sont pas répétés ici : ils sont
 * affichés dans le module, à côté de ce sur quoi ils agissent, et leur nombre
 * noierait les huit destinations. La feuille y renvoie plutôt que de tenir
 * deux listes qui divergeront.
 */
import AppIcon from '@/components/AppIcon.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { DESTINATIONS } from '@/composables/useAppShortcuts'

defineProps({
  open: { type: Boolean, default: false },
})

defineEmits(['close'])

/** Ce qui marche partout, hors navigation. */
const GLOBAUX = [
  { touches: ['Ctrl', 'K'], quoi: 'chercher dans les cinq modules, ou aller quelque part' },
  { touches: ['?'], quoi: 'ouvrir et fermer cette feuille' },
  { touches: ['Échap'], quoi: 'refermer ce qui est ouvert' },
]
</script>

<template>
  <BaseModal :open="open" title="raccourcis clavier" size="md" @close="$emit('close')">
    <div class="space-y-5">
      <section>
        <p class="label-caps mb-2">partout</p>
        <dl class="divide-y divide-line rounded-field border border-line">
          <div v-for="item in GLOBAUX" :key="item.quoi" class="flex items-center gap-3 px-3 py-2">
            <dt class="flex w-28 shrink-0 items-center gap-1">
              <kbd
                v-for="touche in item.touches"
                :key="touche"
                class="rounded border border-line bg-raised px-1.5 py-0.5 font-mono text-[0.68rem] text-ink"
              >
                {{ touche }}
              </kbd>
            </dt>
            <dd class="min-w-0 flex-1 text-[0.82rem] text-ink-2">{{ item.quoi }}</dd>
          </div>
        </dl>
      </section>

      <section>
        <p class="label-caps mb-2">aller à</p>
        <!-- « g » PUIS une lettre : une séquence, pas une combinaison. C'est ce
             qui permet huit destinations sans marcher sur les raccourcis du
             navigateur. -->
        <p class="mb-2 text-[0.78rem] text-ink-3">
          Tapez <kbd class="rounded border border-line bg-raised px-1 font-mono">g</kbd> puis la
          lettre — l'une après l'autre, pas ensemble.
        </p>

        <dl class="grid gap-x-4 gap-y-1 sm:grid-cols-2">
          <div
            v-for="entry in DESTINATIONS"
            :key="entry.key"
            class="flex items-center gap-2 rounded-field px-2 py-1"
          >
            <dt class="flex shrink-0 items-center gap-0.5">
              <kbd
                class="rounded border border-line bg-raised px-1.5 py-0.5 font-mono text-[0.68rem] text-ink-3"
              >
                g
              </kbd>
              <kbd
                class="rounded border border-line bg-raised px-1.5 py-0.5 font-mono text-[0.68rem] text-ink"
              >
                {{ entry.key }}
              </kbd>
            </dt>
            <dd class="min-w-0 flex-1 truncate text-[0.82rem] text-ink-2">{{ entry.label }}</dd>
          </div>
        </dl>
      </section>

      <p class="flex items-start gap-2 text-[0.78rem] text-ink-3">
        <AppIcon name="info" :size="14" class="mt-0.5 shrink-0" />
        Le module Tickets a ses propres raccourcis, affichés sur son écran : ils agissent sur la
        carte sélectionnée, pas sur l'application.
      </p>
    </div>
  </BaseModal>
</template>
