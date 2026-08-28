<script setup>
/**
 * Choix d'un nouveau mot de passe à partir du lien reçu par e-mail.
 *
 * Le jeton est lu dans la query string. Accessible connecté ou non : un
 * utilisateur peut cliquer le lien depuis une session déjà ouverte.
 */
import { computed, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'

import AppIcon from '@/components/AppIcon.vue'
import SuccessBurst from '@/components/SuccessBurst.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { accountApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()

const token = computed(() => String(route.query.token ?? ''))

const form = reactive({ password: '', password_confirmation: '' })
const errors = ref({})
const globalError = ref('')
const loading = ref(false)
const done = ref(false)

async function submit() {
  loading.value = true
  errors.value = {}
  globalError.value = ''

  try {
    await accountApi.resetPassword({
      token: token.value,
      password: form.password,
      password_confirmation: form.password_confirmation,
    })

    // Le serveur a révoqué toutes les sessions : l'état local doit suivre,
    // sinon l'application croirait encore l'utilisateur connecté.
    if (auth.isAuthenticated) {
      await auth.logout()
    }

    done.value = true
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
    <!-- Lien absent ou tronqué -->
    <template v-if="!token">
      <div
        class="mb-6 flex size-12 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-400"
      >
        <AppIcon name="alert" :size="24" />
      </div>
      <h1 class="text-2xl font-bold tracking-tight">Lien incomplet</h1>
      <p class="mt-3 text-sm text-slate-500">
        Ce lien de réinitialisation est invalide. Demandez-en un nouveau.
      </p>
      <BaseButton :to="{ name: 'forgot-password' }" block class="mt-8">
        Demander un nouveau lien
      </BaseButton>
    </template>

    <!-- Succès -->
    <template v-else-if="done">
      <SuccessBurst class="mb-6 mx-0!" />
      <h1 class="text-2xl font-bold tracking-tight">Mot de passe modifié</h1>
      <p class="mt-3 text-sm leading-relaxed text-slate-500">
        Toutes les sessions ouvertes ont été déconnectées par sécurité.
        Connectez-vous avec votre nouveau mot de passe.
      </p>
      <BaseButton :to="{ name: 'login' }" block size="lg" class="mt-8">
        Se connecter
      </BaseButton>
    </template>

    <!-- Formulaire -->
    <template v-else>
      <h1 class="text-2xl font-bold tracking-tight">Nouveau mot de passe</h1>
      <p class="mt-1.5 text-sm text-slate-500">Choisissez un mot de passe que vous n'utilisez pas ailleurs.</p>

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
          v-model="form.password"
          label="Nouveau mot de passe"
          type="password"
          autocomplete="new-password"
          required
          hint="8 caractères minimum, dont une lettre et un chiffre."
          :error="errors.password"
        />

        <BaseInput
          v-model="form.password_confirmation"
          label="Confirmer le mot de passe"
          type="password"
          autocomplete="new-password"
          required
          :error="errors.password_confirmation"
        />

        <BaseButton type="submit" :loading="loading" block size="lg">
          Enregistrer le nouveau mot de passe
        </BaseButton>
      </form>
    </template>
  </div>
</template>
