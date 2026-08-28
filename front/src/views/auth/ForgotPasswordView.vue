<script setup>
/**
 * Demande de réinitialisation de mot de passe.
 *
 * L'API répond volontairement la même chose que l'adresse existe ou non ;
 * l'écran reprend ce parti pris et n'indique jamais si un compte a été trouvé.
 */
import { ref } from 'vue'

import AppIcon from '@/components/AppIcon.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { accountApi } from '@/services/api'

const email = ref('')
const errors = ref({})
const globalError = ref('')
const loading = ref(false)
const sent = ref(false)

async function submit() {
  loading.value = true
  errors.value = {}
  globalError.value = ''

  try {
    await accountApi.forgotPassword(email.value)
    sent.value = true
  } catch (error) {
    errors.value = error.errors ?? {}

    if (!Object.keys(errors.value).length) {
      globalError.value = error.message
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div>
    <!-- Confirmation -->
    <template v-if="sent">
      <div
        class="mb-6 flex size-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400"
      >
        <AppIcon name="mail" :size="24" />
      </div>

      <h1 class="text-2xl font-bold tracking-tight">Vérifiez votre boîte mail</h1>
      <p class="mt-3 text-sm leading-relaxed text-slate-500">
        Si un compte est associé à <span class="font-medium text-slate-700 dark:text-slate-300">{{ email }}</span>,
        un lien de réinitialisation vient d'y être envoyé. Il est valable une heure.
      </p>
      <p class="mt-3 text-sm text-slate-500">
        Pensez à regarder dans les indésirables si le message tarde.
      </p>

      <BaseButton :to="{ name: 'login' }" variant="secondary" block class="mt-8">
        Retour à la connexion
      </BaseButton>
    </template>

    <!-- Formulaire -->
    <template v-else>
      <h1 class="text-2xl font-bold tracking-tight">Mot de passe oublié</h1>
      <p class="mt-1.5 text-sm text-slate-500">
        Indiquez votre adresse : nous vous enverrons un lien de réinitialisation.
      </p>

      <form class="mt-8 space-y-4" novalidate @submit.prevent="submit">
        <div
          v-if="globalError"
          class="flex items-start gap-2.5 rounded-lg border border-red-200 bg-red-50 px-3.5 py-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-400"
          role="alert"
        >
          <AppIcon name="alert" :size="18" class="mt-0.5 shrink-0" />
          <span>{{ globalError }}</span>
        </div>

        <BaseInput
          v-model="email"
          label="Adresse e-mail"
          type="email"
          placeholder="vous@exemple.fr"
          autocomplete="email"
          required
          :error="errors.email"
        />

        <BaseButton type="submit" :loading="loading" block size="lg">
          Envoyer le lien
        </BaseButton>
      </form>

      <p class="mt-8 text-center text-sm text-slate-500">
        <RouterLink
          :to="{ name: 'login' }"
          class="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
        >
          Retour à la connexion
        </RouterLink>
      </p>
    </template>
  </div>
</template>
