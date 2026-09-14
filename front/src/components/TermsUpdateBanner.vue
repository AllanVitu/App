<script setup>
/**
 * Les conditions générales ont changé depuis que ce compte les a acceptées.
 *
 * Non bloquant, comme le rappel de confirmation d'adresse : on ne retient pas
 * le travail d'une équipe derrière un texte. Mais le rappel reste tant que la
 * nouvelle version n'est pas acceptée, et l'acceptation est enregistrée avec
 * son numéro — celui que publie le serveur (cf. ProfileController::acceptTerms).
 */
import { computed, ref } from 'vue'

import AppIcon from '@/components/AppIcon.vue'
import { profileApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { TERMS_VERSION, needsTermsAcceptance } from '@/utils/legal'

const auth = useAuthStore()
const ui = useUiStore()

const sending = ref(false)
const visible = computed(() => needsTermsAcceptance(auth.user))

async function accept() {
  sending.value = true

  try {
    auth.setUser(await profileApi.acceptTerms())
    ui.notify('Conditions générales acceptées.')
  } catch (error) {
    ui.notify(error.message, 'error')
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <div
    v-if="visible"
    class="mb-6 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-field border border-line-2 bg-raised px-4 py-2.5 text-[0.8rem] text-ink"
    role="status"
  >
    <AppIcon name="info" :size="16" class="shrink-0 text-ink-2" />

    <p class="flex-1">
      Les conditions générales ont changé (version {{ TERMS_VERSION }}).
      <RouterLink
        :to="{ name: 'terms' }"
        class="font-medium underline underline-offset-2 transition-colors hover:no-underline"
      >
        Lire les conditions
      </RouterLink>
    </p>

    <button
      type="button"
      class="font-medium underline underline-offset-2 transition-colors hover:no-underline disabled:opacity-60"
      :disabled="sending"
      @click="accept"
    >
      {{ sending ? 'Enregistrement…' : 'J’accepte' }}
    </button>
  </div>
</template>
