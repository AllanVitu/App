/**
 * Ce que l'écran Documentation calcule à partir de la liste des pages.
 */

/**
 * L'arbre des pages, à partir de la liste plate que renvoie l'API.
 *
 * Une page dont le parent n'est pas dans la liste — supprimé, ou hors d'une
 * recherche — remonte à la racine plutôt que de disparaître : une page qu'on
 * ne peut plus atteindre est une page perdue.
 *
 * @param {Array<{id: string, parent_id: string|null, title: string, position: number}>} pages
 * @returns {Array<object>} nœuds { ...page, children, depth }
 */
export function buildTree(pages) {
  const noeuds = new Map(pages.map((page) => [page.id, { ...page, children: [] }]))
  const racines = []

  for (const noeud of noeuds.values()) {
    const parent = noeud.parent_id ? noeuds.get(noeud.parent_id) : null

    if (parent) parent.children.push(noeud)
    else racines.push(noeud)
  }

  const trier = (liste, depth) => {
    liste.sort((a, b) => a.position - b.position || a.title.localeCompare(b.title, 'fr'))

    for (const noeud of liste) {
      noeud.depth = depth
      trier(noeud.children, depth + 1)
    }

    return liste
  }

  return trier(racines, 0)
}

/**
 * L'arbre aplati, dans l'ordre de lecture, pour une liste accessible au
 * clavier et un sélecteur d'emplacement.
 *
 * @param {Array<object>} tree
 * @param {string|null} [exclure] une page dont on masque aussi le sous-arbre —
 *        on ne range pas une page sous elle-même
 */
export function flattenTree(tree, exclure = null) {
  const lignes = []

  const parcourir = (noeuds) => {
    for (const noeud of noeuds) {
      if (noeud.id === exclure) continue

      lignes.push(noeud)
      parcourir(noeud.children)
    }
  }

  parcourir(tree)

  return lignes
}
