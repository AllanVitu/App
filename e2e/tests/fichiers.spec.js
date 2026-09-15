import { deflateSync } from 'node:zlib'

import { expect, login, test } from './support.js'

/**
 * Des fichiers réels, dans un vrai navigateur.
 *
 * Ce que seul un navigateur prouve : le recadrage par le canevas, l'envoi
 * multipart — et surtout qu'une balise <img> charge RÉELLEMENT une adresse
 * signée servie par une autre origine (5173 → 8080). Une politique de
 * ressource inter-origines mal réglée l'aurait bloquée sans qu'aucun test
 * d'API ne le voie : l'API répond, l'image reste vide.
 *
 * D'où la mesure retenue partout ici : les dimensions DÉCODÉES de l'image.
 * Une balise posée mais vide rend zéro.
 */

/** Un PNG uni, écrit octet par octet plutôt que versé en fixture binaire. */
function png(largeur, hauteur, [r, g, b]) {
  const bloc = (type, donnees) => {
    const corps = Buffer.concat([Buffer.from(type, 'ascii'), donnees])
    const longueur = Buffer.alloc(4)
    const controle = Buffer.alloc(4)

    longueur.writeUInt32BE(donnees.length)
    controle.writeUInt32BE(crc32(corps))

    return Buffer.concat([longueur, corps, controle])
  }

  const entete = Buffer.alloc(13)
  entete.writeUInt32BE(largeur, 0)
  entete.writeUInt32BE(hauteur, 4)
  entete[8] = 8 // profondeur
  entete[9] = 2 // RVB

  const ligne = Buffer.from([0, ...Array.from({ length: largeur }, () => [r, g, b]).flat()])
  const pixels = deflateSync(Buffer.concat(Array.from({ length: hauteur }, () => ligne)))

  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    bloc('IHDR', entete),
    bloc('IDAT', pixels),
    bloc('IEND', Buffer.alloc(0)),
  ])
}

function crc32(octets) {
  let crc = ~0

  for (const octet of octets) {
    crc ^= octet

    for (let bit = 0; bit < 8; bit += 1) {
      crc = (crc >>> 1) ^ (0xedb88320 & -(crc & 1))
    }
  }

  return ~crc >>> 0
}

const dimensions = (image) => image.evaluate((img) => [img.naturalWidth, img.naturalHeight])

test.describe('fichiers', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
  })

  test('une photo de profil est recadrée, envoyée, puis montrée là où l’on apparaît', async ({
    page,
  }) => {
    await page.goto('/profil')

    await page.getByLabel('Photo de profil').setInputFiles({
      name: 'vacances.png',
      mimeType: 'image/png',
      buffer: png(300, 200, [232, 61, 92]),
    })

    // Dans la barre latérale, et décodée pour de bon : 200 × 200, le plus
    // grand carré qu'on puisse tirer d'une image de 300 × 200.
    const barre = page.locator('aside a[href="/profil"] img')
    await expect.poll(() => dimensions(barre)).toEqual([200, 200])

    await page.getByRole('button', { name: 'Retirer la photo' }).click()

    // Les initiales reviennent : l'image quitte le document.
    await expect(barre).toHaveCount(0)
  })

  test('une version porte son image, et la carte du fichier la reprend', async ({ page }) => {
    await page.goto('/modules/design')
    await expect(page.getByRole('heading', { name: 'design' })).toBeVisible()

    const nom = `Maquette ${Date.now()}`

    await page.getByRole('button', { name: /nouveau fichier/i }).click()
    await page.getByLabel('nom', { exact: true }).fill(nom)
    await page.getByRole('button', { name: /^créer$/i }).click()

    const carte = page
      .locator('[data-column="maquette"]')
      .getByRole('option')
      .filter({ hasText: nom })
    await carte.click()

    const panneau = page.getByRole('complementary', { name: /historique/i })
    await expect(panneau.getByText(nom, { exact: true })).toBeVisible()

    await panneau.getByPlaceholder('Intitulé').fill('Écran d’accueil')
    await panneau.getByLabel('Image de la version').setInputFiles({
      name: 'accueil.png',
      mimeType: 'image/png',
      buffer: png(64, 40, [40, 110, 200]),
    })
    await panneau.getByRole('button', { name: /enregistrer la version/i }).click()

    // La vignette de la v2 dans l'historique, puis l'aperçu sur la carte.
    const vignette = panneau.getByRole('link', { name: /image de la version 2/i }).locator('img')
    await expect.poll(() => dimensions(vignette)).toEqual([64, 40])
    await expect.poll(() => dimensions(carte.locator('img'))).toEqual([64, 40])

    // Ménage
    await panneau.getByRole('button', { name: /supprimer le fichier/i }).click()
    await expect(page.getByText(nom, { exact: true })).toBeHidden()
  })
})
