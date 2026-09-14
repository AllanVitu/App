/**
 * Prépare une photo de profil : recadrée au carré, au centre, puis réencodée.
 *
 * Deux raisons de le faire AVANT l'envoi :
 *
 *   — une photo de téléphone pèse trois à huit mégaoctets, et l'avatar
 *     s'affiche en soixante-quatre pixels : envoyer l'original ferait attendre
 *     pour rien ;
 *   — le réencodage par le canevas ne garde AUCUNE métadonnée, position GPS
 *     comprise. Le serveur les retire aussi, et c'est lui qui fait foi : le
 *     client se contente de ne pas les envoyer.
 *
 * createImageBitmap applique l'orientation EXIF avant qu'elle ne disparaisse :
 * une photo prise téléphone tourné arrive droite.
 *
 * @param {Blob} fichier
 * @param {number} [cote] côté du carré, en pixels
 * @returns {Promise<Blob>}
 */
export async function cropToSquare(fichier, cote = 512) {
  let image

  try {
    image = await createImageBitmap(fichier)
  } catch {
    throw new Error('Cette image ne se lit pas. Choisissez un fichier PNG, JPEG ou WebP.')
  }

  // Jamais d'agrandissement : une petite photo étirée serait floue ET plus lourde.
  const source = Math.min(image.width, image.height)
  const cible = Math.min(cote, source)

  const canevas = document.createElement('canvas')
  canevas.width = cible
  canevas.height = cible

  const contexte = canevas.getContext('2d')
  contexte.imageSmoothingQuality = 'high'
  contexte.drawImage(
    image,
    (image.width - source) / 2,
    (image.height - source) / 2,
    source,
    source,
    0,
    0,
    cible,
    cible,
  )
  image.close?.()

  // WebP d'abord, le plus léger à qualité égale. Un navigateur qui ne sait pas
  // l'encoder rend du PNG à la place, sans erreur : d'où la vérification du
  // type réellement obtenu.
  const webp = await versBlob(canevas, 'image/webp', 0.9)

  if (webp?.type === 'image/webp') return webp

  const png = await versBlob(canevas, 'image/png')

  if (!png) throw new Error("Cette image n'a pas pu être préparée.")

  return png
}

function versBlob(canevas, type, qualite) {
  return new Promise((resolve) => canevas.toBlob(resolve, type, qualite))
}
