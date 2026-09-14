/**
 * Le texte d'une page de documentation, lu en blocs et en fragments — jamais
 * en HTML.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  AUCUN BALISAGE N'EST JAMAIS INTERPRÉTÉ                                 │
 * │                                                                         │
 * │  Le moyen habituel d'afficher du texte enrichi est de le convertir en   │
 * │  HTML, puis de le « nettoyer » avant de l'injecter par v-html. C'est    │
 * │  une course sans fin contre ce qu'un attaquant saura glisser entre deux │
 * │  mises à jour de la liste noire.                                        │
 * │                                                                         │
 * │  Ici, rien ne devient du HTML. Le texte est découpé en STRUCTURES —     │
 * │  titre, paragraphe, liste, citation, bloc de code — et chaque fragment  │
 * │  est rendu par un élément Vue dont le contenu est du texte, que Vue     │
 * │  échappe toujours. « <script> » écrit dans une page s'affiche           │
 * │  « <script> ». La seule chose qui franchit la frontière est l'adresse   │
 * │  d'un lien, et elle passe par safeHref.                                 │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * La syntaxe est un sous-ensemble volontairement petit de Markdown : ce qu'on
 * écrit dans une procédure, rien de ce qui sert à mettre en page un site.
 *
 *   # Titre, ## Sous-titre, ### Section
 *   - élément de liste     1. élément numéroté     > citation
 *   **gras**  *italique*  `code`  [libellé](https://…)  #142 (un ticket)
 *   ``` pour ouvrir et fermer un bloc de code
 */

