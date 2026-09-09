<script setup>
/**
 * Pastille de filtre : allumée ou éteinte.
 *
 * Douze boutons répétaient ces cinq lignes à l'identique — mêmes classes,
 * même ternaire, même `aria-pressed`. Seules changeaient la condition et
 * l'action. Le jour où le contraste de l'état éteint est corrigé, il faut
 * n'oublier aucun des douze ; il suffit d'un oubli pour qu'un module ait
 * l'air différent sans que personne ne sache pourquoi.
 *
 * `aria-pressed` VIENT AVEC. C'est ce qui distingue une pastille de filtre
 * d'un bouton ordinaire : un lecteur d'écran annonce « activé » ou
 * « désactivé », alors que rien d'autre que la couleur ne le dit. Le lier à
 * `active` par construction rend l'oubli impossible.
 *
 * L'écouteur de clic n'est pas déclaré : Vue le reporte sur la racine, qui
 * est le bouton lui-même.
 */
defineProps({
  /** Vrai quand le filtre est appliqué. */
  active: { type: Boolean, default: false },
})
</script>

<template>
  <button
    type="button"
    class="chip transition-colors"
    :class="
      active
        ? 'border-ink bg-raised text-ink'
        : 'border-line text-ink-3 hover:border-ink-3 hover:text-ink'
    "
    :aria-pressed="active"
  >
    <slot />
  </button>
</template>
