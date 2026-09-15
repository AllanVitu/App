// La boîte d'envoi. Tout ce qui vient d'un message s'écrit en textContent :
// un sujet ou un corps contenant du balisage s'affiche tel quel, sans jamais
// être interprété.
const liste = document.getElementById('liste')
const vide = document.getElementById('vide')
const lecture = document.getElementById('lecture')

const format = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' })
const LIEN = /https?:\/\/[^\s<>"']+/g

let courant = null

function element(balise, classe, texte) {
  const noeud = document.createElement(balise)

  if (classe) {
    noeud.className = classe
  }

  if (texte !== undefined) {
    noeud.textContent = texte
  }

  return noeud
}

/** Le corps du message : du texte, où seules les adresses reconnues deviennent des liens. */
function corps(message) {
  const bloc = element('div', 'corps')
  const connus = new Set(message.liens)
  let position = 0

  for (const trouve of message.texte.matchAll(LIEN)) {
    const url = trouve[0].replace(/[.,;:!?)\]]+$/, '')

    if (!connus.has(url)) {
      continue
    }

    bloc.append(document.createTextNode(message.texte.slice(position, trouve.index)))

    const lien = element('button', 'lien', url)
    lien.type = 'button'
    lien.title = url.startsWith(location.protocol) ? 'Ouvrir dans le navigateur' : 'Ouvrir dans Relais'
    lien.addEventListener('click', () => window.relais.ouvrir(url))
    bloc.append(lien)

    position = trouve.index + url.length
  }

  bloc.append(document.createTextNode(message.texte.slice(position)))

  return bloc
}

async function afficher(id) {
  const message = await window.relais.lire(id)

  courant = id

  for (const option of liste.children) {
    option.setAttribute('aria-selected', String(option.dataset.id === id))
  }

  lecture.replaceChildren()

  if (!message) {
    lecture.append(element('p', 'vide', 'Ce message ne peut pas être lu.'))

    return
  }

  const entete = element('header')
  entete.append(element('h2', null, message.sujet || '(sans sujet)'))

  const details = element('dl')

  for (const [terme, valeur] of [
    ['De', message.de],
    ['À', message.a],
    ['Le', format.format(new Date(message.date))],
  ]) {
    details.append(element('dt', null, terme), element('dd', null, valeur))
  }

  entete.append(details)
  lecture.append(entete, corps(message))
}

async function rafraichir(selection) {
  const messages = await window.relais.lister()

  liste.replaceChildren(
    ...messages.map((message) => {
      const option = element('li')
      option.dataset.id = message.id
      option.setAttribute('role', 'option')
      option.tabIndex = 0

      option.append(
        element('span', 'sujet', message.sujet || '(sans sujet)'),
        element('span', 'meta', `${message.a} · ${format.format(new Date(message.date))}`),
      )

      option.addEventListener('click', () => afficher(message.id))
      option.addEventListener('keydown', (evenement) => {
        if (evenement.key === 'Enter' || evenement.key === ' ') {
          evenement.preventDefault()
          afficher(message.id)
        } else if (evenement.key === 'ArrowDown' || evenement.key === 'ArrowUp') {
          evenement.preventDefault()
          const voisin = evenement.key === 'ArrowDown' ? option.nextElementSibling : option.previousElementSibling
          voisin?.focus()
          voisin?.click()
        }
      })

      return option
    }),
  )

  vide.hidden = messages.length > 0

  const cible = messages.find((m) => m.id === selection) ?? messages.find((m) => m.id === courant) ?? messages[0]

  if (cible) {
    await afficher(cible.id)
  }
}

window.relais.surNouveau((id) => rafraichir(id))
window.relais.surSelection((id) => rafraichir(id))

rafraichir(new URLSearchParams(location.search).get('message'))
