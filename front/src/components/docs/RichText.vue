<script setup>
/**
 * Le rendu d'une page de documentation : des blocs, jamais du HTML.
 *
 * Le titre de la page est un h2 de l'écran ; les titres du texte commencent
 * donc au h3, pour qu'un lecteur d'écran lise une hiérarchie qui tient debout.
 */
import { computed } from 'vue'

import RichInlines from '@/components/docs/RichInlines.vue'
import { parseBlocks } from '@/utils/richText'

const props = defineProps({
  source: { type: String, default: '' },
})

const blocks = computed(() => parseBlocks(props.source))

const TITRES = {
  1: { tag: 'h3', classes: 'mt-3 text-[1.3rem] font-bold tracking-[-0.01em] [font-stretch:88%]' },
  2: { tag: 'h4', classes: 'mt-2 text-[1.08rem] font-semibold' },
  3: { tag: 'h5', classes: 'mt-1 text-[0.95rem] font-semibold' },
}
</script>

<template>
  <div class="flex flex-col gap-3.5 text-[0.9375rem] leading-relaxed text-ink-2">
    <p v-if="!blocks.length" class="text-ink-3">Cette page est vide.</p>

    <div v-for="(block, index) in blocks" :key="index" class="contents">
      <component
        :is="TITRES[block.level].tag"
        v-if="block.type === 'heading'"
        class="text-ink"
        :class="TITRES[block.level].classes"
      >
        <RichInlines :inlines="block.inlines" />
      </component>

      <p v-else-if="block.type === 'paragraph'">
        <RichInlines :inlines="block.inlines" />
      </p>

      <component
        :is="block.ordered ? 'ol' : 'ul'"
        v-else-if="block.type === 'list'"
        class="flex flex-col gap-1 pl-5"
        :class="block.ordered ? 'list-decimal' : 'list-disc'"
      >
        <li v-for="(item, rang) in block.items" :key="rang">
          <RichInlines :inlines="item" />
        </li>
      </component>

      <blockquote v-else-if="block.type === 'quote'" class="border-l-2 border-ink-3 pl-4 italic">
        <RichInlines :inlines="block.inlines" />
      </blockquote>

      <pre
        v-else-if="block.type === 'code'"
        class="overflow-x-auto rounded-card border border-line bg-paper p-3.5 font-mono text-[0.8125rem] leading-relaxed text-ink"
      ><code>{{ block.text }}</code></pre>
    </div>
  </div>
</template>
