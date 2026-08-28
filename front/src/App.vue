<script setup>
/**
 * Racine de l'application : le routeur choisit le layout (authentifié ou
 * public), les notifications sont montées une seule fois au-dessus.
 */
import ToastHost from '@/components/ToastHost.vue'
</script>

<template>
  <RouterView />
  <ToastHost />
</template>
