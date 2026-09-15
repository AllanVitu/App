<script setup>
/**
 * Double authentification : la section du profil.
 *
 * Un parcours en trois temps, chacun avec sa preuve : le mot de passe pour
 * commencer, un premier code pour activer, puis les codes de secours à ranger
 * avant de continuer. Désactiver redemande le mot de passe ET un code : un
 * jeton volé ne suffit pas à retirer la protection.
 *
 * Le QR code est fabriqué ICI, dans le navigateur : son contenu est le secret
 * lui-même, et le confier à un service de génération d'images reviendrait à le
 * publier. La bibliothèque n'est chargée qu'à ce moment-là.
 */
import { computed, onMounted, reactive, ref } from 'vue'

import AppIcon from '@/components/AppIcon.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { profileApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { formatDateTime } from '@/utils/format'

const auth = useAuthStore()
const ui = useUiStore()

/** { enabled, enabled_at, recovery_codes_remaining } — null tant qu'il n'est pas connu. */
const etat = ref(null)

/** repos · mot-de-passe · scan · codes · regeneration · desactivation */
const etape = ref('repos')

const occupe = ref(false)
const erreurs = ref({})
const saisie = reactive({ password: '', code: '' })

const miseEnPlace = ref(null)
const qr = ref('')
const codes = ref([])

/** La clé par groupes de quatre : elle se recopie sans se perdre. */
const cleLisible = computed(() =>
  (miseEnPlace.value?.secret ?? '').replace(/(.{4})/g, '$1 ').trim(),
)

const pluriel = computed(() => (etat.value?.recovery_codes_remaining > 1 ? 's' : ''))

onMounted(async () => {
  try {
    etat.value = await profileApi.twoFactor()
  } catch (error) {
    ui.notify(error.message, 'error')
  }
})

function aller(prochaine) {
  etape.value = prochaine
  erreurs.value = {}
  saisie.password = ''
  saisie.code = ''
}

function abandonner() {
  miseEnPlace.value = null
  qr.value = ''
  aller('repos')
}

async function tenter(action) {
  occupe.value = true
  erreurs.value = {}

  try {
    await action()
  } catch (error) {
    erreurs.value = error.errors ?? {}

    if (!Object.keys(erreurs.value).length) ui.notify(error.message, 'error')
  } finally {
    occupe.value = false
  }
}

const commencer = () =>
  tenter(async () => {
    miseEnPlace.value = await profileApi.twoFactorSetup(saisie.password)

    const { toDataURL } = await import('qrcode')
    qr.value = await toDataURL(miseEnPlace.value.uri, {
      margin: 1,
      width: 192,
      errorCorrectionLevel: 'M',
    })

    aller('scan')
  })

const activer = () =>
  tenter(async () => {
    const reponse = await profileApi.twoFactorEnable(saisie.code)

    codes.value = reponse.recovery_codes
    etat.value = reponse
    auth.setUser({ ...auth.user, two_factor_enabled: true })
    miseEnPlace.value = null
    qr.value = ''

    aller('codes')
  })

const regenerer = () =>
  tenter(async () => {
    const reponse = await profileApi.twoFactorRecoveryCodes(saisie.code)

    codes.value = reponse.recovery_codes
    etat.value = reponse

    aller('codes')
  })

const desactiver = () =>
  tenter(async () => {
    etat.value = await profileApi.twoFactorDisable({ password: saisie.password, code: saisie.code })
    auth.setUser({ ...auth.user, two_factor_enabled: false })

    aller('repos')
    ui.notify('Double authentification désactivée.')
  })

function telechargerCodes() {
  const texte = [
    `Codes de secours Relais — ${auth.user?.email ?? ''}`,
    '',
    ...codes.value,
    '',
    'Chacun ouvre une session une seule fois, si votre téléphone vous manque.',
  ].join('\n')

  const adresse = URL.createObjectURL(new Blob([texte], { type: 'text/plain' }))
  const lien = document.createElement('a')

  lien.href = adresse
  lien.download = 'relais-codes-de-secours.txt'
  lien.click()

  setTimeout(() => URL.revokeObjectURL(adresse), 10_000)
}

function terminer() {
  codes.value = []
  aller('repos')
}
</script>

