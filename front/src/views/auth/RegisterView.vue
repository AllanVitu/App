<script setup>
/**
 * Page d'inscription.
 *
 * La création du compte ouvre directement la session : l'API renvoie le
 * jeton d'accès et dépose le cookie de rafraîchissement dès la réponse 201.
 */
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'

import { gsap } from '@/animations/gsap'
import AppIcon from '@/components/AppIcon.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { useGsap } from '@/composables/useGsap'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()

const form = reactive({
  full_name: '',
  email: '',
  password: '',
  password_confirmation: '',
})

const errors = ref({})
const globalError = ref('')
const loading = ref(false)
const formEl = ref(null)

/** Même cascade d'entrée que la page de connexion : les deux écrans se répondent. */
const root = useGsap(() => {
  gsap.fromTo(
    '[data-anim="head"]',
    { y: 14, opacity: 0 },
    { y: 0, opacity: 1, duration: 0.5, stagger: 0.06 },
  )
  gsap.fromTo(
    '[data-anim="field"]',
    { y: 16, opacity: 0 },
    { y: 0, opacity: 1, duration: 0.5, stagger: 0.06, delay: 0.12 },
  )
})

/** Inscription refusée : le formulaire se secoue avant même qu'on lise l'erreur. */
function shakeForm() {
  if (!formEl.value) return

  gsap.fromTo(formEl.value, { x: -9 }, { x: 0, duration: 0.65, ease: 'appShake' })
}

/**
 * Indicateur de robustesse — purement informatif. La règle qui fait foi
 * (8 caractères, une lettre, un chiffre) est appliquée par l'API.
 */
const strength = computed(() => {
  const value = form.password
  if (!value) return { score: 0, label: '', classes: '' }

  let score = 0
  if (value.length >= 8) score++
  if (value.length >= 12) score++
  if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++
  if (/\d/.test(value)) score++
  if (/[^a-zA-Z0-9]/.test(value)) score++

  const levels = [
    { label: 'Très faible', classes: 'w-1/5 bg-red-500' },
    { label: 'Faible', classes: 'w-2/5 bg-orange-500' },
    { label: 'Moyen', classes: 'w-3/5 bg-amber-500' },
    { label: 'Bon', classes: 'w-4/5 bg-lime-500' },
    { label: 'Excellent', classes: 'w-full bg-emerald-500' },
  ]

  return { score, ...levels[Math.max(0, score - 1)] }
})

async function submit() {
  loading.value = true
  errors.value = {}
  globalError.value = ''

  try {
    await auth.register({ ...form })
    ui.notify('Votre compte a été créé. Bienvenue !')
    await router.push({ name: 'dashboard' })
  } catch (error) {
    errors.value = error.errors ?? {}

    if (!Object.keys(errors.value).length) {
      globalError.value = error.message
    }

    shakeForm()
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div ref="root">
    <h1 data-anim="head" class="text-2xl font-bold tracking-tight">Créer un compte</h1>
    <p data-anim="head" class="mt-1.5 text-sm text-slate-500">Quelques secondes suffisent.</p>

    <form ref="formEl" class="mt-8 space-y-4" novalidate @submit.prevent="submit">
      <div
        v-if="globalError"
        class="flex items-start gap-2.5 rounded-lg border border-red-200 bg-red-50 px-3.5 py-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-400"
        role="alert"
      >
        <AppIcon name="alert" :size="18" class="mt-0.5 shrink-0" />
        <span>{{ globalError }}</span>
      </div>

      <BaseInput
        v-model="form.full_name"
        data-anim="field"
        label="Nom complet"
        placeholder="Jean Dupont"
        autocomplete="name"
        required
        :error="errors.full_name"
      />

      <BaseInput
        v-model="form.email"
        data-anim="field"
        label="Adresse e-mail"
        type="email"
        placeholder="vous@exemple.fr"
        autocomplete="email"
        required
        :error="errors.email"
      />

      <div data-anim="field">
        <BaseInput
          v-model="form.password"
          label="Mot de passe"
          type="password"
          placeholder="••••••••"
          autocomplete="new-password"
          required
          :error="errors.password"
          hint="8 caractères minimum, dont une lettre et un chiffre."
        />

        <div v-if="form.password" class="mt-2">
          <div class="h-1 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
            <div class="h-full rounded-full transition-all" :class="strength.classes" />
          </div>
          <p class="mt-1 text-xs text-slate-500">Robustesse : {{ strength.label }}</p>
        </div>
      </div>

      <BaseInput
        v-model="form.password_confirmation"
        data-anim="field"
        label="Confirmation du mot de passe"
        type="password"
        placeholder="••••••••"
        autocomplete="new-password"
        required
        :error="errors.password_confirmation"
      />

      <BaseButton data-anim="field" type="submit" :loading="loading" block size="lg">
        Créer mon compte
      </BaseButton>
    </form>

    <p class="mt-8 text-center text-sm text-slate-500">
      Déjà inscrit ?
      <RouterLink
        :to="{ name: 'login' }"
        class="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
      >
        Se connecter
      </RouterLink>
    </p>
  </div>
</template>
