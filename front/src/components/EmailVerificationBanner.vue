<script setup>
/**
 * Rappel de confirmation d'adresse.
 *
 * Affiché tant que `email_verified_at` est nul. Volontairement non
 * bloquant : l'utilisateur peut travailler, mais le rappel reste visible et
 * l'envoi d'un nouveau lien est à portée de clic.
 */
import { ref } from 'vue'

import AppIcon from '@/components/AppIcon.vue'
import { accountApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

const auth = useAuthStore()
const ui = useUiStore()

const sending = ref(false)

async function resend() {
  sending.value = true

  try {
    const result = await accountApi.resendVerification()
    ui.notify(result?.message ?? 'Lien de confirmation renvoyé.')
  } catch (error) {
    // 409 = adresse déjà confirmée entre-temps (autre onglet) : on
    // resynchronise plutôt que d'afficher une erreur incompréhensible.
    if (error.status === 409) {
      await auth.loadProfile()
    } else {
      ui.notify(error.message, 'error')
    }
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <div
    v-if="auth.user && !auth.user.email_verified_at"
    class="mb-6 flex flex-wrap items-center gap-x-3 gap-y-2 border border-line border-l-2 border-l-ochre bg-ochre-bg px-3.5 py-2.5 text-[0.78rem] text-ink"
    role="status"
  >
    <AppIcon name="mail" :size="16" class="shrink-0 text-ochre" />

    <p class="flex-1">
      Confirmez votre adresse
      <span class="font-medium">{{ auth.user.email }}</span>
      pour sécuriser votre compte.
    </p>

    <button
      type="button"
      class="font-medium underline underline-offset-2 transition hover:no-underline disabled:opacity-60"
      :disabled="sending"
      @click="resend"
    >
      {{ sending ? 'Envoi…' : 'Renvoyer le lien' }}
    </button>
  </div>
</template>
