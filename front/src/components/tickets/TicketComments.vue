<script setup>
/**
 * La discussion d'un ticket, dans son panneau de détail.
 *
 * Un commentaire est une ligne à lui : deux personnes qui répondent en même
 * temps n'entrent jamais en conflit, contrairement à deux personnes qui
 * réécrivent la description.
 *
 * Le texte s'affiche par le même découpage que la Documentation — titres,
 * listes, code, liens sûrs, « #12 » vers le ticket —, jamais comme du HTML.
 *
 * Ctrl+Entrée envoie : Entrée seule reste un retour à la ligne, parce qu'un
 * commentaire utile a souvent plusieurs lignes.
 */
import { computed, ref, watch } from 'vue'

import AppIcon from '@/components/AppIcon.vue'
import RichText from '@/components/docs/RichText.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { ticketCommentsApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { formatRelative } from '@/utils/format'

const props = defineProps({
  ticketId: { type: String, required: true },
})

const auth = useAuthStore()
const ui = useUiStore()

const comments = ref([])
const loading = ref(false)
const draft = ref('')
const error = ref('')
const sending = ref(false)

const canModerate = computed(() => ['owner', 'admin'].includes(auth.organization?.role))
const canRemove = (comment) => canModerate.value || comment.author_id === auth.user?.id

/**
 * Le numéro de la requête en cours : passer vite d'un ticket à l'autre ne doit
 * jamais afficher, sous le second, la discussion du premier arrivée en retard.
 */
let derniere = 0

async function load(ticketId) {
  const numero = ++derniere

  loading.value = true
  comments.value = []

  try {
    const liste = await ticketCommentsApi.list(ticketId)

    if (numero === derniere) comments.value = liste
  } catch (err) {
    if (numero === derniere) ui.notify(err.message, 'error')
  } finally {
    if (numero === derniere) loading.value = false
  }
}

watch(
  () => props.ticketId,
  (ticketId) => {
    draft.value = ''
    error.value = ''
    load(ticketId)
  },
  { immediate: true },
)

async function send() {
  if (!draft.value.trim() || sending.value) return

  sending.value = true
  error.value = ''

  const ticketId = props.ticketId

  try {
    const cree = await ticketCommentsApi.create(ticketId, { body: draft.value })

    if (ticketId === props.ticketId) {
      comments.value = [...comments.value, cree]
      draft.value = ''
    }
  } catch (err) {
    error.value = err.errors?.body ?? err.message
  } finally {
    sending.value = false
  }
}

async function remove(comment) {
  const ticketId = props.ticketId

  try {
    await ticketCommentsApi.remove(ticketId, comment.id)

    if (ticketId === props.ticketId) {
      comments.value = comments.value.filter((entry) => entry.id !== comment.id)
    }

    ui.notify('Commentaire retiré.')
  } catch (err) {
    ui.notify(err.message, 'error')
  }
}
</script>

<template>
  <section aria-labelledby="fil-du-ticket">
    <p id="fil-du-ticket" class="label-caps mb-2">
      discussion<template v-if="comments.length"> · {{ comments.length }}</template>
    </p>

    <div v-if="loading" class="py-3">
      <BaseSpinner class="size-4 text-ink-3" />
    </div>

    <ol v-else-if="comments.length" class="mb-3 flex flex-col gap-3" aria-label="Commentaires">
      <li
        v-for="comment in comments"
        :key="comment.id"
        class="rounded-card border border-line bg-panel px-3 py-2.5"
      >
        <header class="mb-1.5 flex items-center gap-2 text-[0.72rem] text-ink-3">
          <span class="font-semibold text-ink-2">{{
            comment.author_name ?? 'Compte supprimé'
          }}</span>
          <span>{{ formatRelative(comment.created_at) }}</span>
          <span class="flex-1" />
          <button
            v-if="canRemove(comment)"
            type="button"
            class="rounded-field p-1 text-ink-3 transition-colors hover:text-brick"
            :aria-label="`Retirer le commentaire de ${comment.author_name ?? 'ce compte'}`"
            @click="remove(comment)"
          >
            <AppIcon name="trash" :size="12" />
          </button>
        </header>

        <RichText :source="comment.body" compact />
      </li>
    </ol>

    <form class="flex flex-col gap-2" novalidate @submit.prevent="send">
      <label class="sr-only" :for="`commentaire-${ticketId}`">Votre commentaire</label>
      <textarea
        :id="`commentaire-${ticketId}`"
        v-model="draft"
        rows="3"
        class="input-field resize-y"
        placeholder="Répondre… **gras**, `code`, #12 pour un ticket"
        :aria-invalid="error ? 'true' : undefined"
        :aria-describedby="error ? `commentaire-erreur-${ticketId}` : undefined"
        @keydown.enter.ctrl.prevent="send"
        @keydown.enter.meta.prevent="send"
      />
      <p v-if="error" :id="`commentaire-erreur-${ticketId}`" class="text-[0.72rem] text-brick">
        {{ error }}
      </p>

      <div class="flex items-center justify-between gap-2">
        <span class="text-[0.68rem] text-ink-3">
          <kbd class="font-mono">Ctrl</kbd> + <kbd class="font-mono">Entrée</kbd> pour envoyer
        </span>
        <button
          type="submit"
          class="chip border-ink bg-ink text-paper transition-opacity hover:opacity-90 disabled:opacity-50"
          :disabled="sending || !draft.trim()"
        >
          commenter
        </button>
      </div>
    </form>
  </section>
</template>