const TITRE = /^(#{1,3}) (.+)$/
const PUCE = /^[-*] (.+)$/
const NUMERO = /^\d{1,3}[.)] (.+)$/
const CITATION = /^> ?(.*)$/
const CLOTURE = /^```/

/**
 * Fragments en ligne, par ordre de priorité. Le code d'abord : dans `**x**`,
 * les astérisques ne sont pas du gras. La référence de ticket exige un début
 * de ligne ou un blanc devant elle : « C#42 » n'est pas un ticket.
 */
const FRAGMENT =
  /(`[^`\n]+`)|(\*\*[^*\n]+\*\*)|(\*[^*\s][^*\n]*\*)|(\[[^\]\n]+\]\([^)\s]+\))|((?:^|(?<=[\s(]))#[0-9]{1,7}(?![0-9A-Za-z]))/g

/**
 * L'adresse d'un lien, si elle est sûre ; null sinon.
 *
 * http, https et mailto, plus les chemins internes de l'application. Tout le
 * reste est refusé — javascript:, data:, vbscript:, et ce qui n'existe pas
 * encore. Refuser par défaut est la seule liste qui ne vieillit pas.
 */
export function safeHref(url) {
  const adresse = String(url ?? '').trim()

  // Un chemin de l'application, mais pas « //hôte », qui partirait ailleurs.
  if (adresse.startsWith('/') && !adresse.startsWith('//')) return adresse

  try {
    const lue = new URL(adresse)

    return ['http:', 'https:', 'mailto:'].includes(lue.protocol) ? lue.href : null
  } catch {
    return null
  }
}

/**
 * @returns {Array<{type: 'text'|'strong'|'em'|'code', value: string} | {type: 'link', value: string, href: string} | {type: 'ticket', value: string, number: number}>}
 */
export function parseInlines(text) {
  const fragments = []
  let reste = 0

  // Deux morceaux de texte qui se suivent n'en font qu'un. Sans cela, une
  // adresse refusée qui contient elle-même une parenthèse —
  // « javascript:alert(1) » — sortirait en deux fragments : sans danger, mais
  // la même phrase aurait deux formes selon ce qu'elle contient.
  const texte = (value) => {
    const dernier = fragments.at(-1)

    if (dernier?.type === 'text') dernier.value += value
    else fragments.push({ type: 'text', value })
  }

  for (const trouve of text.matchAll(FRAGMENT)) {
    if (trouve.index > reste) texte(text.slice(reste, trouve.index))

    const [brut, code, gras, italique, lien, ticket] = trouve

    if (code) fragments.push({ type: 'code', value: code.slice(1, -1) })
    else if (gras) fragments.push({ type: 'strong', value: gras.slice(2, -2) })
    else if (italique) fragments.push({ type: 'em', value: italique.slice(1, -1) })
    else if (lien) {
      const coupure = lien.indexOf('](')
      const libelle = lien.slice(1, coupure)
      const href = safeHref(lien.slice(coupure + 2, -1))

      // Une adresse refusée reste visible, telle qu'elle a été écrite : qui
      // relit la page doit voir ce qui ne sera pas un lien.
      if (href) fragments.push({ type: 'link', value: libelle, href })
      else texte(brut)
    } else if (ticket) {
      fragments.push({ type: 'ticket', value: ticket, number: Number(ticket.slice(1)) })
    }

    reste = trouve.index + brut.length
  }

  if (reste < text.length) texte(text.slice(reste))

  return fragments
}

/**
 * @returns {Array<object>} blocs : heading, paragraph, list, quote, code
 */
export function parseBlocks(source) {
  const lignes = String(source ?? '')
    .replace(/\r\n?/g, '\n')
    .split('\n')
  const blocs = []

  let paragraphe = []
  let liste = null
  let citation = []
  let code = null

  const fermer = () => {
    if (paragraphe.length)
      blocs.push({ type: 'paragraph', inlines: parseInlines(paragraphe.join(' ')) })
    if (liste) blocs.push(liste)
    if (citation.length) blocs.push({ type: 'quote', inlines: parseInlines(citation.join(' ')) })

    paragraphe = []
    liste = null
    citation = []
  }

  for (const ligne of lignes) {
    if (code) {
      if (CLOTURE.test(ligne)) {
        blocs.push({ type: 'code', text: code.join('\n') })
        code = null
      } else {
        code.push(ligne)
      }

      continue
    }

    if (CLOTURE.test(ligne)) {
      fermer()
      code = []
      continue
    }

    const nette = ligne.trim()

    if (nette === '') {
      fermer()
      continue
    }

    const titre = nette.match(TITRE)

    if (titre) {
      fermer()
      blocs.push({ type: 'heading', level: titre[1].length, inlines: parseInlines(titre[2]) })
      continue
    }

    const puce = nette.match(PUCE)
    const numero = puce ? null : nette.match(NUMERO)

    if (puce || numero) {
      const ordered = Boolean(numero)

      if (paragraphe.length || citation.length || (liste && liste.ordered !== ordered)) fermer()

      liste ??= { type: 'list', ordered, items: [] }
      liste.items.push(parseInlines((puce ?? numero)[1]))
      continue
    }

    const cite = nette.match(CITATION)

    if (cite) {
      if (paragraphe.length || liste) fermer()

      citation.push(cite[1])
      continue
    }

    if (liste || citation.length) fermer()

    paragraphe.push(nette)
  }

  // Un bloc de code jamais refermé garde son contenu : perdre la fin d'une
  // procédure parce qu'il manque trois accents graves serait pire.
  if (code) blocs.push({ type: 'code', text: code.join('\n') })

  fermer()

  return blocs
}

/**
 * Un aperçu en texte brut, pour la liste des pages et la recherche : la
 * syntaxe retirée, les blancs réduits.
 */
export function plainExcerpt(source, length = 160) {
  const texte = parseBlocks(source)
    .filter((bloc) => bloc.type !== 'code')
    .flatMap((bloc) => (bloc.type === 'list' ? bloc.items.flat() : bloc.inlines))
    .map((fragment) => fragment.value)
    .join(' ')
    .replace(/\s+/g, ' ')
    .trim()

  return texte.length > length ? `${texte.slice(0, length - 1).trimEnd()}…` : texte
}
