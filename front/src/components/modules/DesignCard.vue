<script setup>
/**
 * Un fichier de design, en carte de tableau.
 *
 * La vignette est l'IMAGE de la dernière version qui en porte une — et, à
 * défaut, un dégradé de la couleur d'accent du fichier. Une maquette se
 * reconnaît à son allure avant son nom ; la couleur n'était qu'un substitut,
 * du temps où le module promettait des fichiers sans en stocker aucun.
 *
 * La pastille de type n'est pas reprise ici, contrairement aux autres cartes
 * du projet : elle répéterait le titre de la colonne, qui est le type. Sur
 * les tickets et les erreurs, le statut est gardé sur la carte parce qu'un
 * lecteur d'écran annonce une « option » seule et qu'un glissement la détache
 * de sa colonne — mais un type de fichier ne se lit pas dans l'urgence, et le
 * nom de la colonne est annoncé juste avant.
 */
import { computed, ref, watch } from 'vue'

import PresenceMark from '@/components/ui/PresenceMark.vue'
import { assetUrl } from '@/utils/assets'
import { formatRelative } from '@/utils/format'

const props = defineProps({
  file: { type: Object, required: true },
  /** Qui a ce fichier ouvert en ce moment (cf. ui/PresenceMark). */
  watchers: { type: Array, default: () => [] },
})

const echec = ref(false)
const apercu = computed(() => (echec.value ? null : assetUrl(props.file.preview_url)))

// Une nouvelle version, une nouvelle adresse : l'échec de la précédente — un
// lien expiré sur une carte restée longtemps à l'écran — ne vaut pas pour elle.
watch(
  () => props.file.preview_url,
  () => {
    echec.value = false
  },
)
</script>

<template>
  <div class="-m-2.5 overflow-hidden rounded-card">
    <img
      v-if="apercu"
      :src="apercu"
      alt=""
      loading="lazy"
      decoding="async"
      referrerpolicy="no-referrer"
      class="block h-20 w-full bg-raised object-cover"
      @error="echec = true"
    />
    <span
      v-else
      class="block h-20 w-full"
      :style="{
        background: `linear-gradient(135deg, ${file.accent} 0%, ${file.accent}33 100%)`,
      }"
      aria-hidden="true"
    />

    <div class="flex flex-col gap-1 p-2.5">
      <p class="truncate text-[0.82rem] font-semibold">{{ file.name }}</p>

      <p v-if="file.description" class="line-clamp-2 text-[0.72rem] leading-snug text-ink-2">
        {{ file.description }}
      </p>

      <p class="flex items-center gap-1.5 text-[0.68rem] text-ink-3">
        <span class="tabular-nums">v{{ file.versions }}</span>
        <span aria-hidden="true">·</span>
        <span>{{ formatRelative(file.updated_at) }}</span>
      </p>

      <PresenceMark :watchers="watchers" />
    </div>
  </div>
</template>
