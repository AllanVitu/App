<script setup>
/**
 * Page de connexion.
 *
 * Les erreurs de validation proviennent du champ `errors` de la réponse 422 :
 * les règles ne sont pas dupliquées côté client, la seule autorité reste l'API.
 */
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { gsap } from '@/animations/gsap'
import AppIcon from '@/components/AppIcon.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { useGsap } from '@/composables/useGsap'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()

const form = reactive({ email: '', password: '' })
const errors = ref({})
const globalError = ref('')
const loading = ref(false)
const formEl = ref(null)

/** Arrivée du formulaire : titre puis champs, en cascade. */
const root = useGsap(() => {
  gsap.fromTo(
    '[data-anim="head"]',
    { y: 14, opacity: 0 },
    { y: 0, opacity: 1, duration: 0.5, stagger: 0.06 },
  )
  gsap.fromTo(
    '[data-anim="field"]',
    { y: 16, opacity: 0 },
    { y: 0, opacity: 1, duration: 0.5, stagger: 0.07, delay: 0.12 },
  )
})

/**
 * Refus de connexion : le formulaire se secoue.
 * Le mouvement précède la lecture du message — l'utilisateur sait qu'il a
 * échoué avant même d'avoir lu pourquoi.
 */
function shakeForm() {
  if (!formEl.value) return

  gsap.fromTo(formEl.value, { x: -9 }, { x: 0, duration: 0.65, ease: 'appShake' })
}

async function submit() {
  loading.value = true
  errors.value = {}
  globalError.value = ''

  try {
    await auth.login({ email: form.email, password: form.password })
    ui.notify(`Bienvenue, ${auth.user.full_name} !`)

    // Retour à la page initialement demandée, sinon tableau de bord.
    await router.push(route.query.redirect || { name: 'dashboard' })
  } catch (error) {
    errors.value = error.errors ?? {}

    // Un message global n'a de sens que si aucune erreur de champ ne l'explique.
    if (!Object.keys(errors.value).length) {
      globalError.value = error.message
    }

    shakeForm()
  } finally {
    loading.value = false
  }
}

/** Pré-remplit le compte de démonstration créé par le seed SQL. */
function fillDemo() {
  form.email = 'demo@saas.local'
  form.password = 'Password123!'
}
</script>

<template>
  <div ref="root">
    <div class="mb-8 lg:hidden">
      <div class="mb-6 flex items-center gap-2.5">
        <div class="flex size-9 items-center justify-center rounded-lg bg-brand-600 text-white">
          <AppIcon name="sparkles" :size="20" />
        </div>
        <span class="text-lg font-semibold">SaaS App</span>
      </div>
    </div>

    <h1 data-anim="head" class="text-2xl font-bold tracking-tight">Connexion</h1>
    <p data-anim="head" class="mt-1.5 text-sm text-slate-500">Accédez à votre espace de travail.</p>

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
          autocomplete="current-password"
          required
          :error="errors.password"
        />

        <div class="mt-1.5 text-right">
          <RouterLink
            :to="{ name: 'forgot-password' }"
            class="text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
          >
            Mot de passe oublié ?
          </RouterLink>
        </div>
      </div>

      <BaseButton data-anim="field" type="submit" :loading="loading" block size="lg">
        Se connecter
      </BaseButton>
    </form>

    <button
      type="button"
      class="mt-4 w-full rounded-lg border border-dashed border-slate-300 px-3 py-2.5 text-xs text-slate-500 transition hover:border-brand-400 hover:text-brand-600 dark:border-slate-700 dark:hover:border-brand-500"
      @click="fillDemo"
    >
      Utiliser le compte de démonstration
    </button>

    <p class="mt-8 text-center text-sm text-slate-500">
      Pas encore de compte ?
      <RouterLink
        :to="{ name: 'register' }"
        class="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400"
      >
        Créer un compte
      </RouterLink>
    </p>
  </div>
</template>
