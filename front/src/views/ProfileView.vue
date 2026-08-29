<script setup>
/**
 * Page Profil : informations du compte, changement de mot de passe et
 * suppression définitive.
 */
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'

import AppIcon from '@/components/AppIcon.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import { profileApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { formatDateTime } from '@/utils/format'

const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()

// --- Informations ----------------------------------------------------------
const profileForm = reactive({
  full_name: auth.user?.full_name ?? '',
  avatar_url: auth.user?.avatar_url ?? '',
})

const profileErrors = ref({})
const savingProfile = ref(false)

async function saveProfile() {
  savingProfile.value = true
  profileErrors.value = {}

  try {
    const updated = await profileApi.update({
      full_name: profileForm.full_name,
      avatar_url: profileForm.avatar_url || null,
    })

    auth.setUser(updated)
    ui.notify('Profil mis à jour.')
  } catch (error) {
    profileErrors.value = error.errors ?? {}

    if (!Object.keys(profileErrors.value).length) {
      ui.notify(error.message, 'error')
    }
  } finally {
    savingProfile.value = false
  }
}

// --- Mot de passe ----------------------------------------------------------
const passwordForm = reactive({
  current_password: '',
  new_password: '',
  new_password_confirmation: '',
})

const passwordErrors = ref({})
const savingPassword = ref(false)

async function savePassword() {
  savingPassword.value = true
  passwordErrors.value = {}

  try {
    await profileApi.updatePassword({ ...passwordForm })

    // L'API révoque toutes les sessions : une reconnexion est obligatoire.
    ui.notify('Mot de passe modifié. Reconnectez-vous.', 'info')
    await auth.logout()
    await router.push({ name: 'login' })
  } catch (error) {
    passwordErrors.value = error.errors ?? {}

    if (!Object.keys(passwordErrors.value).length) {
      ui.notify(error.message, 'error')
    }
  } finally {
    savingPassword.value = false
  }
}

// --- Suppression du compte -------------------------------------------------
const confirmOpen = ref(false)
const deletePassword = ref('')
const deleting = ref(false)

async function deleteAccount() {
  deleting.value = true

  try {
    await profileApi.destroy(deletePassword.value)
    ui.notify('Votre compte a été supprimé.', 'info')
    await auth.logout()
    await router.push({ name: 'login' })
  } catch (error) {
    ui.notify(error.errors?.password ?? error.message, 'error')
  } finally {
    deleting.value = false
  }
}

const memberSince = computed(() => formatDateTime(auth.user?.created_at))
const lastLogin = computed(() => formatDateTime(auth.user?.last_login_at))
</script>

<template>
  <div class="mx-auto max-w-3xl space-y-6">
    <!-- Identité -->
    <section class="card p-6">
      <div class="flex flex-wrap items-center gap-5">
        <img
          v-if="auth.user?.avatar_url"
          :src="auth.user.avatar_url"
          alt=""
          class="size-16 rounded-full object-cover"
        />
        <div
          v-else
          class="flex size-16 items-center justify-center rounded-full bg-raised text-xl font-semibold text-ink"
        >
          {{ auth.initials }}
        </div>

        <div class="min-w-0">
          <h2 class="truncate text-lg font-semibold">{{ auth.user?.full_name }}</h2>
          <p class="truncate text-sm text-ink-2">{{ auth.user?.email }}</p>
          <span
            v-if="auth.user?.role === 'admin'"
            class="mt-1.5 inline-flex items-center gap-1 rounded-full bg-raised px-2 py-0.5 text-xs font-medium text-ink"
          >
            <AppIcon name="shield" :size="12" />
            Administrateur
          </span>
        </div>
      </div>

      <dl class="mt-6 grid gap-4 border-t border-line pt-5 sm:grid-cols-2">
        <div>
          <dt class="text-xs font-medium uppercase tracking-wide text-ink-3">Membre depuis</dt>
          <dd class="mt-1 text-sm">{{ memberSince }}</dd>
        </div>
        <div>
          <dt class="text-xs font-medium uppercase tracking-wide text-ink-3">Dernière connexion</dt>
          <dd class="mt-1 text-sm">{{ lastLogin }}</dd>
        </div>
      </dl>
    </section>

    <!-- Informations personnelles -->
    <section class="card p-6">
      <h3 class="text-base font-semibold">Informations personnelles</h3>
      <p class="mt-1 text-sm text-ink-2">
        L'adresse e-mail sert d'identifiant de connexion et n'est pas modifiable.
      </p>

      <form class="mt-5 space-y-4" novalidate @submit.prevent="saveProfile">
        <BaseInput
          v-model="profileForm.full_name"
          label="Nom complet"
          required
          autocomplete="name"
          :error="profileErrors.full_name"
        />

        <BaseInput :model-value="auth.user?.email" label="Adresse e-mail" type="email" disabled />

        <BaseInput
          v-model="profileForm.avatar_url"
          label="URL de l'avatar"
          placeholder="https://exemple.fr/photo.jpg"
          hint="Adresse http(s) d'une image."
          :error="profileErrors.avatar_url"
        />

        <div class="flex justify-end">
          <BaseButton type="submit" :loading="savingProfile">Enregistrer</BaseButton>
        </div>
      </form>
    </section>

    <!-- Sécurité -->
    <section class="card p-6">
      <h3 class="text-base font-semibold">Mot de passe</h3>
      <p class="mt-1 text-sm text-ink-2">
        Le changement déconnecte toutes vos sessions, y compris celle-ci.
      </p>

      <form class="mt-5 space-y-4" novalidate @submit.prevent="savePassword">
        <BaseInput
          v-model="passwordForm.current_password"
          label="Mot de passe actuel"
          type="password"
          autocomplete="current-password"
          required
          :error="passwordErrors.current_password"
        />

        <BaseInput
          v-model="passwordForm.new_password"
          label="Nouveau mot de passe"
          type="password"
          autocomplete="new-password"
          required
          hint="8 caractères minimum, dont une lettre et un chiffre."
          :error="passwordErrors.new_password"
        />

        <BaseInput
          v-model="passwordForm.new_password_confirmation"
          label="Confirmer le nouveau mot de passe"
          type="password"
          autocomplete="new-password"
          required
          :error="passwordErrors.new_password_confirmation"
        />

        <div class="flex justify-end">
          <BaseButton type="submit" variant="secondary" :loading="savingPassword">
            Modifier le mot de passe
          </BaseButton>
        </div>
      </form>
    </section>

    <!-- Zone sensible -->
    <section class="border border-brick bg-brick-bg p-6">
      <h3 class="text-base font-semibold text-brick">Supprimer le compte</h3>
      <p class="mt-1 text-sm text-brick">
        Toutes vos données seront définitivement effacées. Cette action est irréversible.
      </p>

      <BaseButton variant="danger" class="mt-4" @click="confirmOpen = true">
        <AppIcon name="trash" :size="16" />
        Supprimer mon compte
      </BaseButton>
    </section>

    <ConfirmDialog
      :open="confirmOpen"
      title="Supprimer définitivement le compte"
      message="Saisissez votre mot de passe pour confirmer la suppression."
      confirm-label="Supprimer définitivement"
      :loading="deleting"
      @confirm="deleteAccount"
      @close="confirmOpen = false"
    >
      <BaseInput
        v-model="deletePassword"
        type="password"
        placeholder="Votre mot de passe"
        autocomplete="current-password"
      />
    </ConfirmDialog>
  </div>
</template>
