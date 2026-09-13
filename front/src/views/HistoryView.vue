<script setup>
/**
 * Historique : ce qui s'est passé dans l'espace, et par qui.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  LA QUESTION À LAQUELLE CET ÉCRAN RÉPOND                                │
 * │                                                                         │
 * │  « Pourquoi ce ticket est-il passé en urgent ? », « qui a supprimé la   │
 * │  table clients ? », « quand cette clé d'API a-t-elle été révoquée ? ».  │
 * │                                                                         │
 * │  Aucune table métier ne peut y répondre : elle garde l'état courant,    │
 * │  pas le chemin qui y a mené. Le tableau de bord montrait donc des       │
 * │  CRÉATIONS et rien d'autre — les modifications, qui sont l'essentiel du │
 * │  travail d'une équipe, n'existaient nulle part.                         │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * OUVERT À TOUS LES MEMBRES, sans condition de rôle. Un journal que seuls les
 * administrateurs pourraient lire servirait à surveiller plutôt qu'à se
 * coordonner.
 */
import { computed, onMounted, ref } from 'vue'

import AppIcon from '@/components/AppIcon.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import FilterChip from '@/components/ui/FilterChip.vue'
import UserAvatar from '@/components/ui/UserAvatar.vue'
import { activityApi } from '@/services/api'
import { useQuerySync } from '@/composables/useQuerySync'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { formatDateTime, formatRelative } from '@/utils/format'
import { moduleLine, modulePath } from '@/utils/modules'

const ui = useUiStore()

const events = ref([])
const actors = ref([])
const next = ref(null)
const loading = ref(true)
const loadingMore = ref(false)

const moduleFilter = ref(null)
const actorFilter = ref(null)

// L'écran vit dans son adresse, comme les cinq modules : un historique filtré
// sur une personne se partage — c'est même le cas où l'on veut le plus
// envoyer un lien.
useQuerySync({ module: moduleFilter, acteur: actorFilter })

const MODULES = [
  { value: 'tickets', label: 'tickets' },
  { value: 'backend', label: 'backend' },
  { value: 'deploiement', label: 'déploiement' },
  { value: 'supervision', label: 'supervision' },
  { value: 'design', label: 'design' },
]

/**
 * Les verbes, en français et au passé.
 *
 * « created » ne veut rien dire pour un lecteur, et « a créé » se lit dans la
 * phrase que forme la ligne : « Alice a créé TICK-42 ».
 */
const ACTIONS = {
  created: 'a créé',
  updated: 'a modifié',
  deleted: 'a supprimé',
  restored: 'a restauré',
  versioned: 'a publié une version de',
  // Une erreur résolue ou supprimée qui frappe de nouveau. Ce n'est pas une
  // répétition de plus : c'est la seule qui annonce qu'une correction n'a pas
  // tenu.
  reopened: 'a signalé le retour de',
  'key.created': 'a émis la clé',
  'key.revoked': 'a révoqué une clé',
}

const auth = useAuthStore()

/**
 * L'auteur d'un fait qui n'en a pas.
 *
 * Dans un espace d'équipe, c'est une clé de service — une application
 * supervisée qui signale. Dans l'espace de l'instance, c'est l'application
 * elle-même qui range ses pannes : lui prêter une clé serait faux.
 */
const sansAuteur = computed(() =>
  auth.organization?.kind === 'instance' ? 'L’application' : 'Une clé de service',
)

const filtres = computed(() => ({
  ...(moduleFilter.value ? { module: moduleFilter.value } : {}),
  ...(actorFilter.value ? { acteur: actorFilter.value } : {}),
}))

