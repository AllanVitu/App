<script setup>
/**
 * Module Documentation : les procédures et les décisions de l'équipe.
 *
 * À gauche, l'arbre des pages et la recherche ; à droite, la page ouverte,
 * lue ou en écriture. La page ouverte vit dans l'adresse (?page=…) : un lien
 * vers une procédure se colle dans un ticket et mène à elle.
 *
 * Tout membre écrit. Une écriture part avec sa version : si quelqu'un d'autre
 * a modifié le même champ entre-temps, le serveur refuse et l'écran le dit —
 * rien n'est écrasé en silence (cf. Journal::assertNoConflict).
 *
 * Le texte n'est jamais du HTML : il est lu en blocs et affiché comme du texte
 * (cf. utils/richText, components/docs/RichText).
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'

import AppIcon from '@/components/AppIcon.vue'
import RichText from '@/components/docs/RichText.vue'
import ModuleHeader from '@/components/modules/ModuleHeader.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SearchField from '@/components/ui/SearchField.vue'
import { useQuerySync } from '@/composables/useQuerySync'
import { useRevalidate } from '@/composables/useRevalidate'
import { useUnsavedGuard } from '@/composables/useUnsavedGuard'
import { docsApi } from '@/services/api'
import { useUiStore } from '@/stores/ui'
import { buildTree, flattenTree } from '@/utils/docs'
import { formatRelative } from '@/utils/format'
import { plainExcerpt } from '@/utils/richText'

const ui = useUiStore()

const pages = ref([])
const loading = ref(true)

const search = ref('')
const results = ref(null)
const selectedId = ref(null)

useQuerySync({ page: selectedId, q: search })

const tree = computed(() => buildTree(pages.value))
const rows = computed(() => flattenTree(tree.value))

const headerStats = computed(() => [
  { label: pages.value.length > 1 ? 'pages' : 'page', value: pages.value.length, tone: 'neutral' },
])

async function loadTree({ silent = false } = {}) {
  if (!silent) loading.value = true

  try {
    pages.value = await docsApi.tree()
  } catch (error) {
    ui.notify(error.message, 'error')
  } finally {
    loading.value = false
  }
}

onMounted(() => loadTree())

useRevalidate(() => loadTree({ silent: true }))

// --- Recherche -----------------------------------------------------------------

let delai = null
let derniere = 0

/**
 * Sur le serveur, qui cherche aussi dans le TEXTE des pages — l'arbre n'en
 * transporte que les titres. Une réponse lente n'écrase jamais une plus
 * récente : chaque requête porte son numéro, et seule la dernière est gardée.
 */
watch(
  search,
  (terme) => {
    clearTimeout(delai)

    if (terme.trim().length < 2) {
      results.value = null
      return
    }

    delai = setTimeout(async () => {
      const numero = ++derniere

      try {
        const trouves = await docsApi.search(terme.trim())

        if (numero === derniere) results.value = trouves
      } catch (error) {
        ui.notify(error.message, 'error')
      }
    }, 250)
  },
  { immediate: true },
)

// --- Page ouverte ----------------------------------------------------------------

const page = ref(null)
const loadingPage = ref(false)

const editing = ref(false)
const preview = ref(false)
const draft = reactive({ title: '', body: '', parent_id: null })
const errors = ref({})
const saving = ref(false)

const dirty = computed(
  () =>
    editing.value &&
    (draft.title !== (page.value?.title ?? '') ||
      draft.body !== (page.value?.body ?? '') ||
      draft.parent_id !== (page.value?.parent_id ?? null)),
)

useUnsavedGuard(() => dirty.value)

watch(
  selectedId,
  async (id) => {
    if (!id) {
      page.value = null
      return
    }

    loadingPage.value = true

    try {
      page.value = await docsApi.find(id)
    } catch (error) {
      page.value = null
      selectedId.value = null
      ui.notify(error.message, 'error')
    } finally {
      loadingPage.value = false
    }
  },
  { immediate: true },
)

/**
 * Changer de page pendant une écriture non enregistrée la perdrait : l'écran
 * le refuse et le dit, plutôt que de poser une question de plus.
 */
function selectPage(id) {
  if (dirty.value) {
    ui.notify('Enregistrez ou abandonnez d’abord la modification en cours.', 'info')
    return
  }

  editing.value = false
  selectedId.value = id
}

/** Les emplacements possibles : partout, sauf sous la page elle-même. */
const parentOptions = computed(() => flattenTree(tree.value, page.value?.id ?? null))

