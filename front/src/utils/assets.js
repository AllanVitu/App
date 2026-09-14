/**
 * Adresse complète d'un fichier servi par l'API.
 *
 * L'API renvoie des CHEMINS — « /api/files/… » — et non des adresses : elle ne
 * sait pas depuis quelle origine on l'appelle. En développement, le client
 * (5173) et l'API (8080) sont deux origines, et un chemin nu partirait vers le
 * serveur Vite ; en déploiement, c'est la même, et le chemin suffit.
 *
 * SEULS LES CHEMINS DE FICHIERS SONT ACCEPTÉS. Une adresse absolue qui
 * arriverait dans une réponse — un autre domaine, un « javascript: » — donne
 * null plutôt qu'une image : les navigateurs de l'équipe ne contactent que
 * l'application, et c'est précisément ce que l'avatar par URL libre violait.
 */
const API = new URL(
  import.meta.env.VITE_API_BASE_URL || 'http://localhost:8080/api',
  window.location.origin,
)

/**
 * @param {string|null|undefined} chemin tel que l'API le renvoie
 * @returns {string|null}
 */
export function assetUrl(chemin) {
  if (typeof chemin !== 'string' || !chemin.startsWith('/api/files/')) return null

  return new URL(chemin, API).href
}
