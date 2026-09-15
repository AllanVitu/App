// Contrôle d'un client compilé pour le poste.
//
// Deux erreurs ne se voient qu'une fois l'application ouverte, et l'une s'est
// produite : une variable de compilation déformée en route. Git Bash réécrit
// tout argument qui ressemble à un chemin POSIX — « /api » est arrivé dans Vite
// sous la forme « C:/Program Files/Git/api », et chaque appel à l'API partait
// vers une adresse que la fenêtre bloque, à raison.
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'

/** La liste des problèmes du client compilé ; vide s'il est bon pour le poste. */
export function problemesDuClient(dist) {
  const problemes = []

  if (!existsSync(join(dist, 'index.html'))) {
    return ['front/dist/index.html absent : le client n’est pas compilé.']
  }

  const tampon = join(dist, '.edition')

  if (!existsSync(tampon) || readFileSync(tampon, 'utf8').trim() !== 'bureau') {
    problemes.push('front/dist n’est pas marqué comme compilé pour l’édition de bureau.')
  }

  const scripts = readdirSync(join(dist, 'assets')).filter((nom) => nom.endsWith('.js'))
  const adresses = new Set()

  for (const nom of scripts) {
    for (const [, adresse] of readFileSync(join(dist, 'assets', nom), 'utf8').matchAll(/baseURL:"([^"]*)"/g)) {
      adresses.add(adresse)
    }
  }

  if (adresses.size === 0) {
    problemes.push('adresse de l’API introuvable dans le client compilé.')
  }

  for (const adresse of adresses) {
    if (adresse !== '/api') {
      problemes.push(`le client appelle l’API sur « ${adresse} » au lieu de « /api » (même origine).`)
    }
  }

  return problemes
}
