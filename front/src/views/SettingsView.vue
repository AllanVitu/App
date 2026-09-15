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
import FilterChip from '@/components/ui/FilterChip.vue'
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
  density: 'confortable',
  reduce_motion: false,
  notifications: { email: true, push: false, weekly_digest: true },
})

const THEMES = [
  { value: 'light', label: 'Clair', icon: 'sun' },
  { value: 'dark', label: 'Sombre', icon: 'moon' },
  { value: 'system', label: 'Système', icon: 'monitor' },
]

/**
 * UNE SEULE LANGUE, parce qu'il n'y en a qu'une.
 *
 * « English » figurait ici et ne traduisait rien : l'interface n'existe qu'en
 * français, des libellés jusqu'aux messages d'erreur du serveur. Un choix qui
 * ne change rien est une promesse creuse — exactement ce que cette phase a
 * corrigé partout ailleurs.
 *
 * Le champ RESTE dans l'API et en base : il est validé, testé, et servira le
 * jour où les traductions existeront. C'est le CHOIX qu'on retire, pas la
 * possibilité.
 */
const LANGUAGES = [{ value: 'fr', label: 'Français' }]

/** Deux grains, et ils changent vraiment quelque chose à l'écran. */
const DENSITIES = [
  { value: 'confortable', label: 'Confortable' },
  { value: 'compact', label: 'Compact' },
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

function hydrate(settings) {
  form.theme = settings.theme
  form.language = settings.language
  form.timezone = settings.timezone
  form.density = settings.density ?? 'confortable'
  form.reduce_motion = Boolean(settings.reduce_motion)
  form.notifications = { ...form.notifications, ...settings.notifications }
}

/** Aperçu immédiat du thème, avant même l'enregistrement. */
function previewTheme(value) {
  form.theme = value
  ui.applyTheme(value)
}

/**
 * Aperçu immédiat de la densité et du mouvement, comme pour le thème.
 *
 * Un réglage d'apparence se juge à l'œil : demander d'enregistrer pour voir,
 * puis de rouvrir l'écran pour changer d'avis, fait trois allers-retours là où
 * il en faut zéro. L'enregistrement fixe le choix, il ne le révèle pas.
 */
function previewDisplay(champ, valeur) {
  form[champ] = valeur
  ui.applyDisplay({ density: form.density, reduce_motion: form.reduce_motion })
}

async function save() {
  saving.value = true
  errors.value = {}

  try {
    const updated = await settingsApi.update({
      theme: form.theme,
      language: form.language,
      timezone: form.timezone,
      density: form.density,
      reduce_motion: form.reduce_motion,
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

        <!-- CE N'EST PAS UNE PASTILLE, malgré le même vocabulaire d'états.
             `FilterChip` est un jeton d'une ligne dans une barre d'outils ;
             ceci est une carte en colonne, icône au-dessus du libellé, dans
             une grille. Seule la façon de dire « choisi » est commune — le
             survol de l'état inactif s'aligne donc sur celui des pastilles,
             là où il ne faisait rien du tout (`hover:border-line` sur un
             élément déjà `border-line`). -->
        <div class="mt-5 grid gap-3 sm:grid-cols-3">
          <button
            v-for="option in THEMES"
            :key="option.value"
            type="button"
            class="flex flex-col items-center gap-2 border-2 px-4 py-4 transition"
            :class="
              form.theme === option.value
                ? 'border-ink bg-raised text-ink'
                : 'border-line text-ink-2 hover:border-ink-3 hover:text-ink'
            "
            :aria-pressed="form.theme === option.value"
            @click="previewTheme(option.value)"
          >
            <AppIcon :name="option.icon" :size="22" />
            <span class="text-sm font-medium">{{ option.label }}</span>
          </button>
        </div>
        <p v-if="errors.theme" class="mt-2 text-xs text-brick">{{ errors.theme }}</p>

        <!-- DENSITÉ. Un seul point d'action côté CSS : la taille de base,
             dont dépendent toutes les mesures en « rem ». -->
        <div class="mt-6 border-t border-line pt-5">
          <p class="label-field mb-0">Densité</p>
          <p class="mb-3 mt-1 text-xs text-ink-3">
            « Compact » resserre l'interface pour afficher plus de lignes à l'écran.
          </p>

          <div class="flex flex-wrap gap-2">
            <FilterChip
              v-for="option in DENSITIES"
              :key="option.value"
              :active="form.density === option.value"
              @click="previewDisplay('density', option.value)"
            >
              {{ option.label }}
            </FilterChip>
          </div>
          <p v-if="errors.density" class="mt-2 text-xs text-brick">{{ errors.density }}</p>
        </div>

        <!-- MOUVEMENT. Le réglage système reste respecté ; celui-ci s'y
             ajoute. On peut vouloir couper les animations d'UNE application
             sans les couper partout — et beaucoup ignorent que le réglage
             système existe. -->
        <div class="mt-5 border-t border-line pt-5">
          <label class="flex cursor-pointer items-start gap-3">
            <input
              v-model="form.reduce_motion"
              type="checkbox"
              class="mt-0.5 size-4 shrink-0 accent-(--c-ink)"
              @change="previewDisplay('reduce_motion', form.reduce_motion)"
            />
            <span>
              <span class="block text-[0.86rem] font-medium">Réduire les animations</span>
              <span class="mt-0.5 block text-xs text-ink-3">
                Si votre système les a déjà réduites, ce réglage n'a rien à changer — il ne sert
                qu'à les couper ici sans les couper partout.
              </span>
            </span>
          </label>
        </div>

        <!-- LE SON. Il existait, il persistait, et il n'avait pas sa place
             ici : son seul interrupteur vivait dans la barre du haut. Ce qui
             manquait n'était pas la fonctionnalité mais l'endroit où on la
             cherche.

             Il reste côté CLIENT, hors du formulaire : l'écran d'entrée sonore
             est proposé AVANT toute connexion, et le stocker par compte le
             rendrait indisponible au moment précis où il est demandé. Il n'a
             donc rien à faire dans l'enregistrement, et s'applique au clic. -->
        <div class="mt-5 border-t border-line pt-5">
          <label class="flex cursor-pointer items-start gap-3">
            <input
              type="checkbox"
              class="mt-0.5 size-4 shrink-0 accent-(--c-ink)"
              :checked="ui.soundOn"
              @change="ui.setSound($event.target.checked)"
            />
            <span>
              <span class="block text-[0.86rem] font-medium">Retours sonores</span>
              <span class="mt-0.5 block text-xs text-ink-3">
                Survols, clics et notifications. Appliqué tout de suite, et retenu sur cet appareil
                plutôt que sur le compte.
              </span>
            </span>
          </label>
        </div>
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
            <p class="mt-1.5 text-xs text-ink-3">
              L'interface n'existe qu'en français pour l'instant.
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

        <!-- LES TROIS INTERRUPTEURS ONT ÉTÉ RETIRÉS, pas cachés.
             Ils étaient enregistrés et consommés par personne : l'envoi
             périodique demande un ordonnanceur côté serveur, le push un
             service worker, et cette pile n'a ni l'un ni l'autre. Trois
             boutons qui donnent le sentiment d'agir sans rien déclencher
             valent moins qu'une phrase qui dit où on en est.
             La préférence reste en base et dans l'API : c'est le CONTRÔLE
             qu'on retire, pas la possibilité. -->
        <p class="mt-1.5 text-[0.84rem] text-ink-2">
          Aucune notification n'est envoyée pour l'instant. L'envoi périodique demande un
          ordonnanceur côté serveur, qui ne fait pas partie de cette installation — les réglages
          reviendront le jour où il y aura quelque chose à régler.
        </p>

        <p class="mt-3 flex items-start gap-2 text-[0.8rem] text-ink-3">
          <AppIcon name="info" :size="14" class="mt-0.5 shrink-0" />
          Ce que l'application signale déjà, elle le fait à l'écran : « demande attention » sur
          l'accueil, et les compteurs de chaque module.
        </p>
      </section>

      <div class="flex justify-end">
        <BaseButton type="submit" :loading="saving">Enregistrer les paramètres</BaseButton>
      </div>
    </form>
  </div>
</template>
