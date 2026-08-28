<script setup>
/** Page 404 — affichée hors layout, elle est accessible connecté ou non. */
import { useAuthStore } from '@/stores/auth'
import BaseButton from '@/components/ui/BaseButton.vue'

const auth = useAuthStore()
</script>

<template>
  <div class="flex min-h-screen flex-col items-center justify-center px-6 text-center">
    <p class="text-6xl font-bold text-brand-600">404</p>
    <h1 class="mt-4 text-2xl font-bold tracking-tight">Page introuvable</h1>
    <p class="mt-2 max-w-sm text-sm text-slate-500">
      La page demandée n'existe pas ou a été déplacée.
    </p>

    <BaseButton class="mt-8" :to="auth.isAuthenticated ? { name: 'dashboard' } : { name: 'login' }">
      {{ auth.isAuthenticated ? 'Retour au tableau de bord' : 'Aller à la connexion' }}
    </BaseButton>
  </div>
</template>
