<script setup>
/**
 * Palette de commandes — Ctrl/⌘ + K.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  LE MANQUE QU'ELLE COMBLE                                           │
 * │                                                                     │
 * │  Chaque module avait sa recherche, et chacune ne voyait que sa      │
 * │  propre table. Pour retrouver « refresh token », il fallait DÉJÀ    │
 * │  savoir s'il s'agissait d'un ticket, d'un déploiement ou d'une      │
 * │  erreur — et essayer les trois sinon.                               │
 * │                                                                     │
 * │  C'est la question qu'on ne peut pas se poser : on se souvient d'un │
 * │  mot, pas d'un module.                                              │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * DEUX SOURCES, ET LEUR SÉPARATION EST DÉLIBÉRÉE.
 *
 *   « aller à »   — les modules et les pages. Filtrés EN LOCAL sur le
 *                   catalogue déjà chargé : la navigation doit répondre dans
 *                   la même image que la frappe, sans jamais attendre le
 *                   réseau.
 *   « résultats » — le contenu des cinq modules, via l'API. Forcément
 *                   asynchrone, donc affiché en second et sans faire sauter
 *                   ce qui est déjà là.
 *
 * Le curseur parcourt les DEUX groupes d'affilée, dans l'ordre de lecture :
 * une liste à plat est reconstruite pour lui, sans quoi les flèches sauteraient
 * d'un groupe à l'autre de façon imprévisible.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import AppIcon from '@/components/AppIcon.vue'
import { searchApi } from '@/services/api'
import { useModulesStore } from '@/stores/modules'
import { modulePath } from '@/utils/modules'

const router = useRouter()
const modulesStore = useModulesStore()

const open = ref(false)
const term = ref('')
const results = ref([])
const loading = ref(false)
const cursor = ref(0)
const input = ref(null)
const listbox = ref(null)

/** Pages fixes de l'application, hors modules. */
const PAGES = [
  { label: 'accueil', icon: 'home', to: { name: 'dashboard' } },
  { label: 'profil', icon: 'user', to: { name: 'profile' } },
  { label: 'paramètres', icon: 'settings', to: { name: 'settings' } },
]

/** Libellé lisible d'un module, pour situer un résultat. */
const MODULE_NAMES = {
  backend: 'backend',
  deploiement: 'déploiement',
  tickets: 'tickets',
  supervision: 'supervision',
  design: 'design',
}

/** Replie les accents et la casse : « systeme » doit trouver « Système ».
 *  Le serveur fait de même de son côté (cf. SearchService). */
const fold = (value) => value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')

const needle = computed(() => fold(term.value.trim()))

/** Navigation : filtrée en local, donc instantanée. */
const destinations = computed(() => {
  const entries = [
    ...modulesStore.items.map((module) => ({
      label: module.name.toLowerCase(),
      icon: module.icon,
      to: modulePath(module.slug),
    })),
    ...PAGES,
  ]

  if (!needle.value) return entries

  return entries.filter((entry) => fold(entry.label).includes(needle.value))
})

/**
 * Liste À PLAT dans l'ordre de l'écran : c'est elle que les flèches
 * parcourent. Le curseur doit suivre ce qu'on voit, pas la structure interne.
 */
const flat = computed(() => [
  ...destinations.value.map((entry) => ({ kind: 'go', entry })),
  ...results.value.map((entry) => ({ kind: 'hit', entry })),
])

watch(flat, () => {
  cursor.value = 0
})

// --- Recherche distante ------------------------------------------------------

let timer = null
let controller = null

/**
 * Frappe débattue de 180 ms, et requête précédente ANNULÉE.
 *
 * Sans l'annulation, taper « refresh » lance sept requêtes dont rien ne
 * garantit l'ordre d'arrivée : une réponse à « re » revenue en dernier
 * écraserait celle de « refresh ». Le terme est aussi comparé au retour, en
 * seconde barrière — une requête annulée peut déjà être partie.
 */
watch(term, (value) => {
  clearTimeout(timer)
  controller?.abort()

  if (value.trim().length < 2) {
    results.value = []
    loading.value = false

    return
  }

  loading.value = true

  timer = setTimeout(async () => {
    controller = new AbortController()

    try {
      const response = await searchApi.query(value.trim(), controller.signal)

      if (response.query === term.value.trim()) results.value = response.results
    } catch (error) {
      // Une requête annulée est le cas NORMAL ici, pas une panne.
      if (!error.canceled) results.value = []
    } finally {
      loading.value = false
    }
  }, 180)
})

// --- Ouverture et navigation -------------------------------------------------

async function show() {
  open.value = true

  // Le catalogue peut ne pas être chargé : la palette est montée dans la mise
  // en page, donc potentiellement avant le menu latéral qui le charge
  // d'habitude. S'en remettre à lui ferait une palette sans modules, sans
  // erreur pour le dire. Le store met en cache : aucun appel en double.
  modulesStore.load().catch(() => {
    // Un catalogue absent réduit la palette à la recherche de contenu ;
    // ce n'est pas une raison d'alerter par-dessus l'écran.
  })

  term.value = ''
  results.value = []
  cursor.value = 0

  await nextTick()
  input.value?.focus()
}

function hide() {
  open.value = false
  clearTimeout(timer)
  controller?.abort()
}

