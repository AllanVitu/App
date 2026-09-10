<script setup>
/**
 * Écran Équipe : les membres de l'espace, leurs rôles, les invitations.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  CE QUI EST MASQUÉ ICI N'EST PAS CE QUI EST INTERDIT                    │
 * │                                                                         │
 * │  Le rôle sert à ne pas montrer des commandes qui échoueraient. C'est du │
 * │  confort, pas une barrière : chaque route vérifie le rôle de son côté,  │
 * │  et un bouton reconstitué à la main se heurterait au même 403.          │
 * │                                                                         │
 * │  Le rôle affiché vient de la RÉPONSE de l'API, jamais du store : il est │
 * │  relu en base à chaque requête, une rétrogradation prend donc effet     │
 * │  sans qu'on ait à recharger la page.                                    │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
import { computed, onMounted, reactive, ref } from 'vue'

import AppIcon from '@/components/AppIcon.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { organizationsApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { formatDate } from '@/utils/format'

const auth = useAuthStore()
const ui = useUiStore()

const loading = ref(true)
const members = ref([])
const invitations = ref([])
const role = ref('member')

const ROLES = {
  owner: 'propriétaire',
  admin: 'administrateur',
  member: 'membre',
}

const RANGS = { member: 1, admin: 2, owner: 3 }

const canManage = computed(() => RANGS[role.value] >= RANGS.admin)
const isOwner = computed(() => role.value === 'owner')

/** L'espace n'est quittable que s'il en reste un autre derrière. */
const canLeave = computed(() => auth.organizations.length > 1)

// --- Invitation --------------------------------------------------------------

const invite = reactive({ email: '', role: 'member', busy: false, errors: {} })

async function envoyerInvitation() {
  invite.busy = true
  invite.errors = {}

  try {
    await organizationsApi.invite(invite.email, invite.role)

    invite.email = ''
    ui.notify('Invitation envoyée.')
    await charger()
  } catch (erreur) {
    invite.errors = erreur.errors ?? {}

    if (!Object.keys(invite.errors).length) {
      ui.notify(erreur.message, 'error')
    }
  } finally {
    invite.busy = false
  }
}

async function revoquer(invitation) {
  try {
    await organizationsApi.revokeInvitation(invitation.id)
    ui.notify(`Invitation de ${invitation.email} révoquée.`)
    await charger()
  } catch (erreur) {
    ui.notify(erreur.message, 'error')
  }
}

// --- Membres ------------------------------------------------------------------

async function changerRole(membre, nouveau) {
  const precedent = membre.role

  // Changement optimiste : la liste répond au clic, et retombe sur sa valeur
  // d'origine si le serveur refuse.
  membre.role = nouveau

  try {
    await organizationsApi.updateMember(membre.id, nouveau)
    ui.notify(`${membre.full_name} est désormais ${ROLES[nouveau]}.`)
  } catch (erreur) {
    membre.role = precedent
    ui.notify(erreur.message, 'error')
  }
}

const exclusion = reactive({ open: false, membre: null, busy: false })

async function exclure() {
  exclusion.busy = true

  try {
    await organizationsApi.removeMember(exclusion.membre.id)
    ui.notify(`${exclusion.membre.full_name} ne fait plus partie de l'espace.`)
    exclusion.open = false
    await charger()
  } catch (erreur) {
    ui.notify(erreur.message, 'error')
  } finally {
    exclusion.busy = false
  }
}

// --- L'espace lui-même ---------------------------------------------------------

const renommage = reactive({ nom: '', busy: false })
const depart = reactive({ open: false, busy: false })

async function renommer() {
  renommage.busy = true

  try {
    const espace = await organizationsApi.rename(auth.organization.id, renommage.nom)

    auth.organization = { ...auth.organization, name: espace.name }
    await auth.reloadOrganizations()
    ui.notify('Espace renommé.')
  } catch (erreur) {
    ui.notify(erreur.message, 'error')
  } finally {
    renommage.busy = false
  }
}

