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
/**
 * Le raccourci vers le compte de démonstration n'existe qu'en développement :
 * en déploiement, la base n'en contient aucun (DB_SEED=none.sql), et un
 * bouton qui remplit un formulaire voué à l'échec est un piège.
 */
const demo = import.meta.env.DEV

function fillDemo() {
  form.email = 'demo@saas.local'
  form.password = 'Password123!'
}
</script>

<template>
  <div ref="root">
    <h1 data-anim="head">
      <span class="label-caps block">Connexion</span>
      <span
        class="mt-2.5 block text-[1.875rem] font-bold leading-[1.1] tracking-[-0.015em] [font-stretch:85%] [text-wrap:balance]"
      >
        Reprenez là où l'équipe s'est arrêtée.
      </span>
    </h1>

    <form ref="formEl" class="mt-8 space-y-4.5" novalidate @submit.prevent="submit">
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
        placeholder="vous@entreprise.fr"
        autocomplete="email"
        required
        :error="errors.email"
      />

      <div data-anim="field">
        <BaseInput
          v-model="form.password"
          label="Mot de passe"
          type="password"
          placeholder="••••••••••••"
          autocomplete="current-password"
          required
          :error="errors.password"
        />

        <div class="mt-2 text-right">
          <RouterLink
            :to="{ name: 'forgot-password' }"
            class="text-[0.8rem] text-ink-2 underline decoration-line-2 underline-offset-[3px] transition-colors hover:text-ink hover:decoration-ink"
          >
            Mot de passe oublié ?
          </RouterLink>
        </div>
      </div>

      <BaseButton data-anim="field" type="submit" :loading="loading" block size="lg">
        Se connecter
        <!-- Le raccourci est dit, pas imposé : la touche Entrée envoie déjà
             tout formulaire. L'écrire, c'est l'apprendre à qui clique. -->
        <kbd
          class="ml-2 hidden rounded-card border border-current px-1.5 font-mono text-[0.6875rem] font-medium opacity-60 sm:inline"
          aria-hidden="true"
        >
          Entrée
        </kbd>
      </BaseButton>
    </form>

    <button
      v-if="demo"
      type="button"
      class="mt-4 w-full rounded-card border border-dashed border-line-2 px-3 py-2.5 text-xs text-ink-2 transition-colors hover:border-ink hover:text-ink"
      @click="fillDemo"
    >
      Utiliser le compte de démonstration
    </button>

    <p class="mt-7 border-t border-line pt-5.5 text-sm text-ink-2">
      Pas encore d'espace ?
      <RouterLink
        :to="{ name: 'register' }"
        class="font-medium text-ink underline decoration-line-2 underline-offset-[3px] transition-colors hover:decoration-ink"
      >
        Créer celui de votre équipe
      </RouterLink>
    </p>
  </div>
</template>
