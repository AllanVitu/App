<script setup>
/**
 * Page d'inscription.
 *
 * La création du compte ouvre directement la session : l'API renvoie le
 * jeton d'accès et dépose le cookie de rafraîchissement dès la réponse 201.
 */
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'

import { animate, appEnter, DURATION, shake, stagger, STAGGER } from '@/animations/motion'
import AppIcon from '@/components/AppIcon.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { useMotion } from '@/composables/useMotion'
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
  // Le serveur refuse toute inscription sans consentement : la case n'est
  // pas une formalité d'affichage, elle fait partie du contrat d'API.
  terms_accepted: false,
})

const errors = ref({})
const globalError = ref('')
const loading = ref(false)
const formEl = ref(null)

/** Même cascade d'entrée que la page de connexion : les deux écrans se répondent. */
const root = useMotion(() => {
  animate('[data-anim="head"]', {
    translateY: [14, 0],
    opacity: [0, 1],
    duration: DURATION.base,
    delay: stagger(STAGGER.blocks),
    ease: appEnter,
  })

  animate('[data-anim="field"]', {
    translateY: [16, 0],
    opacity: [0, 1],
    duration: DURATION.base,
    delay: stagger(STAGGER.blocks, { start: 120 }),
    ease: appEnter,
  })
})

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
    { label: 'très faible', classes: 'w-1/5 bg-brick' },
    { label: 'faible', classes: 'w-2/5 bg-brick' },
    { label: 'moyen', classes: 'w-3/5 bg-ochre' },
    { label: 'bon', classes: 'w-4/5 bg-ochre' },
    { label: 'excellent', classes: 'w-full bg-moss' },
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

    shake(formEl.value)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div ref="root">
    <h1 data-anim="head" class="text-[1.3rem] font-semibold">créer un compte</h1>
    <p data-anim="head" class="mt-1.5 text-sm text-ink-2">Quelques secondes suffisent.</p>

    <form ref="formEl" class="mt-8 space-y-4" novalidate @submit.prevent="submit">
      <div
        v-if="globalError"
        class="flex items-start gap-2.5 rounded-field border border-brick/40 bg-brick-bg px-3.5 py-2.5 text-[0.8rem] text-ink"
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
          <div class="h-1 w-full overflow-hidden rounded-full bg-line">
            <div class="h-full rounded-full transition-all" :class="strength.classes" />
          </div>
          <p class="mt-1 text-xs text-ink-2">Robustesse : {{ strength.label }}</p>
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

      <div data-anim="field">
        <label class="flex cursor-pointer items-start gap-2.5">
          <input
            v-model="form.terms_accepted"
            type="checkbox"
            class="mt-0.5 size-4 shrink-0 accent-ink"
            :aria-invalid="Boolean(errors.terms_accepted)"
          />
          <span class="text-[0.8rem] leading-snug text-ink-2">
            J'accepte les
            <RouterLink
              :to="{ name: 'terms' }"
              class="font-medium text-ink underline underline-offset-2"
            >
              conditions générales
            </RouterLink>
            et la conservation des données décrites.
          </span>
        </label>

        <p v-if="errors.terms_accepted" class="mt-1.5 text-[0.72rem] text-brick">
          {{ errors.terms_accepted }}
        </p>
      </div>

      <BaseButton data-anim="field" type="submit" :loading="loading" block size="lg">
        Créer mon compte
      </BaseButton>
    </form>

    <p class="mt-8 text-center text-sm text-ink-2">
      Déjà inscrit ?
      <RouterLink
        :to="{ name: 'login' }"
        class="font-medium text-ink underline underline-offset-2 hover:text-ink-2"
      >
        Se connecter
      </RouterLink>
    </p>
  </div>
</template>
