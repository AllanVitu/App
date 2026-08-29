<script setup>
/**
 * Racine de l'application : le routeur choisit le layout (authentifié ou
 * public), l'écran d'entrée et les notifications sont montés une seule fois
 * au-dessus.
 */
import ToastHost from '@/components/ToastHost.vue'
</script>

<template>
  <RouterView />
  <ToastHost />
</template>
