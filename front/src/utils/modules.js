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

/**
 * La couleur de ligne d'un module.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  ELLE DIT OÙ ON EST, JAMAIS COMMENT ÇA VA                           │
 * │                                                                     │
 * │  L'application a déjà trois couleurs pour l'état — ok, attente,     │
 * │  danger. En ajouter cinq pour l'identité, sans règle, reviendrait à │
 * │  parler deux langues avec le même vocabulaire.                      │
 * │                                                                     │
 * │  La règle est une règle de FORME, et elle est tenue ici : ces       │
 * │  classes ne produisent que des FONDS. Aucune ne colore du texte.    │
 * │  L'état, lui, ne colore que du texte et des points, jamais un       │
 * │  aplat. Deux formes qui ne se croisent pas ne se confondent pas.    │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Les classes sont écrites EN TOUTES LETTRES et non composées à la volée :
 * Tailwind lit les sources pour savoir quoi générer, et ne trouverait jamais
 * une classe assemblée par concaténation.
 */
const LIGNES = {
  backend: 'bg-mod-backend',
  deploiement: 'bg-mod-deploiement',
  tickets: 'bg-mod-tickets',
  supervision: 'bg-mod-supervision',
  design: 'bg-mod-design',
  disponibilite: 'bg-mod-disponibilite',
  documentation: 'bg-mod-documentation',
}

/**
 * Repli sur l'encre pour un module ajouté en base sans teinte attribuée :
 * il reste repérable, sans emprunter la couleur d'un autre — deux modules de
 * la même couleur seraient pires que pas de couleur du tout.
 *
 * @param {string} slug
 * @returns {string} une classe de FOND, à poser sur un bloc
 */
export const moduleLine = (slug) => LIGNES[slug] ?? 'bg-ink-3'