async function quitter() {
  depart.busy = true

  try {
    await organizationsApi.leave()

    // Comme la bascule d'espace : tout l'écran change de sujet, on repart de
    // zéro plutôt que de rafraîchir chaque module ouvert.
    window.location.assign('/')
  } catch (erreur) {
    depart.busy = false
    depart.open = false
    ui.notify(erreur.message, 'error')
  }
}

// --- Chargement -----------------------------------------------------------------

async function charger() {
  try {
    const donnees = await organizationsApi.members()

    members.value = donnees.members
    invitations.value = donnees.invitations
    role.value = donnees.role
  } catch (erreur) {
    ui.notify(erreur.message, 'error')
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  renommage.nom = auth.organization?.name ?? ''
  await charger()
})
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-6">
    <header>
      <h2 class="text-lg font-semibold">{{ auth.organization?.name }}</h2>
      <p class="mt-1 text-sm text-ink-2">
        Tout ce que contient cet espace — tickets, tables, déploiements, erreurs, maquettes — est
        visible de chacun de ses membres.
      </p>
    </header>

    <div v-if="loading" class="flex justify-center py-20">
      <BaseSpinner class="size-8 text-ink" />
    </div>

    <template v-else>
      <!-- Membres -->
      <section class="card">
        <div class="flex items-baseline justify-between border-b border-line px-5 py-3">
          <h3 class="text-base font-semibold">Membres</h3>
          <span class="text-[0.72rem] tabular-nums text-ink-3">{{ members.length }}</span>
        </div>

        <ul>
          <li
            v-for="membre in members"
            :key="membre.id"
            class="flex items-center gap-3 border-b border-line px-5 py-3 last:border-b-0"
          >
            <span
              class="flex size-8 shrink-0 items-center justify-center border border-line bg-raised text-[0.68rem] font-semibold"
            >
              {{
                membre.full_name
                  .split(' ')
                  .filter(Boolean)
                  .slice(0, 2)
                  .map((mot) => mot[0].toUpperCase())
                  .join('')
              }}
            </span>

            <div class="min-w-0 flex-1">
              <p class="truncate text-[0.85rem]">
                {{ membre.full_name }}
                <span v-if="membre.id === auth.user?.id" class="text-ink-3">— vous</span>
              </p>
              <p class="truncate text-[0.72rem] text-ink-3">{{ membre.email }}</p>
            </div>

            <p class="hidden shrink-0 text-[0.72rem] text-ink-3 sm:block">
              depuis le {{ formatDate(membre.joined_at) }}
            </p>

            <!-- Le rôle devient un menu quand on peut le changer, et reste un
                 mot quand on ne le peut pas : deux états d'une même
                 information, jamais deux emplacements différents. -->
            <select
              v-if="
                canManage && membre.id !== auth.user?.id && (isOwner || membre.role !== 'owner')
              "
              class="input-field w-auto shrink-0 py-1 text-[0.76rem]"
              :value="membre.role"
              :aria-label="`Rôle de ${membre.full_name}`"
              @change="changerRole(membre, $event.target.value)"
            >
              <option v-if="isOwner" value="owner">propriétaire</option>
              <option value="admin">administrateur</option>
              <option value="member">membre</option>
            </select>
            <span v-else class="shrink-0 text-[0.76rem] text-ink-2">{{ ROLES[membre.role] }}</span>

            <button
              v-if="
                canManage && membre.id !== auth.user?.id && (isOwner || membre.role !== 'owner')
              "
              type="button"
              class="shrink-0 p-1 text-ink-3 transition-colors hover:text-brick"
              :aria-label="`Exclure ${membre.full_name}`"
              @click="((exclusion.membre = membre), (exclusion.open = true))"
            >
              <AppIcon name="close" :size="15" />
            </button>
          </li>
        </ul>
      </section>

      <!-- Invitations : réservées à ceux qui peuvent en émettre -->
      <section v-if="canManage" class="card">
        <div class="border-b border-line px-5 py-3">
          <h3 class="text-base font-semibold">Inviter quelqu'un</h3>
          <p class="mt-1 text-[0.78rem] text-ink-2">
            Un lien lui est envoyé par courriel. Il reste valable sept jours et fonctionne aussi
            pour quelqu'un qui n'a pas encore de compte.
          </p>
        </div>

        <form class="flex flex-wrap items-end gap-3 px-5 py-4" @submit.prevent="envoyerInvitation">
          <BaseInput
            v-model="invite.email"
            type="email"
            label="Adresse e-mail"
            placeholder="prenom@exemple.fr"
            :error="invite.errors.email"
            required
            class="min-w-56 flex-1"
          />

          <label class="shrink-0">
            <span class="label-field">rôle</span>
            <select v-model="invite.role" class="input-field py-2 text-[0.82rem]">
              <option value="member">membre</option>
              <option value="admin">administrateur</option>
            </select>
          </label>

          <BaseButton type="submit" :loading="invite.busy" class="shrink-0">Inviter</BaseButton>
        </form>

        <div v-if="invitations.length" class="border-t border-line">
          <p class="label-caps px-5 pb-1 pt-3">en attente</p>

          <ul>
            <li
              v-for="invitation in invitations"
              :key="invitation.id"
              class="flex items-center gap-3 px-5 py-2.5"
            >
              <AppIcon name="mail" :size="15" class="shrink-0 text-ink-3" />

              <div class="min-w-0 flex-1">
                <p class="truncate text-[0.82rem]">{{ invitation.email }}</p>
                <p class="truncate text-[0.72rem] text-ink-3">
                  {{ ROLES[invitation.role] }} ·
                  <!-- Une invitation périmée RESTE affichée, marquée comme
                       telle : la faire disparaître laisserait croire qu'elle
                       n'a jamais été envoyée. -->
                  <span :class="invitation.expired ? 'text-brick' : ''">
                    {{
                      invitation.expired
                        ? 'expirée'
                        : `expire le ${formatDate(invitation.expires_at)}`
                    }}
                  </span>
                </p>
              </div>

              <BaseButton variant="ghost" size="sm" @click="revoquer(invitation)">
                Révoquer
              </BaseButton>
            </li>
          </ul>
        </div>
      </section>

      <!-- L'espace lui-même -->
      <section class="card">
        <div class="border-b border-line px-5 py-3">
          <h3 class="text-base font-semibold">Cet espace</h3>
        </div>

        <div class="space-y-5 px-5 py-4">
          <form v-if="canManage" class="flex flex-wrap items-end gap-3" @submit.prevent="renommer">
            <BaseInput
              v-model="renommage.nom"
              label="Nom de l'espace"
              required
              class="min-w-56 flex-1"
            />
            <BaseButton
              type="submit"
              variant="secondary"
              :loading="renommage.busy"
              :disabled="renommage.nom === auth.organization?.name"
              class="shrink-0"
            >
              Renommer
            </BaseButton>
          </form>

          <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4">
            <div>
              <p class="text-[0.82rem]">Quitter cet espace</p>
              <p class="text-[0.72rem] text-ink-3">
                <template v-if="canLeave">
                  Vous perdez l'accès à son contenu. Ce que vous y avez écrit y reste.
                </template>
                <template v-else>
                  C'est votre seul espace : il faut en rejoindre ou en créer un autre d'abord.
                </template>
              </p>
            </div>

            <BaseButton
              variant="secondary"
              size="sm"
              :disabled="!canLeave"
              class="shrink-0"
              @click="depart.open = true"
            >
              Quitter
            </BaseButton>
          </div>
        </div>
      </section>
    </template>

    <ConfirmDialog
      :open="exclusion.open"
      title="Exclure ce membre"
      :message="`${exclusion.membre?.full_name} perdra l'accès à cet espace. Ce qu'il ou elle y a écrit y reste.`"
      confirm-label="Exclure"
      :loading="exclusion.busy"
      @confirm="exclure"
      @close="exclusion.open = false"
    />

    <ConfirmDialog
      :open="depart.open"
      title="Quitter cet espace"
      message="Vous perdrez l'accès à son contenu. Il faudra une nouvelle invitation pour y revenir."
      confirm-label="Quitter"
      :loading="depart.busy"
      @confirm="quitter"
      @close="depart.open = false"
    />
  </div>
</template>
