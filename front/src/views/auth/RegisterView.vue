<script setup>
/**
 * Page d'inscription.
 *
 * La création du compte ouvre directement la session : l'API renvoie le
 * jeton d'accès et dépose le cookie de rafraîchissement dès la réponse 201.
 */
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { animate, appEnter, DURATION, shake, stagger, STAGGER } from '@/animations/motion'
import AppIcon from '@/components/AppIcon.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { useMotion } from '@/composables/useMotion'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { PASSWORD_HINT, passwordStrength } from '@/utils/password'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()

/**
 * Jeton d'invitation, quand on arrive depuis un lien.
 *
 * Il est transmis à l'API AVEC l'inscription : le compte est créé, reçoit son
 * propre espace, puis entre dans celui qui l'a invité — le tout en une
 * requête. Le faire en deux appels laisserait, si le second échouait, un
 * compte tout neuf dans un espace vide, sans rien pour expliquer pourquoi.
 */
const invitation = computed(() => String(route.query.invitation ?? ''))

const form = reactive({
  full_name: '',
  // Préremplie depuis le lien : l'invitation vise une adresse précise, et
  // c'est celle-là qui la fera aboutir.
  email: String(route.query.email ?? ''),
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
 * Indicateur de robustesse. Il comptait des cases — longueur, majuscules,
 * chiffres — et annonçait « bon » des mots de passe que l'API refusait. Il
 * applique désormais la règle de l'API (utils/password), qui seule fait foi :
 * « bon » et « excellent » veulent dire « accepté », et rien d'autre.
 */
const LEVELS = [
  { label: 'très faible', classes: 'w-1/5 bg-brick' },
  { label: 'faible', classes: 'w-2/5 bg-brick' },
  { label: 'insuffisant', classes: 'w-3/5 bg-ochre' },
  { label: 'bon', classes: 'w-4/5 bg-moss' },
  { label: 'excellent', classes: 'w-full bg-moss' },
]

const strength = computed(() => {
  const { score } = passwordStrength(form.password)

  return score ? { score, ...LEVELS[score - 1] } : { score: 0, label: '', classes: '' }
})

async function submit() {
  loading.value = true
  errors.value = {}
  globalError.value = ''

  try {
    await auth.register({
      ...form,
      ...(invitation.value ? { invitation_token: invitation.value } : {}),
    })

    ui.notify(
      invitation.value
        ? `Bienvenue dans ${auth.organization?.name ?? 'votre nouvel espace'} !`
        : 'Votre compte a été créé. Bienvenue !',
    )

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
    <h1 data-anim="head">
      <span class="label-caps block">{{ invitation ? 'Invitation' : 'Inscription' }}</span>
      <span
        class="mt-2.5 block text-[1.875rem] font-bold leading-[1.1] tracking-[-0.015em] [font-stretch:85%] [text-wrap:balance]"
      >
        {{ invitation ? 'Rejoignez votre équipe.' : "Créez l'espace de votre équipe." }}
      </span>
    </h1>
    <p data-anim="head" class="mt-2 text-[0.9375rem] text-ink-2">
      {{
        invitation
          ? "Créez votre compte : vous entrerez directement dans l'espace qui vous a invité."
          : 'Deux minutes, puis vous invitez qui vous voulez.'
      }}
    </p>

    <form ref="formEl" class="mt-7 space-y-4" novalidate @submit.prevent="submit">
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
        placeholder="Camille Martin"
        autocomplete="name"
        required
        :error="errors.full_name"
      />

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
          autocomplete="new-password"
          required
          :error="errors.password"
          :hint="PASSWORD_HINT"
        />

        <!-- Cinq segments et non une jauge continue : on lit « quatre sur
             cinq » d'un coup d'œil, pas « un peu plus des trois quarts ».
             Vert à partir de « bon », parce que « bon » veut dire « accepté »
             (cf. utils/password). -->
        <div v-if="form.password" class="mt-2.5">
          <div class="grid grid-cols-5 gap-1" aria-hidden="true">
            <span
              v-for="n in 5"
              :key="n"
              class="h-1"
              :class="
                n <= strength.score
                  ? strength.score >= 4
                    ? 'bg-moss'
                    : strength.score === 3
                      ? 'bg-ochre'
                      : 'bg-brick'
                  : 'bg-line'
              "
            />
          </div>
          <p class="mt-1.5 text-xs text-ink-2">Robustesse : {{ strength.label }}</p>
        </div>
      </div>

      <BaseInput
        v-model="form.password_confirmation"
        data-anim="field"
        label="Confirmation du mot de passe"
        type="password"
        placeholder="••••••••••••"
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
          <span class="text-[0.84rem] leading-snug text-ink-2">
            J'accepte les
            <RouterLink
              :to="{ name: 'terms' }"
              class="font-medium text-ink underline decoration-line-2 underline-offset-[3px] transition-colors hover:decoration-ink"
              >conditions générales</RouterLink
            >
            et j'ai lu la
            <RouterLink
              :to="{ name: 'privacy' }"
              class="font-medium text-ink underline decoration-line-2 underline-offset-[3px] transition-colors hover:decoration-ink"
              >politique de confidentialité</RouterLink
            >.
          </span>
        </label>

        <p v-if="errors.terms_accepted" class="mt-1.5 text-[0.72rem] text-brick">
          {{ errors.terms_accepted }}
        </p>
      </div>

      <BaseButton data-anim="field" type="submit" :loading="loading" block size="lg">
        {{ invitation ? 'Créer mon compte' : 'Créer mon espace' }}
      </BaseButton>
    </form>

    <p class="mt-7 border-t border-line pt-5.5 text-sm text-ink-2">
      Déjà un compte ?
      <RouterLink
        :to="{ name: 'login' }"
        class="font-medium text-ink underline decoration-line-2 underline-offset-[3px] transition-colors hover:decoration-ink"
      >
        Se connecter
      </RouterLink>
    </p>
  </div>
</template>
