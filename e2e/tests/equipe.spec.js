import { expect, login, test } from './support.js'

/**
 * L'espace de travail, de l'invitation au partage effectif.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  LE SEUL ENDROIT OÙ CE PARCOURS EXISTE EN ENTIER                        │
 * │                                                                         │
 * │  Les tests d'API vérifient chaque maillon : l'invitation s'émet, le     │
 * │  jeton se consomme une fois, l'invité voit les cinq modules. Ils        │
 * │  fabriquent le jeton en lisant la file de tâches.                       │
 * │                                                                         │
 * │  Ici, le jeton fait le vrai voyage : e-mail, boîte de réception, lien    │
 * │  ouvert dans un navigateur qui n'a jamais vu l'application. C'est le    │
 * │  seul niveau où l'on constate qu'un lien envoyé ARRIVE, et qu'il mène   │
 * │  quelque part.                                                          │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * DEUX PRÉCAUTIONS D'ÉTAT PARTAGÉ, apprises en salant cette suite :
 *
 *  1. L'adresse invitée est UNIQUE à chaque exécution. Deux passages ne se
 *     disputent donc ni une invitation en cours, ni un compte existant.
 *
 *  2. Rien n'est affirmé sur un NOMBRE de membres. Le compte de démonstration
 *     est partagé par toute la suite, et une exécution interrompue laisse des
 *     traces : on vérifie une présence nommée, jamais un total.
 */

const MAILPIT = process.env.MAILPIT_URL ?? 'http://localhost:8025'

/** Mot de passe des comptes fabriqués ici — mêmes exigences que l'écran. */
const MOTDEPASSE = 'Password123!'

/**
 * Récupère le lien d'invitation depuis la boîte de réception de développement.
 *
 * L'envoi passe par la file de tâches : le worker le remet au serveur SMTP
 * dans la seconde. On interroge donc en boucle courte plutôt que d'attendre
 * une durée fixe, qui serait soit trop longue, soit trop juste selon la
 * charge de la machine.
 */
async function lienDInvitation(request, adresse) {
  for (let essai = 0; essai < 40; essai += 1) {
    const boite = await request.get(`${MAILPIT}/api/v1/search?query=to:${adresse}`)

    if (boite.ok()) {
      const { messages = [] } = await boite.json()

      if (messages.length > 0) {
        const message = await request.get(`${MAILPIT}/api/v1/message/${messages[0].ID}`)
        const corps = await message.json()

        const trouve = /https?:\/\/[^\s"'<>]*\/invitation\?token=[a-f0-9]{64}/.exec(
          `${corps.Text ?? ''} ${corps.HTML ?? ''}`,
        )

        if (trouve) return trouve[0]
      }
    }

    await new Promise((resolve) => setTimeout(resolve, 250))
  }

  throw new Error(`Aucun e-mail d'invitation reçu pour ${adresse}.`)
}