function startEdit() {
  Object.assign(draft, {
    title: page.value.title,
    body: page.value.body,
    parent_id: page.value.parent_id,
  })
  errors.value = {}
  preview.value = false
  editing.value = true
}

function startCreate(parentId = null) {
  if (dirty.value) {
    ui.notify('Enregistrez ou abandonnez d’abord la modification en cours.', 'info')
    return
  }

  selectedId.value = null
  page.value = null
  Object.assign(draft, { title: '', body: '', parent_id: parentId })
  errors.value = {}
  preview.value = false
  editing.value = true
}

function cancel() {
  editing.value = false
  errors.value = {}
}

async function save() {
  saving.value = true
  errors.value = {}

  const creation = page.value === null

  try {
    const payload = { title: draft.title, body: draft.body, parent_id: draft.parent_id }
    const saved = creation
      ? await docsApi.create(payload)
      : await docsApi.update(page.value.id, { ...payload, version: page.value.version })

    editing.value = false
    await loadTree({ silent: true })

    if (selectedId.value === saved.id) {
      page.value = await docsApi.find(saved.id)
    } else {
      selectedId.value = saved.id
    }

    ui.notify(creation ? 'Page créée.' : 'Page enregistrée.')
  } catch (error) {
    // Un conflit arrive avec un message par champ disputé : il se place sous
    // le champ, là où la personne regarde.
    errors.value = error.errors ?? {}

    if (!Object.keys(errors.value).length) ui.notify(error.message, 'error')
  } finally {
    saving.value = false
  }
}

async function remove() {
  const cible = page.value

  try {
    await docsApi.remove(cible.id)

    selectedId.value = null
    await loadTree({ silent: true })

    ui.notifyUndo('Page supprimée.', async () => {
      try {
        await docsApi.restore(cible.id)
        await loadTree({ silent: true })
        selectedId.value = cible.id
        ui.notify('Page restaurée.')
      } catch (error) {
        ui.notify(error.message, 'error')
      }
    })
  } catch (error) {
    ui.notify(error.errors?.parent_id ?? error.message, 'error')
  }
}

const chipAction = 'chip border-line-2 text-ink-2 transition-colors hover:border-ink hover:text-ink'

const AIDE =
  '# Titre, - liste, 1. étape, **gras**, `code`, [lien](https://…), #142 pour un ticket, ``` pour un bloc de code.'
</script>

