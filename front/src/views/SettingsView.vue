<script setup>
/**
 * Page Paramètres : apparence, langue, fuseau horaire et notifications.
 *
 * Le thème est appliqué immédiatement à la sélection (retour visuel direct)
 * puis persisté en base à l'enregistrement.
 */
import { onMounted, reactive, ref } from 'vue'

import AppIcon from '@/components/AppIcon.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { settingsApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

const auth = useAuthStore()
const ui = useUiStore()

const loading = ref(true)
const saving = ref(false)
const errors = ref({})

const form = reactive({
  theme: 'system',
  language: 'fr',
  timezone: 'Europe/Paris',
  notifications: { email: true, push: false, weekly_digest: true },
})

const THEMES = [
  { value: 'light', label: 'Clair', icon: 'sun' },
  { value: 'dark', label: 'Sombre', icon: 'moon' },
  { value: 'system', label: 'Système', icon: 'monitor' },
]

const LANGUAGES = [
  { value: 'fr', label: 'Français' },
  { value: 'en', label: 'English' },
]

// Sous-ensemble courant ; l'API valide contre la liste complète des fuseaux PHP.
const TIMEZONES = [
  'Europe/Paris',
  'Europe/London',
  'Europe/Brussels',
  'Europe/Madrid',
  'Europe/Lisbon',
  'America/Montreal',
  'America/New_York',
  'UTC',
]

const NOTIFICATIONS = [
  {
    key: 'email',
    label: 'Notifications par e-mail',
    hint: 'Alertes sur les échéances et les modifications.',
  },
  { key: 'push', label: 'Notifications push', hint: 'Messages instantanés dans le navigateur.' },
  {
    key: 'weekly_digest',
    label: 'Résumé hebdomadaire',
    hint: 'Un récapitulatif de votre activité chaque lundi.',
  },
]

function hydrate(settings) {
  form.theme = settings.theme
  form.language = settings.language
  form.timezone = settings.timezone
  form.notifications = { ...form.notifications, ...settings.notifications }
}

/** Aperçu immédiat du thème, avant même l'enregistrement. */
function previewTheme(value) {
  form.theme = value
  ui.applyTheme(value)
}

async function save() {
  saving.value = true
  errors.value = {}

  try {
    const updated = await settingsApi.update({
      theme: form.theme,
      language: form.language,
      timezone: form.timezone,
      notifications: form.notifications,
    })

    auth.setSettings(updated)
    ui.applyTheme(updated.theme)
    ui.notify('Paramètres enregistrés.')
  } catch (error) {
    errors.value = error.errors ?? {}

    if (!Object.keys(errors.value).length) {
      ui.notify(error.message, 'error')
    }
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  try {
    // Les préférences sont déjà en mémoire après la connexion : on évite un
    // appel réseau, avec repli sur l'API si le store est vide.
    hydrate(auth.settings ?? (await settingsApi.show()))
  } catch (error) {
    ui.notify(error.message, 'error')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="mx-auto max-w-3xl">
    <div v-if="loading" class="flex justify-center py-20">
      <BaseSpinner class="size-8 text-ink" />
    </div>

    <form v-else class="space-y-6" @submit.prevent="save">
      <!-- Apparence -->
      <section class="card p-6">
        <h3 class="text-base font-semibold">Apparence</h3>
        <p class="mt-1 text-sm text-ink-2">
          « Système » suit le réglage clair/sombre de votre appareil.
        </p>

        <div class="mt-5 grid gap-3 sm:grid-cols-3">
          <button
            v-for="option in THEMES"
            :key="option.value"
            type="button"
            class="flex flex-col items-center gap-2 border-2 px-4 py-4 transition"
            :class="
              form.theme === option.value
                ? 'border-ink bg-raised text-ink '
                : 'border-line text-ink-2 hover:border-line '
            "
            :aria-pressed="form.theme === option.value"
            @click="previewTheme(option.value)"
          >
            <AppIcon :name="option.icon" :size="22" />
            <span class="text-sm font-medium">{{ option.label }}</span>
          </button>
        </div>
        <p v-if="errors.theme" class="mt-2 text-xs text-brick">{{ errors.theme }}</p>
      </section>

      <!-- Régionalisation -->
      <section class="card p-6">
        <h3 class="text-base font-semibold">Langue et région</h3>

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
          <div>
            <label for="language" class="label-field">Langue</label>
            <select id="language" v-model="form.language" class="input-field">
              <option v-for="lang in LANGUAGES" :key="lang.value" :value="lang.value">
                {{ lang.label }}
              </option>
            </select>
            <!-- Dit franchement ce que ce réglage fait AUJOURD'HUI. L'interface
                 n'existe qu'en français : laisser croire qu'elle bascule en
                 anglais serait exactement le genre de promesse creuse que ce
                 projet corrige ailleurs. La préférence est bien enregistrée —
                 elle servira quand les traductions existeront. -->
            <p class="mt-1.5 text-xs text-ink-3">
              Enregistrée pour plus tard : l'interface n'est traduite qu'en français.
            </p>
            <p v-if="errors.language" class="mt-1.5 text-xs text-brick">{{ errors.language }}</p>
          </div>

          <div>
            <label for="timezone" class="label-field">Fuseau horaire</label>
            <select id="timezone" v-model="form.timezone" class="input-field">
              <option v-for="zone in TIMEZONES" :key="zone" :value="zone">{{ zone }}</option>
            </select>
            <p class="mt-1.5 text-xs text-ink-3">
              Toutes les dates et les échéances de l'application s'y règlent.
            </p>
            <p v-if="errors.timezone" class="mt-1.5 text-xs text-brick">{{ errors.timezone }}</p>
          </div>
        </div>
      </section>

      <!-- Notifications -->
      <section class="card p-6">
        <h3 class="text-base font-semibold">Notifications</h3>

        <!-- Ces trois préférences sont enregistrées mais rien ne les consomme
             encore : l'envoi périodique demande un ordonnanceur côté serveur,
             et le push demande un service worker. Aucun des deux n'existe dans
             cette pile. Le dire vaut mieux que trois interrupteurs qui donnent
             le sentiment d'agir. -->
        <p class="mt-1.5 text-[0.8rem] text-ink-3">
          Vos choix sont conservés. Aucun envoi n'est encore effectué : cela demande un ordonnanceur
          côté serveur, qui n'est pas en place.
        </p>

        <div class="mt-4 divide-y divide-line">
          <label
            v-for="option in NOTIFICATIONS"
            :key="option.key"
            class="flex cursor-pointer items-center justify-between gap-4 py-4"
          >
            <span class="min-w-0">
              <span class="block text-sm font-medium">{{ option.label }}</span>
              <span class="mt-0.5 block text-sm text-ink-2">{{ option.hint }}</span>
            </span>

            <!-- Interrupteur : la case native reste présente (accessibilité
                 clavier et lecteurs d'écran), seule sa présentation change. -->
            <span class="relative inline-flex shrink-0">
              <input
                v-model="form.notifications[option.key]"
                type="checkbox"
                class="peer sr-only"
              />
              <span
                class="block h-5 w-9 border border-line bg-raised transition-colors peer-checked:border-ink peer-checked:bg-ink peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-ink"
              />
              <span
                class="pointer-events-none absolute left-[3px] top-[3px] size-3.5 bg-ink transition-transform peer-checked:translate-x-4 peer-checked:bg-paper"
              />
            </span>
          </label>
        </div>
      </section>

      <div class="flex justify-end">
        <BaseButton type="submit" :loading="saving">Enregistrer les paramètres</BaseButton>
      </div>
    </form>
  </div>
</template>
