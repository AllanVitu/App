import net from 'node:net'

/** Vrai si le port peut être ouvert sur l'adresse de bouclage. */
export function portDisponible(port) {
  return new Promise((resoudre) => {
    const serveur = net.createServer()

    serveur.once('error', () => resoudre(false))
    serveur.listen({ port, host: '127.0.0.1', exclusive: true }, () => serveur.close(() => resoudre(true)))
  })
}

/**
 * Le port préféré s'il est libre, sinon un port attribué par le système.
 *
 * Préférer le même port d'un lancement à l'autre n'est pas un détail : le
 * stockage local du navigateur est rattaché à l'origine, port compris. Un port
 * différent à chaque lancement, et la session serait oubliée à chaque fois.
 */
export async function portLibre(prefere) {
  if (Number.isInteger(prefere) && prefere > 1024 && prefere < 65536 && (await portDisponible(prefere))) {
    return prefere
  }

  return new Promise((resoudre, rejeter) => {
    const serveur = net.createServer()

    serveur.once('error', rejeter)
    serveur.listen({ port: 0, host: '127.0.0.1', exclusive: true }, () => {
      const { port } = serveur.address()
      serveur.close(() => resoudre(port))
    })
  })
}

/** Attend qu'un service accepte les connexions, ou échoue au bout du délai. */
export async function attendrePort(port, { delai = 10_000, intervalle = 100 } = {}) {
  const limite = Date.now() + delai

  while (Date.now() < limite) {
    const ouvert = await new Promise((resoudre) => {
      const socket = net.connect({ port, host: '127.0.0.1' })

      socket.once('connect', () => {
        socket.destroy()
        resoudre(true)
      })
      socket.once('error', () => resoudre(false))
    })

    if (ouvert) {
      return
    }

    await new Promise((r) => setTimeout(r, intervalle))
  }

  throw new Error(`Aucun service n'a ouvert le port ${port} dans le délai imparti.`)
}
