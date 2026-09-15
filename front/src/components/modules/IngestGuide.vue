<script setup>
/**
 * « Comment envoyer vos erreurs ici » — la porte d'entrée du module
 * Supervision.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  CE QUE CE PANNEAU CORRIGE                                          │
 * │                                                                     │
 * │  Le module promettait des « erreurs de production ». Il n'affichait │
 * │  que son jeu de démonstration, et rien nulle part ne disait comment │
 * │  y envoyer quoi que ce soit. Un outil de supervision sans porte     │
 * │  d'entrée ne supervise rien — il décore.                            │
 * │                                                                     │
 * │  L'endpoint existait, mais était protégé par un jeton de SESSION,   │
 * │  valable quinze minutes. Il accepte désormais une clé d'API de      │
 * │  service (cf. back : IngestMiddleware), ce qui est la seule forme   │
 * │  d'authentification qu'un serveur peut réellement détenir.          │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * L'exemple est DONNÉ EN ENTIER, avec la vraie URL de l'instance. Un extrait
 * à trous — « remplacez YOUR_API_URL » — se recopie mal et se débogue plus
 * mal encore.
 *
 * La clé, elle, n'est jamais affichée ici : elle n'existe en clair qu'une
 * seule fois, à sa création, dans le module Backend. Ce panneau y renvoie.
 */
import { computed } from 'vue'

import AppIcon from '@/components/AppIcon.vue'
import { modulePath } from '@/utils/modules'

/**
 * URL réelle de l'API, telle que ce navigateur la joint. Elle vient de la
 * configuration de compilation — la même variable que le client HTTP —, donc
 * l'exemple reste juste quel que soit l'environnement.
 */
const apiBase = computed(() => import.meta.env.VITE_API_BASE_URL ?? '/api')

const snippet = computed(
  () => `curl -X POST ${apiBase.value}/errors \\
  -H "Authorization: Bearer sk_votre_cle_de_service" \\
  -H "Content-Type: application/json" \\
  -d '{
    "fingerprint": "checkout-undefined-total",
    "title": "TypeError: impossible de lire « total »",
    "culprit": "checkout.js:88",
    "level": "error",
    "message": "Cannot read properties of undefined (reading total)",
    "stack": "at checkout (checkout.js:88)"
  }'`,
)

/**
 * L'EMPREINTE est le seul champ dont la valeur demande réflexion : c'est elle
 * qui décide si deux occurrences sont le même problème. Les autres se lisent
 * tout seuls, et les documenter ligne à ligne ferait un mur qu'on ne lit pas.
 */
const FIELDS = [
  ['fingerprint', 'Ce qui identifie le problème. Deux envois de même empreinte forment UN groupe.'],
  ['title', "Ce qui s'affiche dans la liste."],
  ['culprit', "L'endroit du code. Facultatif, mais c'est par lui qu'on reconnaît une erreur."],
  ['level', '« warning », « error » ou « fatal ». Par défaut « error ».'],
  ['message', 'Le message complet.'],
  ['stack', "La pile d'appels. Facultative."],
]
</script>

<template>
  <div class="space-y-4 text-[0.84rem]">
    <p class="text-ink-2">
      Ce module reçoit les erreurs de vos applications. L'envoi se fait en HTTP, authentifié par une
      <strong class="font-semibold text-ink">clé d'API de service</strong> — pas par une session :
      un serveur ne peut pas en ouvrir une.
    </p>

    <!-- Le chemin pour obtenir une clé, en un clic, plutôt qu'une phrase qui
         décrit où aller. -->
    <RouterLink
      :to="modulePath('backend')"
      class="chip inline-flex border-line text-ink-2 transition-colors hover:border-ink-3 hover:text-ink"
    >
      <AppIcon name="database" :size="13" />
      créer une clé dans Backend
      <AppIcon name="arrow-right" :size="13" />
    </RouterLink>

    <div>
      <p class="label-caps mb-2">un envoi, en entier</p>
      <pre
        class="overflow-x-auto rounded-field border border-line bg-raised p-3 font-mono text-[0.72rem] leading-relaxed text-ink-2"
        >{{ snippet }}</pre>
    </div>

    <div>
      <p class="label-caps mb-2">les champs</p>
      <dl class="divide-y divide-line rounded-field border border-line">
        <div v-for="[name, hint] in FIELDS" :key="name" class="flex gap-3 px-3 py-2">
          <dt class="w-28 shrink-0 font-mono text-[0.72rem] text-ink">{{ name }}</dt>
          <dd class="min-w-0 flex-1 text-[0.78rem] text-ink-2">{{ hint }}</dd>
        </div>
      </dl>
    </div>

    <!-- Le regroupement est LA règle qui surprend : sans elle, on croit avoir
         perdu des envois alors qu'ils ont fusionné. -->
    <p class="rounded-field border border-line bg-raised px-3 py-2 text-[0.78rem] text-ink-2">
      Mille envois de même empreinte font <strong class="text-ink">un seul groupe</strong>, avec un
      compteur d'occurrences. C'est voulu : un problème récurrent est un problème à corriger, pas
      mille lignes à faire défiler. Une erreur déjà résolue qui se reproduit rouvre son statut
      d'elle-même.
    </p>
  </div>
</template>
