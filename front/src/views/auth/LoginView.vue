<script setup>
/**
 * Page de connexion.
 *
 * Les erreurs de validation proviennent du champ `errors` de la réponse 422 :
 * les règles ne sont pas dupliquées côté client, la seule autorité reste l'API.
 */
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { animate, appEnter, DURATION, shake, stagger, STAGGER } from '@/animations/motion'
import AppIcon from '@/components/AppIcon.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { useMotion } from '@/composables/useMotion'
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

/**
 * Arrivée du formulaire : titre puis champs, en cascade.
 *
 * Durées en MILLISECONDES : cet écran appartient à la moitié publique et
 * passe par le point d'entrée unique du mouvement (cf. animations/motion.js).
 */
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

    // Le mouvement précède la lecture du message : on sait qu'on a échoué
    // avant même d'avoir lu pourquoi.
    shake(formEl.value)
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
    <h1 data-anim="head" class="text-[1.3rem] font-semibold">connexion</h1>
    <p data-anim="head" class="mt-1.5 text-sm text-ink-2">Accédez à votre espace de travail.</p>

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
            class="text-xs font-medium text-ink underline underline-offset-2 hover:text-ink-2"
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
      class="mt-4 w-full border border-dashed border-line px-3 py-2.5 text-xs text-ink-2 transition-colors hover:border-ink hover:text-ink"
      @click="fillDemo"
    >
      Utiliser le compte de démonstration
    </button>

    <p class="mt-8 text-center text-sm text-ink-2">
      Pas encore de compte ?
      <RouterLink
        :to="{ name: 'register' }"
        class="font-medium text-ink underline underline-offset-2 hover:text-ink-2"
      >
        Créer un compte
      </RouterLink>
    </p>
  </div>
</template>
