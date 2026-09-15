<script setup>
/**
 * Conditions générales d'utilisation.
 *
 * La version affichée doit correspondre à celle qu'enregistre l'API
 * (App\Config\Terms::CURRENT_VERSION, cf. utils/legal) — c'est elle qui donne
 * sa valeur à la trace de consentement.
 */
import LegalPage from '@/components/legal/LegalPage.vue'
import { TERMS_EFFECTIVE, TERMS_VERSION } from '@/utils/legal'

const sections = [
  {
    title: 'Objet',
    body: `Ces conditions régissent l'utilisation de Relais. Créer un compte vaut
      acceptation ; sans acceptation, aucun compte n'est créé.`,
  },
  {
    title: 'Compte et sécurité',
    body: `Vous êtes responsable de la confidentialité de votre mot de passe.
      Toute connexion établie avec vos identifiants est réputée être la vôtre.
      Signalez sans délai tout accès que vous n'auriez pas autorisé : un
      changement de mot de passe ferme immédiatement toutes les sessions.`,
  },
  {
    title: 'Vos contenus',
    body: `Ce que vous créez dans un espace appartient à cet espace et à son
      équipe : ses membres le voient et le modifient selon leur rôle. Relais ne
      s'en sert que pour vous rendre le service.`,
  },
  {
    title: 'Données personnelles',
    body: `Ce qui est conservé, pourquoi et combien de temps est détaillé dans la
      politique de confidentialité. Aucune donnée n'est revendue ni transmise à
      des fins publicitaires, et aucun traceur n'est déposé : le seul cookie est
      celui de votre session.`,
  },
  {
    title: 'Suppression',
    body: `Vous pouvez supprimer votre compte à tout moment depuis la page Profil.
      La suppression est immédiate : vos sessions, préférences, photo et
      adhésions disparaissent, et un espace dont vous étiez le seul membre est
      supprimé avec tout son contenu. Dans un espace partagé, ce que vous y avez
      écrit reste à l'équipe, sans votre nom.`,
  },
  {
    title: 'Modification des présentes conditions',
    body: `Toute nouvelle version porte un numéro distinct. Votre acceptation est
      enregistrée avec ce numéro : une modification vous est signalée à votre
      prochaine visite, et appelle un nouveau consentement.`,
  },
]
</script>

<template>
  <LegalPage
    :surtitre="`version ${TERMS_VERSION} · en vigueur le ${TERMS_EFFECTIVE}`"
    titre="Conditions générales d'utilisation"
  >
    <section v-for="section in sections" :key="section.title">
      <h2 class="text-[0.95rem] font-semibold text-ink">{{ section.title }}</h2>
      <p class="mt-1.5">{{ section.body }}</p>
    </section>
  </LegalPage>
</template>
