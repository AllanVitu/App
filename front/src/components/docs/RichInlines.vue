<script setup>
/**
 * Les fragments d'une ligne de texte enrichi.
 *
 * Chaque fragment est un élément dont le contenu est du TEXTE, que Vue échappe
 * toujours (cf. utils/richText) : aucun v-html. Un lien n'a d'adresse que si
 * safeHref l'a acceptée, et s'ouvre dans un nouvel onglet sans donner au site
 * visé la main sur celui-ci (rel="noopener noreferrer").
 *
 * Chaque fragment est enveloppé d'un « span » en display: contents : il ne
 * change rien à la mise en page, et reste un élément valide à l'intérieur d'un
 * paragraphe, là où un « div » ne l'est pas.
 */
import { modulePath } from '@/utils/modules'

defineProps({
  inlines: { type: Array, required: true },
})

const externe = (href) => href.startsWith('http:') || href.startsWith('https:')
</script>

<template>
  <span v-for="(fragment, index) in inlines" :key="index" class="contents">
    <strong v-if="fragment.type === 'strong'" class="font-semibold text-ink">{{
      fragment.value
    }}</strong>
    <em v-else-if="fragment.type === 'em'">{{ fragment.value }}</em>
    <code
      v-else-if="fragment.type === 'code'"
      class="rounded-card border border-line bg-raised px-1 py-px font-mono text-[0.85em] text-ink"
      >{{ fragment.value }}</code
    >
    <a
      v-else-if="fragment.type === 'link'"
      :href="fragment.href"
      :target="externe(fragment.href) ? '_blank' : undefined"
      :rel="externe(fragment.href) ? 'noopener noreferrer' : undefined"
      class="text-ink underline decoration-line-2 underline-offset-[3px] hover:decoration-ink"
      >{{ fragment.value }}</a
    >
    <RouterLink
      v-else-if="fragment.type === 'ticket'"
      :to="{ path: modulePath('tickets'), query: { q: fragment.value } }"
      class="font-mono text-[0.9em] text-ink underline decoration-line-2 underline-offset-[3px] hover:decoration-ink"
      >{{ fragment.value }}</RouterLink
    >
    <template v-else>{{ fragment.value }}</template>
  </span>
</template>
