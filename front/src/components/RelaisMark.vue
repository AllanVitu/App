<script setup>
/**
 * La marque Relais : trois barres croissantes sur une plaque.
 *
 * Trois barres de signal, parce que l'application dit où en est la
 * production, et une plaque d'encre, parce que la couleur est réservée aux
 * lignes de module — une marque colorée emprunterait la teinte de l'une
 * d'elles. Elle suit le thème : encre sur fond clair, papier sur fond sombre.
 *
 * En SVG et non en boîtes : une marge intérieure en pourcentage se calcule
 * sur la largeur du PARENT, pas de la plaque. Posée dans un lien de 248 px,
 * la première version gonflait jusqu'à recouvrir le sélecteur d'espace. Le
 * dessin tient désormais dans son propre repère, à n'importe quelle taille.
 *
 * Toujours décorative : le nom « Relais » est écrit à côté, ou porté par le
 * lien qui l'entoure.
 */
</script>

<template>
  <svg viewBox="0 0 32 32" aria-hidden="true" focusable="false">
    <rect width="32" height="32" rx="3" class="fill-ink" />
    <rect x="7" y="18.7" width="4" height="6.3" rx="1" class="fill-paper" />
    <rect x="14" y="13.3" width="4" height="11.7" rx="1" class="fill-paper" />
    <rect x="21" y="7" width="4" height="18" rx="1" class="fill-paper" />
  </svg>
</template>
