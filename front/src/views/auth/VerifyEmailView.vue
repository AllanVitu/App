<script setup>
/**
 * Confirmation d'adresse e-mail.
 *
 * La vérification part automatiquement au montage : l'utilisateur arrive ici
 * depuis un lien, il n'a rien de plus à saisir. Si une session est ouverte,
 * le profil est rechargé pour que le bandeau d'avertissement disparaisse.
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'

import AppIcon from '@/components/AppIcon.vue'
import SuccessBurst from '@/components/SuccessBurst.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { accountApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()

const token = computed(() => String(route.query.token ?? ''))

const state = ref('pending') // pending | success | error
const message = ref('')

onMounted(async () => {
  if (!token.value) {
    state.value = 'error'
    message.value = 'Ce lien de confirmation est incomplet.'

    return
  }

  try {
    const result = await accountApi.verifyEmail(token.value)
    message.value = result?.message ?? 'Votre adresse e-mail est confirmée.'
    state.value = 'success'

    if (auth.isAuthenticated) {
      await auth.loadProfile()
    }
  } catch (error) {
    message.value = error.message
    state.value = 'error'
  }
})
</script>

<template>
  <div class="text-center">
    <!-- Vérification en cours -->
    <template v-if="state === 'pending'">
      <BaseSpinner class="mx-auto size-8 text-ink" />
      <p class="mt-4 text-sm text-ink-2">Confirmation de votre adresse…</p>
    </template>

    <!-- Confirmée -->
    <template v-else-if="state === 'success'">
      <SuccessBurst burst class="mb-6" />
      <h1 class="text-[1.3rem] font-semibold">adresse confirmée</h1>
      <p class="mt-3 text-sm text-ink-2">{{ message }}</p>

      <BaseButton
        :to="auth.isAuthenticated ? { name: 'dashboard' } : { name: 'login' }"
        block
        size="lg"
        class="mt-8"
      >
        {{ auth.isAuthenticated ? 'Aller au tableau de bord' : 'Se connecter' }}
      </BaseButton>
    </template>

    <!-- Échec -->
    <template v-else>
      <div
        class="mx-auto mb-6 flex size-12 items-center justify-center rounded-full border border-brick/40 bg-brick-bg text-brick"
      >
        <AppIcon name="alert" :size="24" />
      </div>
      <h1 class="text-[1.3rem] font-semibold">confirmation impossible</h1>
      <p class="mt-3 text-sm text-ink-2">{{ message }}</p>
      <p class="mt-2 text-sm text-ink-2">Connectez-vous pour demander l'envoi d'un nouveau lien.</p>

      <BaseButton
        :to="auth.isAuthenticated ? { name: 'dashboard' } : { name: 'login' }"
        variant="secondary"
        block
        class="mt-8"
      >
        {{ auth.isAuthenticated ? 'Retour au tableau de bord' : 'Se connecter' }}
      </BaseButton>
    </template>
  </div>
</template>