function go(item) {
  hide()

  if (item.kind === 'go') {
    router.push(item.entry.to)

    return
  }

  // Un résultat ouvre SON module. Le désigner à l'intérieur demanderait à
  // chaque écran de savoir se positionner sur une ligne — ce qu'aucun ne sait
  // faire aujourd'hui. Mieux vaut mener au bon endroit que promettre plus.
  router.push(modulePath(item.entry.module))
}

function move(step) {
  const count = flat.value.length

  if (count === 0) return

  cursor.value = (cursor.value + step + count) % count

  nextTick(() => {
    listbox.value?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' })
  })
}

function onKeydown(event) {
  // L'ouverture est écoutée sur le DOCUMENT, donc valable partout — y compris
  // depuis un champ de saisie, contrairement aux raccourcis à touche unique.
  if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
    event.preventDefault()
    open.value ? hide() : show()

    return
  }

  if (!open.value) return

  if (event.key === 'Escape') {
    event.preventDefault()
    hide()
  } else if (event.key === 'ArrowDown') {
    event.preventDefault()
    move(1)
  } else if (event.key === 'ArrowUp') {
    event.preventDefault()
    move(-1)
  } else if (event.key === 'Enter') {
    event.preventDefault()

    const item = flat.value[cursor.value]

    if (item) go(item)
  }
}

onMounted(() => document.addEventListener('keydown', onKeydown))

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown)
  clearTimeout(timer)
  controller?.abort()
})

defineExpose({ show })
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-60 flex items-start justify-center px-4 pt-[12vh]"
      role="dialog"
      aria-modal="true"
      aria-label="Recherche"
    >
      <div class="absolute inset-0 bg-ink/45" @click="hide" />

      <div
        class="panel relative z-10 flex max-h-[70vh] w-full max-w-xl flex-col overflow-hidden border-ink-3"
      >
        <div class="flex shrink-0 items-center gap-3 border-b border-line px-4 py-3">
          <AppIcon name="search" :size="16" class="shrink-0 text-ink-3" />
          <input
            ref="input"
            v-model="term"
            type="text"
            class="min-w-0 flex-1 bg-transparent text-[0.9rem] text-ink placeholder:text-ink-3 focus:outline-none"
            placeholder="Chercher partout, ou aller à…"
            aria-label="Chercher"
            autocomplete="off"
          />
          <span v-if="loading" class="shrink-0 text-[0.7rem] text-ink-3">…</span>
          <kbd class="shrink-0 rounded border border-line px-1.5 py-0.5 text-[0.66rem] text-ink-3">
            échap
          </kbd>
        </div>

        <div ref="listbox" class="min-h-0 flex-1 overflow-y-auto">
          <!-- ALLER À — filtré en local, donc toujours présent d'emblée. -->
          <template v-if="destinations.length">
            <p class="label-caps px-4 pb-1 pt-3">aller à</p>
            <button
              v-for="(entry, index) in destinations"
              :key="`go-${entry.label}`"
              type="button"
              class="flex w-full items-center gap-3 px-4 py-2 text-left transition-colors"
              :class="cursor === index ? 'bg-raised' : 'hover:bg-raised/60'"
              :data-active="cursor === index"
              @click="go({ kind: 'go', entry })"
              @mousemove="cursor = index"
            >
              <AppIcon :name="entry.icon" :size="15" class="shrink-0 text-ink-3" />
              <span class="text-[0.84rem]">{{ entry.label }}</span>
            </button>
          </template>

          <!-- RÉSULTATS — le contenu des cinq modules. -->
          <template v-if="results.length">
            <p class="label-caps px-4 pb-1 pt-3">résultats</p>
            <button
              v-for="(entry, index) in results"
              :key="`hit-${entry.module}-${entry.id}`"
              type="button"
              class="flex w-full items-center gap-3 px-4 py-2 text-left transition-colors"
              :class="cursor === destinations.length + index ? 'bg-raised' : 'hover:bg-raised/60'"
              :data-active="cursor === destinations.length + index"
              @click="go({ kind: 'hit', entry })"
              @mousemove="cursor = destinations.length + index"
            >
              <span class="w-24 shrink-0 text-[0.72rem] text-ink-3">
                {{ MODULE_NAMES[entry.module] ?? entry.module }}
              </span>
              <span class="w-24 shrink-0 truncate font-mono text-[0.7rem] text-ink-3">
                {{ entry.ref }}
              </span>
              <span class="min-w-0 flex-1 truncate text-[0.84rem]">{{ entry.title }}</span>
            </button>
          </template>

          <!-- Un seul caractère : la recherche de contenu n'est pas lancée.
               Le dire même quand des destinations correspondent, sinon on croit
               qu'aucun ticket ne contient cette lettre. -->
          <p v-if="term.trim().length === 1" class="px-4 py-3 text-[0.76rem] text-ink-3">
            Tapez un caractère de plus pour chercher dans le contenu des modules.
          </p>

          <!-- Le vide se dit, et se distingue de « en train de chercher ». -->
          <p v-if="!flat.length && !loading" class="px-4 py-6 text-center text-[0.8rem] text-ink-3">
            {{
              term.trim().length < 2
                ? 'Tapez au moins deux caractères.'
                : `Rien ne correspond à « ${term.trim()} ».`
            }}
          </p>
        </div>

        <footer
          class="flex shrink-0 items-center gap-4 border-t border-line bg-raised px-4 py-2 text-[0.68rem] text-ink-3"
        >
          <span>↑ ↓ parcourir</span>
          <span>↵ ouvrir</span>
          <span class="ml-auto">cinq modules à la fois</span>
        </footer>
      </div>
    </div>
  </Teleport>
</template>
