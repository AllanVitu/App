/**
 * Découpe d'un texte en caractères animables.
 *
 * anime.js fournit un module « text » qui fait cela — et bien davantage :
 * lignes, mots, masques, recomposition au redimensionnement. Il pèse 3,6 Ko
 * compressés. Ici, il s'agit de découper un logotype de sept caractères, sans
 * balisage imbriqué et sans reflux à gérer : ces quinze lignes suffisent, et
 * la moitié publique du front est précisément celle dont on surveille le
 * poids. Le jour où un écran aura besoin de lignes ou de mots, le module
 * d'anime.js sera le bon outil.
 *
 * ACCESSIBILITÉ — la raison d'être des deux attributs posés ici. Un texte
 * éclaté en une balise par lettre est lu lettre par lettre par certains
 * lecteurs d'écran : « s, a, a, s, espace, o, s ». On rend donc les
 * fragments invisibles à l'assistance et on remet le texte entier sur le
 * conteneur. La version GSAP de cet écran ne le faisait pas.
 *
 * @param {HTMLElement|null} element
 * @returns {HTMLElement[]} les fragments, dans l'ordre de lecture
 */
export function splitChars(element) {
  if (!element) return []

  const label = element.textContent?.trim() ?? ''

  if (label === '') return []

  element.setAttribute('aria-label', label)
  element.textContent = ''

  const fragments = []

  for (const character of label) {
    const span = document.createElement('span')

    // Espace INSÉCABLE : un espace ordinaire s'effondre dans un
    // inline-block, et les deux mots se recolleraient.
    span.textContent = character === ' ' ? ' ' : character

    // inline-block : sans lui, une transformation verticale n'a aucun effet
    // sur un élément en flux.
    span.style.display = 'inline-block'
    span.setAttribute('aria-hidden', 'true')

    element.appendChild(span)
    fragments.push(span)
  }

  return fragments
}
