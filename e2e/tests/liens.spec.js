import { expect, login, test } from './support.js'

/**
 * Les modules se parlent.
 *
 * Deux parcours, chacun rejouable sur le compte de démonstration et qui
 * range derrière lui : un commentaire écrit, rendu sans jamais être
 * interprété, puis retiré ; une erreur qui ouvre son ticket, et le ticket mis
 * à la corbeille — ce qui rend l'erreur à personne.
 */
test.describe('liens entre modules', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
  })

  test('un commentaire s’écrit sous le ticket, sans interpréter aucune balise', async ({ page }) => {
    const titre = `Discussion de parcours ${Date.now()}`

    await page.goto('/modules/tickets')

    // La touche part quand l'écran l'écoute : avant que la liste soit là, « c »
    // tombe dans le vide.
    await expect(page.getByRole('heading', { name: 'tickets' })).toBeVisible()
    await expect(page.getByRole('option').first()).toBeVisible()
    await page.keyboard.press('c')
    await page.getByPlaceholder(/Entrée pour créer/i).fill(titre)
    await page.keyboard.press('Enter')

    const ligne = page.getByRole('option').filter({ hasText: titre })
    await expect(ligne).toHaveAttribute('aria-selected', 'true')

    await page.keyboard.press('Escape')
    await page.keyboard.press('Enter')

    const panneau = page.getByRole('complementary', { name: /détail du ticket/i })
    const fil = panneau.getByRole('list', { name: 'Commentaires' })

    try {
      await panneau
        .getByRole('textbox', { name: 'Votre commentaire' })
        .fill('**Reproduit** sur mobile <img src=x onerror="window.__injection = true">')

      // Entrée seule est un retour à la ligne ; Ctrl+Entrée envoie.
      await page.keyboard.press('Control+Enter')

      await expect(fil.getByRole('listitem')).toHaveCount(1)
      await expect(fil.locator('strong')).toHaveText('Reproduit')
      await expect(fil.getByText('<img src=x onerror="window.__injection = true">')).toBeVisible()
      await expect(fil.locator('img')).toHaveCount(0)
      expect(await page.evaluate(() => window.__injection)).toBeUndefined()

      await fil.getByRole('button', { name: /retirer le commentaire/i }).click()
      await expect(fil).toHaveCount(0)
    } finally {
      // Ménage : le rechargement rend le focus à la liste, où « Retour
      // arrière » supprime le ticket sous le curseur.
      await page.reload()
      await ligne.click()
      await page.keyboard.press('Backspace')
      // Le bandeau d'annulation ne paraît qu'une fois la suppression reçue par
      // le serveur : l'attendre, c'est ne pas couper la requête en fermant.
      await expect(page.getByText(/Ticket #[0-9]+ supprimé/)).toBeVisible()
    }
  })

  test('une erreur ouvre son ticket, et s’en souvient', async ({ page }) => {
    await page.goto('/modules/supervision')

    // Une erreur du jeu de démonstration, quelle que soit sa colonne.
    await page.locator('[data-column]').getByRole('option').first().click()

    const fenetre = page.getByRole('dialog')
    await expect(fenetre).toBeVisible()

    const titreErreur = ((await fenetre.getByRole('heading').first().textContent()) ?? '').trim()
    const lien = fenetre.getByRole('link', { name: /#[0-9]+/ })

    // Rejouable : un passage interrompu a pu laisser un ticket derrière lui ;
    // on repart alors de celui-là plutôt que d'échouer.
    if (!(await lien.count())) {
      await fenetre.getByRole('button', { name: /créer un ticket/i }).click()
    }

    await expect(lien).toBeVisible()
    await expect(lien).toContainText('à faire')

    // Rouverte, l'erreur montre toujours son ticket : le lien vient du serveur.
    await page.keyboard.press('Escape')
    await page.reload()
    await page.locator('[data-column]').getByRole('option').filter({ hasText: titreErreur }).first().click()
    await expect(lien).toBeVisible()

    await lien.click()
    await expect(page).toHaveURL(/modules[/]tickets/)

    const ticket = page.getByRole('option').filter({ hasText: `Erreur : ${titreErreur.slice(0, 40)}` })
    await expect(ticket.first()).toBeVisible()

    // Ménage : à la corbeille, le ticket ne s'occupe plus de rien.
    await ticket.first().click()
    await page.keyboard.press('Backspace')
    await expect(ticket).toHaveCount(0)

    // Le bandeau paraît quand le serveur a répondu. Naviguer avant couperait
    // la requête en vol : le ticket vivrait encore, et l'erreur avec lui.
    await expect(page.getByText(/Ticket #[0-9]+ supprimé/)).toBeVisible()

    await page.goto('/modules/supervision')
    await page.locator('[data-column]').getByRole('option').filter({ hasText: titreErreur }).first().click()
    await expect(page.getByRole('dialog').getByRole('button', { name: /créer un ticket/i })).toBeVisible()
  })
})
