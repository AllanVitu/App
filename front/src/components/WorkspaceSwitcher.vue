<script setup>
/**
 * Sélecteur d'espace de travail.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  UNE BASCULE CHANGE TOUT L'ÉCRAN, ET DOIT DONC SE VOIR VENIR            │
 * │                                                                         │
 * │  Les cinq modules, le tableau de bord, la recherche : tout ce qui est   │
 * │  affiché appartient à l'espace courant. Le sélecteur reste donc         │
 * │  visible en permanence, en tête de la barre latérale — comme le nom de  │
 * │  la ligne sur un quai. Savoir OÙ l'on est doit précéder la question de  │
 * │  ce qu'on y voit.                                                       │
 * │                                                                         │
 * │  Et la bascule RECHARGE la page. Chaque écran ouvert détient des        │
 * │  données de l'ancien espace ; les rafraîchir un par un reviendrait à    │
 * │  tenir une liste qu'on finirait par oublier de compléter. Un            │
 * │  rechargement franc ne peut rien laisser derrière lui, et le geste est  │
 * │  rare.                                                                  │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
import { computed, nextTick, onScopeDispose, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'

import AppIcon from '@/components/AppIcon.vue'
import UserAvatar from '@/components/ui/UserAvatar.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

const auth = useAuthStore()
const ui = useUiStore()

const { organization, organizations } = storeToRefs(auth)

const open = ref(false)
const busy = ref(false)
const liste = ref(null)

/** Le rôle en toutes lettres : « owner » ne veut rien dire pour un lecteur. */
const ROLES = {
  owner: 'propriétaire',
  admin: 'administrateur',
  member: 'membre',
}

const roleLabel = computed(() => ROLES[organization.value?.role] ?? '')

/**
 * Échap ferme, D'OÙ QUE VIENNE LA FRAPPE.
 *
 * Le gestionnaire était posé sur le panneau lui-même : il ne recevait la
 * touche que si le focus s'y trouvait encore. Un clic n'importe où ailleurs
 * — dans le panneau compris — et le menu ne se fermait plus qu'à la souris.
 *
 * L'écoute est ici posée sur le document, et seulement tant que le menu est
 * ouvert : rien ne reste branché une fois refermé.
 */
function surEchap(evenement) {
  if (evenement.key === 'Escape') open.value = false
}

watch(open, (ouvert) => {
  if (ouvert) {
    document.addEventListener('keydown', surEchap)
  } else {
    document.removeEventListener('keydown', surEchap)
  }
})

// Le composant vit dans la barre latérale, donc pour toute la session — mais
// un menu laissé ouvert au démontage laisserait son écoute derrière lui.
onScopeDispose(() => document.removeEventListener('keydown', surEchap))

async function basculer() {
  open.value = !open.value

  if (!open.value) return

  // La liste des espaces peut avoir bougé ailleurs (invitation acceptée sur un
  // autre poste, exclusion). Elle est relue à l'ouverture plutôt qu'affichée
  // de mémoire.
  try {
    await auth.reloadOrganizations()
  } catch {
    // Une liste périmée reste plus utile qu'un menu vide : on garde
    // l'affichage précédent et la bascule échouera franchement si l'espace
    // n'existe plus.
  }

  await nextTick()
  liste.value?.querySelector('button')?.focus()
}

async function choisir(id) {
  if (id === organization.value?.id) {
    open.value = false

    return
  }

  busy.value = true

  try {
    await auth.switchOrganization(id)

    // Rechargement complet : cf. l'encadré en tête de fichier.
    window.location.assign('/')
  } catch (erreur) {
    busy.value = false
    open.value = false
    // « message » est toujours présent : le client HTTP normalise chaque
    // échec, réseau coupé compris. Un repli ici n'aurait jamais servi.
    ui.notify(erreur.message, 'error')
  }
}
</script>

<template>
  <div class="relative border-b border-line">
    <button
      type="button"
      class="flex w-full items-center gap-2.5 px-3 py-2.5 text-left transition-colors hover:bg-raised"
      :aria-expanded="open"
      aria-haspopup="listbox"
      @click="basculer"
    >
      <!-- « muted » : ce carré désigne un ESPACE, pas une personne. Même
           forme, encre plus discrète — sans quoi il se confondrait avec
           l'avatar du compte, deux lignes plus bas dans la même barre. -->
      <UserAvatar :name="organization?.name ?? ''" size="sm" muted />

      <span class="min-w-0 flex-1">
        <span class="block truncate text-[0.78rem] font-semibold">
          {{ organization?.name ?? '—' }}
        </span>
        <span class="block truncate text-[0.66rem] text-ink-3">{{ roleLabel }}</span>
      </span>

      <AppIcon
        name="chevron-down"
        :size="13"
        class="shrink-0 text-ink-3 transition-transform"
        :class="open ? 'rotate-180' : ''"
      />
    </button>

    <!-- Le panneau se referme au clic hors de lui comme à l'échappement : un
         menu qu'on ne sait pas fermer est un menu qu'on n'ouvre plus. -->
    <div v-if="open" class="fixed inset-0 z-40" aria-hidden="true" @click="open = false" />

    <div
      v-if="open"
      ref="liste"
      role="listbox"
      class="absolute inset-x-2 top-full z-50 mt-1 border border-line-2 bg-panel py-1 shadow-lg"
    >
      <p class="label-caps px-3 pb-1 pt-1">espaces</p>

      <button
        v-for="espace in organizations"
        :key="espace.id"
        type="button"
        role="option"
        :aria-selected="espace.id === organization?.id"
        class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-[0.78rem] transition-colors hover:bg-raised disabled:opacity-50"
        :disabled="busy"
        @click="choisir(espace.id)"
      >
        <!-- Le repère de l'espace courant prend l'encre, comme les entrées de
             navigation hors module : la couleur de ligne reste réservée aux
             cinq modules. -->
        <span
          class="ligne h-4 shrink-0"
          :class="espace.id === organization?.id ? 'bg-ink' : 'bg-transparent'"
          aria-hidden="true"
        />
        <span class="min-w-0 flex-1 truncate">{{ espace.name }}</span>
        <span class="shrink-0 text-[0.66rem] tabular-nums text-ink-3">
          {{ espace.members }}
        </span>
      </button>

      <div class="mt-1 border-t border-line pt-1">
        <RouterLink
          :to="{ name: 'team' }"
          class="flex w-full items-center gap-2 px-3 py-1.5 text-[0.78rem] text-ink-2 transition-colors hover:bg-raised hover:text-ink"
          @click="open = false"
        >
          <span class="ligne h-4 shrink-0 bg-transparent" aria-hidden="true" />
          gérer l'équipe
        </RouterLink>
      </div>
    </div>
  </div>
</template>