<template>
  <div class="flex h-full min-h-0 flex-col gap-5">
    <ModuleHeader slug="documentation" title="documentation" :stats="headerStats">
      <template #filters>
        <SearchField
          v-model="search"
          placeholder="Titre ou texte"
          label="Rechercher dans la documentation"
        />

        <span class="flex-1" />

        <button
          type="button"
          class="chip border-ink bg-ink text-paper transition-opacity hover:opacity-90"
          @click="startCreate(null)"
        >
          <AppIcon name="plus" :size="13" />
          nouvelle page
        </button>
      </template>
    </ModuleHeader>

    <div class="grid min-h-0 flex-1 gap-5 lg:grid-cols-[18rem_minmax(0,1fr)]">
      <nav class="card min-h-0 overflow-y-auto py-2" aria-label="Pages de documentation">
        <div v-if="loading" class="flex justify-center py-8">
          <BaseSpinner class="size-5 text-ink" />
        </div>

        <template v-else-if="results">
          <p class="label-caps px-4 pb-1.5 pt-2">
            {{ results.length }} {{ results.length > 1 ? 'résultats' : 'résultat' }}
          </p>

          <ul>
            <li v-for="result in results" :key="result.id">
              <button
                type="button"
                class="flex w-full flex-col items-start gap-0.5 px-4 py-2 text-left transition-colors hover:bg-raised"
                :class="result.id === selectedId ? 'bg-raised' : ''"
                @click="selectPage(result.id)"
              >
                <span class="text-[0.875rem] font-medium">{{ result.title }}</span>
                <span class="line-clamp-2 text-xs text-ink-3">
                  {{ plainExcerpt(result.head, 110) }}
                </span>
              </button>
            </li>
          </ul>
        </template>

        <p v-else-if="!rows.length" class="px-4 py-6 text-[0.8125rem] text-ink-3">
          Aucune page. Commencez par la procédure dont l'équipe aurait eu besoin la dernière fois
          que quelque chose a cassé.
        </p>

        <ul v-else>
          <li v-for="row in rows" :key="row.id">
            <button
              type="button"
              class="flex w-full items-center py-1.5 pr-4 text-left text-[0.875rem] transition-colors hover:bg-raised"
              :class="row.id === selectedId ? 'bg-raised font-semibold text-ink' : 'text-ink-2'"
              :style="{ paddingLeft: `${1 + row.depth * 0.9}rem` }"
              :aria-current="row.id === selectedId ? 'page' : undefined"
              @click="selectPage(row.id)"
            >
              <span class="truncate">{{ row.title }}</span>
            </button>
          </li>
        </ul>
      </nav>

      <section class="card min-h-0 overflow-y-auto" aria-label="Page ouverte">
        <div v-if="loadingPage" class="flex justify-center py-16">
          <BaseSpinner class="size-6 text-ink" />
        </div>

        <form v-else-if="editing" class="flex flex-col gap-4 p-6" novalidate @submit.prevent="save">
          <BaseInput
            v-model="draft.title"
            label="Titre"
            placeholder="Restaurer une sauvegarde"
            required
            :error="errors.title"
          />

          <label class="block">
            <span class="label-field">Emplacement</span>
            <select v-model="draft.parent_id" class="input-field">
              <option :value="null">À la racine</option>
              <option v-for="option in parentOptions" :key="option.id" :value="option.id">
                {{ '— '.repeat(option.depth + 1) }}{{ option.title }}
              </option>
            </select>
            <span v-if="errors.parent_id" class="mt-1 block text-[0.72rem] text-brick">
              {{ errors.parent_id }}
            </span>
          </label>

          <div class="flex gap-1.5" role="group" aria-label="Mode d’écriture">
            <button
              type="button"
              :class="[chipAction, !preview ? 'border-ink text-ink' : '']"
              :aria-pressed="!preview"
              @click="preview = false"
            >
              écrire
            </button>
            <button
              type="button"
              :class="[chipAction, preview ? 'border-ink text-ink' : '']"
              :aria-pressed="preview"
              @click="preview = true"
            >
              aperçu
            </button>
          </div>

          <BaseInput
            v-if="!preview"
            v-model="draft.body"
            label="Texte"
            textarea
            :rows="18"
            :error="errors.body"
            :hint="AIDE"
          />

          <div v-else class="rounded-card border border-line p-4">
            <RichText :source="draft.body" />
          </div>

          <div class="flex justify-end gap-2">
            <BaseButton type="button" variant="secondary" @click="cancel">Abandonner</BaseButton>
            <BaseButton type="submit" :loading="saving">
              {{ page ? 'Enregistrer' : 'Créer la page' }}
            </BaseButton>
          </div>
        </form>

        <article v-else-if="page" class="flex flex-col gap-5 p-6">
          <nav
            v-if="page.ancestors?.length"
            aria-label="Emplacement de la page"
            class="flex flex-wrap items-center gap-1.5 text-xs text-ink-3"
          >
            <span v-for="ancetre in page.ancestors" :key="ancetre.id" class="contents">
              <button type="button" class="hover:text-ink" @click="selectPage(ancetre.id)">
                {{ ancetre.title }}
              </button>
              <span aria-hidden="true">/</span>
            </span>
          </nav>

          <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <h2
                class="text-[1.625rem] font-bold leading-tight tracking-[-0.015em] [font-stretch:85%]"
              >
                {{ page.title }}
              </h2>
              <p class="mt-1 text-xs text-ink-3">
                Modifiée {{ formatRelative(page.updated_at) }}
                <template v-if="page.updated_by_name">par {{ page.updated_by_name }}</template>
              </p>
            </div>

            <div class="flex shrink-0 flex-wrap gap-1.5">
              <button type="button" :class="chipAction" @click="startEdit">modifier</button>
              <button type="button" :class="chipAction" @click="startCreate(page.id)">
                sous-page
              </button>
              <button
                type="button"
                class="chip border-line-2 text-ink-2 transition-colors hover:border-brick hover:text-brick"
                aria-label="Supprimer la page"
                @click="remove"
              >
                <AppIcon name="trash" :size="13" />
              </button>
            </div>
          </header>

          <RichText :source="page.body" />
        </article>

        <EmptyState
          v-else
          title="Aucune page ouverte"
          description="Choisissez une page à gauche, ou créez-en une."
        />
      </section>
    </div>
  </div>
</template>
