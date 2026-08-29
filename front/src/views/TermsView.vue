<script setup>
/**
 * Conditions générales d'utilisation.
 *
 * Accessible sans être connecté : on ne peut pas demander d'accepter un
 * texte qu'il faudrait un compte pour lire.
 *
 * La version affichée doit correspondre à celle qu'enregistre l'API
 * (App\Config\Terms::CURRENT_VERSION) — c'est elle qui donne sa valeur à la
 * trace de consentement.
 */
import BaseButton from '@/components/ui/BaseButton.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const VERSION = '1.0'
const EFFECTIVE = '29 août 2026'

const sections = [
  {
    title: 'Objet',
    body: `Ces conditions régissent l'utilisation de l'application. Créer un
      compte vaut acceptation ; sans acceptation, aucun compte n'est créé.`,
  },
  {
    title: 'Compte et sécurité',
    body: `Vous êtes responsable de la confidentialité de votre mot de passe.
      Toute connexion établie avec vos identifiants est réputée être la vôtre.
      Signalez sans délai tout accès que vous n'auriez pas autorisé : un
      changement de mot de passe ferme immédiatement toutes les sessions.`,
  },
  {
    title: 'Données que nous conservons',
    body: `Votre adresse e-mail, votre nom, vos préférences d'affichage et les
      contenus que vous créez dans les modules. Les tentatives de connexion
      sont journalisées pendant 30 jours, à seule fin de détecter les attaques
      par force brute.`,
  },
  {
    title: 'Ce que nous ne faisons pas',
    body: `Aucune donnée n'est revendue, ni transmise à des tiers à des fins
      publicitaires. Aucun traceur publicitaire n'est déposé. Les seuls
      cookies utilisés sont ceux de votre session.`,
  },
  {
    title: 'Suppression',
    body: `Vous pouvez supprimer votre compte à tout moment depuis la page
      Profil. La suppression est immédiate et définitive : elle emporte vos
      contenus, vos préférences et vos sessions.`,
  },
  {
    title: 'Modification des présentes conditions',
    body: `Toute nouvelle version porte un numéro distinct. Votre acceptation
      étant enregistrée avec le numéro de version, une modification appelle un
      nouveau consentement — l'ancien ne vaut que pour l'ancien texte.`,
  },
]
</script>

<template>
  <div class="mx-auto w-full max-w-2xl px-5 py-12">
    <p class="label-caps">version {{ VERSION }} · en vigueur le {{ EFFECTIVE }}</p>
    <h1 class="mt-2 text-2xl font-semibold">Conditions générales d'utilisation</h1>

    <div class="mt-8 space-y-7">
      <section v-for="section in sections" :key="section.title">
        <h2 class="text-[0.95rem] font-semibold">{{ section.title }}</h2>
        <p class="mt-1.5 text-[0.88rem] leading-relaxed text-ink-2">{{ section.body }}</p>
      </section>
    </div>

    <div class="mt-10 flex flex-wrap gap-3 border-t border-line pt-6">
      <BaseButton :to="auth.isAuthenticated ? { name: 'dashboard' } : { name: 'register' }">
        {{ auth.isAuthenticated ? 'Retour au tableau de bord' : "Retour à l'inscription" }}
      </BaseButton>
      <BaseButton v-if="!auth.isAuthenticated" :to="{ name: 'login' }" variant="secondary">
        Se connecter
      </BaseButton>
    </div>
  </div>
</template>