test.describe("espace de travail", () => {
  test("une invitation traverse l'e-mail et ouvre l'espace à qui la reçoit", async ({
    page,
    browser,
    request,
  }) => {
    const marque = Date.now()
    const invite = `equipier-${marque}@test.local`
    const ticket = `Vu par l'équipe ${marque}`

    await login(page)

    // L'hôte écrit quelque chose que l'invité devra voir. Le titre porte la
    // marque de l'exécution : les traces d'un passage précédent ne peuvent pas
    // le faire passer pour réussi.
    await page.goto('/modules/tickets')
    await expect(page.getByRole('heading', { name: 'tickets' })).toBeVisible()

    await page.keyboard.press('c')
    await page.getByPlaceholder(/Entrée pour créer/i).fill(ticket)
    await page.keyboard.press('Enter')

    await expect(page.getByRole('option').filter({ hasText: ticket })).toBeVisible()

    // ── L'invitation part
    await page.goto('/equipe')
    await expect(page.getByRole('heading', { name: /membres/i })).toBeVisible()

    await page.getByLabel(/adresse e-mail/i).fill(invite)
    await page.getByRole('button', { name: /^inviter$/i }).click()

    // Elle apparaît « en attente » : l'écran rend compte de ce qui a été fait,
    // et c'est ce qui permet de la révoquer.
    await expect(page.getByText(invite)).toBeVisible()

    const lien = await lienDInvitation(request, invite)

    // ── Le lien s'ouvre dans un navigateur qui ne connaît pas l'application
    //
    // Contexte créé à la main : il n'hérite PAS de l'outillage du fixture
    // « page ». L'écran d'entrée sonore s'afficherait par-dessus tout et
    // avalerait les clics — d'où le même réglage, posé ici explicitement.
    const contexte = await browser.newContext()

    await contexte.addInitScript(() => {
      try {
        window.localStorage.setItem('sound', 'off')
      } catch {
        /* stockage indisponible : l'écran s'affichera, le test le dira */
      }
    })

    const arrivant = await contexte.newPage()

    await arrivant.goto(lien)

    // Il annonce l'espace AVANT de demander quoi que ce soit : on ne crée pas
    // un compte sans savoir pour quoi.
    await expect(arrivant.getByRole('heading', { name: /utilisateur démo/i })).toBeVisible()

    await arrivant.getByRole('link', { name: /créer un compte/i }).click()

    // L'adresse est préremplie depuis le lien : l'invitation vise celle-là.
    await expect(arrivant.getByLabel(/adresse e-mail/i)).toHaveValue(invite)

    await arrivant.getByLabel(/nom complet/i).fill(`Équipier ${marque}`)

    // « Confirmation du mot de passe » contient « mot de passe » : les deux
    // champs répondent au même motif. C'est l'ORDRE qui les sépare, et il est
    // celui de la lecture.
    await arrivant.getByLabel(/mot de passe/i).first().fill(MOTDEPASSE)
    await arrivant.getByLabel(/confirmation/i).fill(MOTDEPASSE)

    await arrivant.getByRole('checkbox').check()
    await arrivant.getByRole('button', { name: /créer mon compte/i }).click()

    await arrivant.waitForURL('/', { timeout: 20_000 })

    // ── CE QUI CHANGE TOUT : il voit le ticket de quelqu'un d'autre.
    //
    // Avant ce jalon, un compte neuf arrivait sur une application vide. C'est
    // la seule assertion de ce fichier qui n'aurait pas pu passer hier.
    await arrivant.goto('/modules/tickets')
    await expect(arrivant.getByRole('option').filter({ hasText: ticket })).toBeVisible()

    // Et il sait chez qui il est.
    await expect(arrivant.getByRole('button', { name: /utilisateur démo/i })).toBeVisible()

    // ── L'hôte le voit arriver
    //
    // Ciblé sur la LIGNE de la liste : le nom apparaît deux fois dans le
    // document, la seconde dans le texte de rechange de l'avatar, réservé aux
    // lecteurs d'écran.
    await page.reload()
    await expect(page.locator('li', { hasText: `Équipier ${marque}` })).toBeVisible()

    // ── Ménage : le compte se supprime lui-même, comme le ferait n'importe qui
    // depuis son profil. Sans cela, chaque exécution laisserait un membre de
    // plus dans l'espace de démonstration.
    await arrivant.goto('/profil')
    await arrivant.getByRole('button', { name: /supprimer mon compte/i }).click()
    await arrivant.getByPlaceholder(/votre mot de passe/i).fill(MOTDEPASSE)
    await arrivant.getByRole('button', { name: /supprimer définitivement/i }).click()
    await arrivant.waitForURL(/connexion/, { timeout: 20_000 })

    await contexte.close()
  })

  test("le rôle décide de ce que l'écran propose", async ({ page }) => {
    await login(page)
    await page.goto('/equipe')

    // Le compte de démonstration est propriétaire de son espace : il dispose
    // de tout, y compris du formulaire d'invitation.
    await expect(page.getByRole('heading', { name: /inviter quelqu'un/i })).toBeVisible()
    await expect(page.getByRole('heading', { name: /cet espace/i })).toBeVisible()

    // Il ne peut pas quitter son unique espace, et l'écran le DIT plutôt que
    // de laisser cliquer sur un bouton qui échouerait.
    await expect(page.getByRole('button', { name: /^quitter$/i })).toBeDisabled()
    await expect(page.getByText(/c'est votre seul espace/i)).toBeVisible()
  })

  test("le sélecteur d'espace nomme où l'on se trouve", async ({ page }) => {
    await login(page)

    // Il est visible en permanence, en tête de la barre latérale : savoir OÙ
    // l'on est précède la question de ce qu'on y voit.
    const selecteur = page.getByRole('button', { name: /utilisateur démo/i })

    await expect(selecteur).toBeVisible()
    await expect(selecteur).toContainText(/propriétaire/i)

    await selecteur.click()

    await expect(page.getByRole('listbox')).toBeVisible()
    await expect(page.getByRole('option', { name: /utilisateur démo/i })).toHaveAttribute(
      'aria-selected',
      'true',
    )

    // Le menu se referme sans naviguer : un menu qu'on ne sait pas fermer est
    // un menu qu'on n'ouvre plus.
    await page.keyboard.press('Escape')
    await expect(page.getByRole('listbox')).toBeHidden()
    expect(new URL(page.url()).pathname).toBe('/')
  })

  test('une invitation se révoque, et son lien cesse de valoir', async ({ page, request }) => {
    const invite = `revoque-${Date.now()}@test.local`

    await login(page)
    await page.goto('/equipe')

    await page.getByLabel(/adresse e-mail/i).fill(invite)
    await page.getByRole('button', { name: /^inviter$/i }).click()
    await expect(page.getByText(invite)).toBeVisible()

    const lien = await lienDInvitation(request, invite)

    await page
      .locator('li', { hasText: invite })
      .getByRole('button', { name: /révoquer/i })
      .click()

    // Sur la LIGNE, pas sur la page : le message de confirmation reprend
    // l'adresse pendant quelques secondes, et un « getByText » nu l'y aurait
    // trouvée — le test aurait échoué alors que tout allait bien.
    await expect(page.locator('li', { hasText: invite })).toBeHidden()

    // Le lien déjà envoyé ne vaut plus rien : c'est tout l'intérêt de pouvoir
    // révoquer une invitation partie par erreur.
    await page.goto(lien)
    await expect(page.getByRole('heading', { name: /ce lien ne fonctionne plus/i })).toBeVisible()

    // Et l'écran d'échec ne laisse pas dans une impasse. La sortie tient
    // compte de qui la lit : l'hôte est connecté, on lui rend l'application —
    // et non l'écran de connexion, qui l'aurait renvoyé ici même.
    await page.getByRole('link', { name: /retour à l'application/i }).click()
    await expect(page).toHaveURL(/localhost:\d+\/$/)
  })
})
