/**
 * Chemin d'un module.
 *
 * Les liens vers un module passent par le CHEMIN, jamais par le nom de route.
 *
 * Certains modules ont leur propre écran — « tickets » aujourd'hui, les
 * autres à leur tour. Une navigation par nom (`{ name: 'module', params: …}`)
 * désignerait toujours la vue générique, y compris pour ces modules-là : le
 * lien ouvrirait la vue générique tandis qu'un rechargement de la MÊME URL
 * ouvrirait la vue dédiée, puisque le rechargement, lui, passe par la
 * correspondance de chemin.
 *
 * En liant par chemin, la table de routage tranche seule, et de la même
 * façon dans les deux cas.
 */
export const modulePath = (slug) => `/modules/${slug}`
