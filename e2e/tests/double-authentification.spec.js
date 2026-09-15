import { createHmac } from 'node:crypto'

import { expect, login, MOTDEPASSE, test } from './support.js'

/**
 * La double authentification, dans un vrai navigateur.
 *
 * Le parcours calcule lui-même les codes, comme le ferait une application
 * d'authentification (RFC 6238), à partir de la clé affichée sous le QR code.
 * Un compte NEUF, supprimé à la fin : activer la double authentification sur le
 * compte de démonstration fermerait la porte à toute la suite.
 */

const API = process.env.E2E_API_URL ?? 'http://localhost:8080/api'
const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'

function octetsDeBase32(texte) {
  let bits = ''

  for (const caractere of texte.toUpperCase().replace(/[^A-Z2-7]/g, '')) {
    bits += BASE32.indexOf(caractere).toString(2).padStart(5, '0')
  }

  const octets = []

  for (let i = 0; i + 8 <= bits.length; i += 8) octets.push(parseInt(bits.slice(i, i + 8), 2))

  return Buffer.from(octets)
}

/** Le code TOTP d'un pas de temps — ce qu'afficherait une application. */
function totp(cle, pas) {
  const compteur = Buffer.alloc(8)
  compteur.writeUInt32BE(Math.floor(pas / 2 ** 32), 0)
  compteur.writeUInt32BE(pas % 2 ** 32, 4)

  const empreinte = createHmac('sha1', octetsDeBase32(cle)).update(compteur).digest()
  const decalage = empreinte[19] & 0x0f
  const binaire = empreinte.readUInt32BE(decalage) & 0x7fffffff

  return String(binaire % 1_000_000).padStart(6, '0')
}

const pasCourant = () => Math.floor(Date.now() / 30_000)

test.describe('double authentification', () => {
  test('elle s’active depuis le profil, puis la connexion demande le code', async ({ page, request }) => {
    test.setTimeout(120_000)

    const marque = Date.now()
    const email = `deux-facteurs-${marque}@test.local`

    const inscription = await request.post(`${API}/auth/register`, {
      data: {
        full_name: `Double ${marque}`,
        email,
        password: MOTDEPASSE,
        password_confirmation: MOTDEPASSE,
        terms_accepted: true,
      },
    })
    expect(inscription.status()).toBe(201)

    const { access_token: jeton } = (await inscription.json()).data

    try {
      await login(page, { email, password: MOTDEPASSE })
      await page.goto('/profil')

      const section = page.getByRole('region', { name: 'Double authentification' })

      await section.getByRole('button', { name: /activer/i }).click()
      await section.locator('input[type="password"]').fill(MOTDEPASSE)
      await section.getByRole('button', { name: 'Continuer' }).click()

      // Le QR code est fabriqué dans le navigateur ; la clé, sous lui, sert
      // à qui n'a pas de caméra — et à ce parcours.
      await expect(section.getByRole('img', { name: /QR code/ })).toBeVisible()
      const cle = ((await section.locator('code').textContent()) ?? '').replace(/\s/g, '')

      const pasActivation = pasCourant()
      await section.getByRole('textbox', { name: 'Code de vérification', exact: true }).fill(totp(cle, pasActivation))
      await section.getByRole('button', { name: 'Activer', exact: true }).click()

      await expect(section.getByRole('list', { name: 'Codes de secours' }).getByRole('listitem')).toHaveCount(10)
      await section.getByRole('button', { name: /rangé mes codes/i }).click()
      await expect(section.getByText('activée', { exact: true })).toBeVisible()

      // Une autre fois, ailleurs : sans session, le mot de passe ne suffit plus.
      await page.context().clearCookies()
      await page.goto('/connexion')

      await page.getByLabel(/adresse e-mail/i).fill(email)
      await page.getByLabel(/mot de passe/i).first().fill(MOTDEPASSE)
      await page.getByRole('button', { name: /se connecter/i }).click()

      const code = page.getByRole('textbox', { name: 'Code de vérification', exact: true })
      await expect(code).toBeVisible()

      // Un code ne sert qu'une fois : celui de l'activation est refusé. On
      // attend le suivant, comme le ferait quelqu'un devant son téléphone.
      const attente = (pasActivation + 1) * 30_000 - Date.now()
      if (attente > 0) await page.waitForTimeout(attente + 500)

      await code.fill(totp(cle, pasCourant()))
      await page.getByRole('button', { name: 'Vérifier' }).click()

      await expect(page.getByRole('heading', { name: /bonjour/i })).toBeVisible({ timeout: 20_000 })
    } finally {
      await request.delete(`${API}/profile`, {
        data: { password: MOTDEPASSE },
        headers: { Authorization: `Bearer ${jeton}` },
      })
    }
  })
})
