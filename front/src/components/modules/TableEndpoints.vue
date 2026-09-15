<script setup>
/**
 * Les endpoints REST d'une table, affichés à côté de son schéma.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  UNE API QU'ON NE VOIT PAS N'EXISTE PAS                             │
 * │                                                                     │
 * │  Les tables décrites ici sont désormais de VRAIES tables            │
 * │  PostgreSQL, interrogeables par HTTP. Sans ce panneau, personne ne  │
 * │  le saurait : le module ressemblerait toujours à un éditeur de      │
 * │  diagrammes.                                                        │
 * │                                                                     │
 * │  Les adresses sont données EN ENTIER, avec l'URL réelle de          │
 * │  l'instance. Un extrait à trous — « remplacez YOUR_API_URL » — se   │
 * │  recopie mal et se débogue plus mal encore.                         │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * La clé n'est jamais affichée ici : elle n'existe en clair qu'une seule fois,
 * à sa création. Le panneau rappelle seulement laquelle utiliser.
 */
import { computed } from 'vue'

const props = defineProps({
  /** Nom de la table, tel qu'il apparaît dans l'URL. */
  name: { type: String, required: true },
  /** Colonnes décrites, pour composer un exemple d'insertion juste. */
  columns: { type: Array, default: () => [] },
})

const apiBase = computed(() => import.meta.env.VITE_API_BASE_URL ?? '/api')

const ROUTES = computed(() => [
  ['GET', `${apiBase.value}/backend/data/${props.name}`, 'lire les lignes'],
  ['POST', `${apiBase.value}/backend/data/${props.name}`, 'en ajouter une'],
  ['DELETE', `${apiBase.value}/backend/data/${props.name}/{id}`, 'en supprimer une'],
])

/**
 * Exemple d'insertion composé À PARTIR DU SCHÉMA RÉEL de la table.
 *
 * « id » en est retiré : il se remplit tout seul (clé primaire à valeur par
 * défaut). Le faire figurer inviterait à fabriquer un identifiant côté client,
 * ce dont personne n'a besoin.
 */
const snippet = computed(() => {
  const champs = props.columns
    .filter((column) => column.name !== 'id')
    .slice(0, 4)
    .map((column) => `    "${column.name}": ${exemplePour(column.type)}`)
    .join(',\n')

  return `curl -X POST ${apiBase.value}/backend/data/${props.name} \\
  -H "Authorization: Bearer sk_votre_cle_de_service" \\
  -H "Content-Type: application/json" \\
  -d '{
${champs || '    "colonne": "valeur"'}
  }'`
})

/** Une valeur plausible par type — un exemple faux ne s'essaie pas. */
function exemplePour(type) {
  return (
    {
      text: '"du texte"',
      varchar: '"du texte"',
      uuid: '"00000000-0000-0000-0000-000000000000"',
      integer: '42',
      bigint: '42',
      numeric: '19.99',
      boolean: 'true',
      date: '"2026-09-09"',
      timestamptz: '"2026-09-09T10:00:00Z"',
      jsonb: '{ "clé": "valeur" }',
    }[type] ?? '"valeur"'
  )
}
</script>

<template>
  <div class="space-y-3">
    <div>
      <p class="label-caps mb-2">adresses</p>
      <ul class="divide-y divide-line rounded-field border border-line">
        <li
          v-for="[verbe, url, quoi] in ROUTES"
          :key="verbe"
          class="flex flex-wrap items-baseline gap-2 px-3 py-1.5"
        >
          <span
            class="w-14 shrink-0 font-mono text-[0.68rem] font-semibold"
            :class="verbe === 'DELETE' ? 'text-brick' : 'text-ink-2'"
          >
            {{ verbe }}
          </span>
          <code class="min-w-0 flex-1 truncate font-mono text-[0.7rem] text-ink">{{ url }}</code>
          <span class="shrink-0 text-[0.68rem] text-ink-3">{{ quoi }}</span>
        </li>
      </ul>
    </div>

    <div>
      <p class="label-caps mb-2">une insertion, en entier</p>
      <pre
        class="overflow-x-auto rounded-field border border-line bg-panel p-3 font-mono text-[0.7rem] leading-relaxed text-ink-2"
        >{{ snippet }}</pre>
    </div>

    <p class="text-[0.74rem] text-ink-3">
      Authentifié par une clé de <strong class="text-ink-2">service</strong> — la même que pour
      l'ingestion d'erreurs. La colonne <code class="font-mono">id</code> se remplit toute seule.
    </p>
  </div>
</template>