<template>
  <section class="card p-6" aria-labelledby="double-authentification">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <h3 id="double-authentification" class="text-base font-semibold">
          Double authentification
        </h3>
        <p class="mt-1 text-sm text-ink-2">
          Un code à six chiffres, donné par une application d’authentification, en plus du mot de
          passe : un mot de passe volé ne suffit plus à entrer.
        </p>
      </div>

      <span v-if="etat?.enabled" class="chip border-moss bg-moss-bg text-moss">
        <AppIcon name="check" :size="12" />
        activée
      </span>
    </div>

    <div v-if="!etat" class="mt-5 flex justify-center py-4">
      <BaseSpinner class="size-5 text-ink" />
    </div>

    <!-- Au repos -->
    <template v-else-if="etape === 'repos'">
      <p v-if="etat.enabled" class="mt-4 text-sm text-ink-2">
        Activée le {{ formatDateTime(etat.enabled_at) }} ·
        {{ etat.recovery_codes_remaining }} code{{ pluriel }} de secours restant{{ pluriel }}.
      </p>

      <div class="mt-4 flex flex-wrap gap-2">
        <BaseButton v-if="!etat.enabled" @click="aller('mot-de-passe')">
          <AppIcon name="shield" :size="16" />
          Activer
        </BaseButton>
        <template v-else>
          <BaseButton variant="secondary" @click="aller('regeneration')">
            Régénérer les codes de secours
          </BaseButton>
          <BaseButton variant="secondary" @click="aller('desactivation')">Désactiver</BaseButton>
        </template>
      </div>
    </template>

    <!-- 1. Le mot de passe -->
    <form
      v-else-if="etape === 'mot-de-passe'"
      class="mt-5 space-y-4"
      novalidate
      @submit.prevent="commencer"
    >
      <BaseInput
        v-model="saisie.password"
        label="Mot de passe actuel"
        type="password"
        autocomplete="current-password"
        required
        :error="erreurs.password"
        hint="Changer la façon d’entrer dans le compte redemande le mot de passe."
      />
      <div class="flex gap-2">
        <BaseButton type="submit" :loading="occupe">Continuer</BaseButton>
        <BaseButton type="button" variant="secondary" @click="abandonner">Annuler</BaseButton>
      </div>
    </form>

    <!-- 2. Scanner, puis prouver que l'application a pris le secret -->
    <form v-else-if="etape === 'scan'" class="mt-5 space-y-4" novalidate @submit.prevent="activer">
      <ol class="list-decimal space-y-1 pl-5 text-sm text-ink-2">
        <li>
          Scannez ce QR code avec votre application d’authentification — Aegis, 1Password, Google
          Authenticator…
        </li>
        <li>Saisissez le code à six chiffres qu’elle affiche.</li>
      </ol>

      <div class="flex flex-wrap items-center gap-5">
        <img
          v-if="qr"
          :src="qr"
          alt="QR code à scanner avec votre application d’authentification"
          width="192"
          height="192"
          class="rounded-card border border-line bg-white p-2"
        />
        <p class="min-w-0 text-sm text-ink-3">
          Pas de caméra ? Saisissez cette clé :
          <code class="mt-1 block break-all font-mono text-[0.95rem] tracking-wide text-ink">{{
            cleLisible
          }}</code>
        </p>
      </div>

      <BaseInput
        v-model="saisie.code"
        label="Code de vérification"
        inputmode="numeric"
        autocomplete="one-time-code"
        placeholder="123 456"
        required
        :error="erreurs.code"
      />
      <div class="flex gap-2">
        <BaseButton type="submit" :loading="occupe">Activer</BaseButton>
        <BaseButton type="button" variant="secondary" @click="abandonner">Annuler</BaseButton>
      </div>
    </form>

    <!-- 3. Les codes de secours, une seule fois -->
    <div v-else-if="etape === 'codes'" class="mt-5 space-y-4">
      <p class="text-sm text-ink-2">
        <strong class="font-medium text-ink">Rangez ces codes maintenant</strong> : ils ne seront
        plus affichés. Chacun ouvre une session une seule fois, si votre téléphone vous manque.
      </p>
      <ul
        class="grid grid-cols-2 gap-2 font-mono text-[0.95rem] sm:grid-cols-5"
        aria-label="Codes de secours"
      >
        <li
          v-for="code in codes"
          :key="code"
          class="rounded-field border border-line bg-raised px-2 py-1.5 text-center"
        >
          {{ code }}
        </li>
      </ul>
      <div class="flex flex-wrap gap-2">
        <BaseButton variant="secondary" @click="telechargerCodes">Télécharger (.txt)</BaseButton>
        <BaseButton @click="terminer">J’ai rangé mes codes</BaseButton>
      </div>
    </div>

    <!-- Régénérer les codes de secours -->
    <form
      v-else-if="etape === 'regeneration'"
      class="mt-5 space-y-4"
      novalidate
      @submit.prevent="regenerer"
    >
      <BaseInput
        v-model="saisie.code"
        label="Code de vérification"
        inputmode="numeric"
        autocomplete="one-time-code"
        placeholder="123 456"
        required
        :error="erreurs.code"
        hint="Les codes de secours actuels cesseront de fonctionner."
      />
      <div class="flex gap-2">
        <BaseButton type="submit" :loading="occupe">Générer de nouveaux codes</BaseButton>
        <BaseButton type="button" variant="secondary" @click="aller('repos')">Annuler</BaseButton>
      </div>
    </form>

    <!-- Désactiver -->
    <form
      v-else-if="etape === 'desactivation'"
      class="mt-5 space-y-4"
      novalidate
      @submit.prevent="desactiver"
    >
      <BaseInput
        v-model="saisie.password"
        label="Mot de passe actuel"
        type="password"
        autocomplete="current-password"
        required
        :error="erreurs.password"
      />
      <BaseInput
        v-model="saisie.code"
        label="Code de vérification"
        inputmode="numeric"
        autocomplete="one-time-code"
        placeholder="123 456"
        required
        :error="erreurs.code"
      />
      <div class="flex gap-2">
        <BaseButton type="submit" variant="danger" :loading="occupe">Désactiver</BaseButton>
        <BaseButton type="button" variant="secondary" @click="aller('repos')">Annuler</BaseButton>
      </div>
    </form>
  </section>
</template>