async function charger({ suite = false } = {}) {
  if (suite) loadingMore.value = true
  else loading.value = true

  try {
    const page = await activityApi.list({
      ...filtres.value,
      ...(suite && next.value ? { avant: next.value } : {}),
    })

    events.value = suite ? [...events.value, ...page.events] : page.events
    next.value = page.next

    // La liste des acteurs ne bouge pas entre deux pages : la garder évite
    // que les pastilles de filtre ne clignotent au défilement.
    if (!suite) actors.value = page.actors
  } catch (erreur) {
    ui.notify(erreur.message, 'error')
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

/** Changer de filtre repart de la première page : le curseur ne vaut plus. */
function filtrer(champ, valeur) {
  const cible = champ === 'module' ? moduleFilter : actorFilter

  cible.value = cible.value === valeur ? null : valeur
  next.value = null

  charger()
}

/**
 * Où mène une entrée.
 *
 * Le journal ne porte pas d'URL : il porte un module et un sujet. C'est
 * l'écran qui sait composer le lien, et lui seul — le serveur n'a pas à
 * connaître la table de routage du client.
 *
 * Une entrée SUPPRIMÉE ne mène nulle part : le lien ouvrirait un écran vide.
 */
function lien(evenement) {
  if (evenement.action === 'deleted' || evenement.action.startsWith('key.')) return null

  return modulePath(evenement.module)
}

/**
 * Les champs touchés, en toutes lettres.
 *
 * Le journal stocke des noms techniques ; les montrer bruts (« due_date »)
 * ferait un écran d'administrateur système là où c'est un fil d'équipe.
 */
const CHAMPS = {
  title: 'titre',
  description: 'description',
  status: 'statut',
  priority: 'priorité',
  project: 'projet',
  labels: 'étiquettes',
  due_date: 'échéance',
  assigned_to: 'assignation',
  name: 'nom',
  kind: 'type',
  accent: 'couleur',
  columns: 'colonnes',
  rls_enabled: 'sécurité au niveau ligne',
  row_estimate: 'volume estimé',
  data: 'données',
  environment: 'environnement',
  branch: 'branche',
  commit_sha: 'empreinte',
  commit_message: 'message',
  url: 'adresse',
  log: 'journal',
}

const champs = (evenement) =>
  Object.keys(evenement.changes ?? {})
    .map((champ) => CHAMPS[champ] ?? champ)
    .join(', ')

onMounted(charger)
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-5">
    <header>
      <div class="flex items-stretch gap-3">
        <span class="ligne bg-ink" aria-hidden="true" />

        <div>
          <p class="label-caps">espace</p>
          <h2 class="mt-0.5 text-2xl font-extrabold lowercase tracking-[-0.03em]">historique</h2>
        </div>
      </div>

      <p class="mt-3 text-sm text-ink-2">
        Tout ce qui a été fait dans cet espace, par qui, et quand. Les cinq modules y écrivent.
      </p>
    </header>

    <!-- Filtres : le module d'abord, les personnes ensuite. On cherche plus
         souvent « ce qui s'est passé sur les déploiements » que « ce qu'a fait
         Bob ». -->
    <div class="flex flex-wrap items-center gap-2">
      <FilterChip :active="moduleFilter === null" @click="filtrer('module', null)">
        tous
      </FilterChip>

      <FilterChip
        v-for="module in MODULES"
        :key="module.value"
        :active="moduleFilter === module.value"
        @click="filtrer('module', module.value)"
      >
        <span class="ligne h-3" :class="moduleLine(module.value)" aria-hidden="true" />
        {{ module.label }}
      </FilterChip>

      <span v-if="actors.length" class="h-4 w-px bg-line" aria-hidden="true" />

      <FilterChip
        v-for="acteur in actors"
        :key="acteur.id"
        :active="actorFilter === acteur.id"
        @click="filtrer('acteur', acteur.id)"
      >
        <AppIcon name="user" :size="12" />
        {{ acteur.name }}
      </FilterChip>
    </div>

    <div v-if="loading" class="flex justify-center py-20">
      <BaseSpinner class="size-8 text-ink" />
    </div>

    <EmptyState
      v-else-if="!events.length"
      icon="clock"
      title="Rien pour l'instant"
      description="Le journal se remplit au fil du travail de l'équipe."
    />

    <ol v-else class="card divide-y divide-line">
      <li v-for="evenement in events" :key="evenement.id" class="flex items-start gap-3 px-4 py-3">
        <!-- La ligne du module, comme partout ailleurs : c'est le repère qui
             sert à balayer une liste sans lire. -->
        <span
          class="ligne mt-1 h-4 shrink-0"
          :class="moduleLine(evenement.module)"
          aria-hidden="true"
        />

        <UserAvatar :name="evenement.actor_name ?? ''" size="xs" class="mt-0.5" />

        <div class="min-w-0 flex-1">
          <p class="text-[0.82rem] leading-snug">
            <!-- Sans nom : une clé de service, ou une occurrence reçue d'une
                 application supervisée. « Quelqu'un » serait plus honnête
                 qu'un nom emprunté, mais moins juste que l'origine réelle. -->
            <span class="font-medium">{{ evenement.actor_name ?? sansAuteur }}</span>
            {{ ACTIONS[evenement.action] ?? evenement.action }}

            <component
              :is="lien(evenement) ? 'RouterLink' : 'span'"
              :to="lien(evenement)"
              :class="lien(evenement) ? 'underline decoration-line-2 hover:decoration-ink' : ''"
            >
              <span v-if="evenement.subject_ref" class="font-mono text-[0.78rem]">
                {{ evenement.subject_ref }}
              </span>
              <span v-else>{{ evenement.subject_title ?? 'un élément' }}</span>
            </component>

            <span v-if="evenement.subject_ref && evenement.subject_title" class="text-ink-2">
              — {{ evenement.subject_title }}
            </span>
          </p>

          <p v-if="champs(evenement)" class="mt-0.5 text-[0.72rem] text-ink-3">
            {{ champs(evenement) }}
          </p>
        </div>

        <!-- Relatif à l'œil, exact au survol : « il y a 3 h » se lit sans
             calcul, la date complète sert quand on cherche à recouper. -->
        <time
          class="shrink-0 text-[0.72rem] tabular-nums text-ink-3"
          :datetime="evenement.happened_at"
          :title="formatDateTime(evenement.happened_at)"
        >
          {{ formatRelative(evenement.happened_at) }}
        </time>
      </li>
    </ol>

    <!-- « next » vient du serveur : le bouton n'apparaît que s'il y a
         vraiment une suite, sans avoir à compter la table entière. -->
    <div v-if="next" class="flex justify-center">
      <BaseButton variant="secondary" :loading="loadingMore" @click="charger({ suite: true })">
        Charger la suite
      </BaseButton>
    </div>
  </div>
</template>
