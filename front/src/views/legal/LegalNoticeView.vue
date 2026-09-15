<script setup>
/**
 * Mentions légales — article 6-III de la loi pour la confiance dans
 * l'économie numérique (LCEN).
 *
 * L'identité de l'éditeur et celle de l'hébergeur se renseignent dans
 * utils/legal.js. Un champ vide s'affiche « à compléter » : une page de
 * mentions légales remplie d'une identité inventée serait une fausse
 * déclaration, pas une page terminée.
 */
import LegalPage from '@/components/legal/LegalPage.vue'
import { EDITEUR, EDITION_BUREAU, HEBERGEUR } from '@/utils/legal'

const editeur = [
  { terme: 'Nom ou raison sociale', valeur: EDITEUR.nom },
  { terme: 'Statut', valeur: EDITEUR.statut },
  { terme: 'Adresse', valeur: EDITEUR.adresse },
  { terme: 'E-mail', valeur: EDITEUR.email },
  { terme: 'Téléphone', valeur: EDITEUR.telephone },
  { terme: 'Immatriculation', valeur: EDITEUR.immatriculation },
  { terme: 'Directeur de la publication', valeur: EDITEUR.directeurPublication },
]

const hebergeur = [
  { terme: 'Nom', valeur: HEBERGEUR.nom },
  { terme: 'Adresse', valeur: HEBERGEUR.adresse },
  { terme: 'Téléphone', valeur: HEBERGEUR.telephone },
]

// Sur le poste, personne n'héberge rien : le bloc laisse place à une phrase
// qui le dit, plutôt qu'à trois champs « à compléter » sans objet.
const blocs = [
  { id: 'editeur', titre: 'Éditeur', lignes: editeur },
  ...(EDITION_BUREAU ? [] : [{ id: 'hebergeur', titre: 'Hébergeur', lignes: hebergeur }]),
]
</script>

<template>
  <LegalPage titre="Mentions légales">
    <section v-for="bloc in blocs" :key="bloc.id" :aria-labelledby="bloc.id">
      <h2 :id="bloc.id" class="text-[0.95rem] font-semibold text-ink">{{ bloc.titre }}</h2>
      <dl class="mt-2 grid grid-cols-1 gap-x-6 gap-y-1.5 sm:grid-cols-[13rem_1fr]">
        <div v-for="ligne in bloc.lignes" :key="ligne.terme" class="contents">
          <dt class="text-ink-3">{{ ligne.terme }}</dt>
          <dd class="text-ink">
            <template v-if="ligne.valeur">{{ ligne.valeur }}</template>
            <span v-else class="italic text-ochre">à compléter</span>
          </dd>
        </div>
      </dl>
    </section>

    <section v-if="EDITION_BUREAU" aria-labelledby="hebergement">
      <h2 id="hebergement" class="text-[0.95rem] font-semibold text-ink">Hébergement</h2>
      <p class="mt-1.5">
        Aucun. Cette édition de Relais fonctionne entièrement sur votre ordinateur : l’application,
        sa base de données et vos fichiers y restent, et rien n’est transmis à l’éditeur.
      </p>
    </section>

    <section aria-labelledby="propriete">
      <h2 id="propriete" class="text-[0.95rem] font-semibold text-ink">Propriété intellectuelle</h2>
      <p class="mt-1.5">
        L’application, son nom, son identité visuelle et ses textes sont protégés. Les contenus que
        vous créez dans votre espace restent les vôtres : Relais ne s’en sert que pour vous rendre
        le service.
      </p>
    </section>

    <section aria-labelledby="donnees">
      <h2 id="donnees" class="text-[0.95rem] font-semibold text-ink">Données personnelles</h2>
      <p class="mt-1.5">
        Ce qui est collecté, pourquoi, combien de temps et comment exercer vos droits est détaillé
        dans la politique de confidentialité, accessible ci-dessous.
      </p>
    </section>
  </LegalPage>
</template>
