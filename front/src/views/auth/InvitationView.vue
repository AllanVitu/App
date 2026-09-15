<script setup>
/**
 * Accueil d'un lien d'invitation.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  CELUI QUI OUVRE CE LIEN N'A LE PLUS SOUVENT PAS DE COMPTE              │
 * │                                                                         │
 * │  C'est le cas COURANT, pas le cas limite : on invite des gens de        │
 * │  l'extérieur. La page annonce donc d'abord à quoi l'on est convié, et   │
 * │  ne demande qu'ensuite de se connecter ou de s'inscrire.                │
 * │                                                                         │
 * │  Elle ne consomme rien à l'affichage. Une invitation ne s'accepte que   │
 * │  par un geste — sans quoi ouvrir le lien depuis un poste où traîne la   │
 * │  session de quelqu'un d'autre le ferait entrer à sa place.              │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'

import SuccessBurst from '@/components/SuccessBurst.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { invitationsApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()

const token = computed(() => String(route.query.token ?? ''))

const state = ref('loading') // loading | ready | accepted | error
const invitation = ref(null)
const message = ref('')
const busy = ref(false)

const ROLES = {
  owner: 'propriétaire',
  admin: 'administrateur',
  member: 'membre',
}

/**
 * Le jeton voyage jusqu'au formulaire d'inscription, qui le renvoie à l'API :
 * un compte créé depuis un lien entre dans l'espace sans repasser par ici.
 */
const inscription = computed(() => ({
  name: 'register',
  query: { invitation: token.value, email: invitation.value?.email },
}))

const connexion = computed(() => ({
  name: 'login',
  query: { redirect: `/invitation?token=${token.value}` },
}))

async function accepter() {
  busy.value = true

  try {
    await invitationsApi.accept(token.value)

    state.value = 'accepted'

    // Rechargement complet plutôt que navigation : l'espace actif vient de
    // changer, et tout ce qui est en mémoire appartient au précédent.
    setTimeout(() => window.location.assign('/'), 900)
  } catch (erreur) {
    busy.value = false
    message.value = erreur.message
    state.value = 'error'
  }
}

onMounted(async () => {
  if (!token.value) {
    state.value = 'error'
    message.value = "Ce lien d'invitation est incomplet."

    return
  }

  try {
    invitation.value = await invitationsApi.show(token.value)
    state.value = 'ready'
  } catch (erreur) {
    message.value = erreur.message
    state.value = 'error'
  }
})
</script>

<template>
  <div class="text-center">
    <template v-if="state === 'loading'">
      <BaseSpinner class="mx-auto size-8 text-ink" />
      <p class="mt-4 text-sm text-ink-2">Vérification du lien…</p>
    </template>

    <template v-else-if="state === 'accepted'">
      <SuccessBurst burst class="mb-6" />
      <h2 class="text-lg font-semibold">Bienvenue dans {{ invitation?.organization_name }}</h2>
      <p class="mt-2 text-sm text-ink-2">Ouverture de l'espace…</p>
    </template>

    <template v-else-if="state === 'ready'">
      <p class="label-caps">invitation</p>

      <h2 class="mt-2 text-xl font-semibold">{{ invitation.organization_name }}</h2>

      <p class="mt-3 text-sm leading-relaxed text-ink-2">
        Vous êtes invité·e à rejoindre cet espace de travail en tant que
        <strong class="text-ink">{{ ROLES[invitation.role] }}</strong
        >, à l'adresse <strong class="text-ink">{{ invitation.email }}</strong
        >.
      </p>

      <!-- Session ouverte : un seul geste suffit. -->
      <div v-if="auth.isAuthenticated" class="mt-7 space-y-3">
        <BaseButton block :loading="busy" @click="accepter">Rejoindre l'espace</BaseButton>

        <!-- L'adresse connectée peut ne pas être celle de l'invitation. Le dire
             évite de chercher pourquoi « rien ne se passe » : l'invitation
             fonctionne quand même, mais elle rattache CE compte-ci. -->
        <p v-if="auth.user?.email !== invitation.email" class="text-[0.76rem] text-ink-3">
          Vous êtes connecté·e avec {{ auth.user?.email }}. C'est ce compte-là qui rejoindra
          l'espace.
        </p>
      </div>

      <!-- Aucune session : les deux portes, l'inscription d'abord puisque
           c'est le cas le plus fréquent. -->
      <div v-else class="mt-7 space-y-3">
        <BaseButton :to="inscription" block>Créer un compte</BaseButton>
        <BaseButton :to="connexion" variant="secondary" block> J'ai déjà un compte </BaseButton>
      </div>
    </template>

    <template v-else>
      <h2 class="text-lg font-semibold">Ce lien ne fonctionne plus</h2>
      <p class="mt-2 text-sm text-ink-2">{{ message }}</p>
      <p class="mt-1 text-[0.78rem] text-ink-3">
        Demandez une nouvelle invitation à un administrateur de l'espace.
      </p>

      <!-- La sortie dépend de qui est là. Proposer « la connexion » à
           quelqu'un de déjà connecté l'aurait envoyé sur une page qui le
           renvoie aussitôt d'où il vient : un aller-retour pour rien, et un
           libellé qui ment sur sa destination. -->
      <BaseButton
        :to="{ name: auth.isAuthenticated ? 'dashboard' : 'login' }"
        variant="secondary"
        class="mt-7"
      >
        {{ auth.isAuthenticated ? "Retour à l'application" : 'Retour à la connexion' }}
      </BaseButton>
    </template>
  </div>
</template>
