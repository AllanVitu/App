import { expect, login, test } from './support.js'

/**
 * L'adresse, et l'entrée dans le contenu.
 *
 * Deux corrections qui ne se constatent qu'à l'échelle du navigateur : ce que
 * la barre d'adresse contient, ce que le bouton « précédent » fait, et où le
 * focus se trouve. Aucune ne s'observe depuis un test unitaire, parce
 * qu'aucune n'existe hors d'une vraie session de navigation.
 */
test.describe('adresse et entrée dans le contenu', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
  })

  // ─────────────────────────────────────────────────────── l'état partageable

  test('un filtre posé s’inscrit dans l’adresse et lui survit', async ({ page }) => {
    await page.goto('/modules/supervision')
    await expect(page.getByRole('heading', { name: 'supervision' })).toBeVisible()

    // Adresse nue tant qu'on n'a rien touché : un écran par défaut ne doit pas
    // porter d'état que personne n'a choisi.
    expect(new URL(page.url()).search).toBe('')

    await page.getByRole('button', { name: 'ignorées', exact: true }).first().click()
    await expect(page).toHaveURL(/statut=ignored/)

    // CE QUE ÇA CHANGE : le lien décrit ce qu'on regarde. Rechargé — ou envoyé
    // à quelqu'un — il rouvre le même écran, pas l'écran par défaut.
    await page.reload()

    await expect(
      page.getByRole('button', { name: 'ignorées', exact: true }).first(),
    ).toHaveAttribute('aria-pressed', 'true')
  })

  test('la recherche s’inscrit dans l’adresse sans encombrer l’historique', async ({ page }) => {
    await page.goto('/modules/tickets')
    await expect(page.getByRole('heading', { name: 'tickets' })).toBeVisible()

    await page.getByRole('searchbox', { name: /rechercher un ticket/i }).fill('recette')
    await expect(page).toHaveURL(/q=recette/)

    // LE POINT DE « replace » : sept lettres, sept écritures d'adresse, et
    // pourtant UN SEUL « précédent » pour revenir à la page d'avant. Avec
    // « push », il en aurait fallu sept — puis une huitième.
    await page.goBack()

    await expect(page).toHaveURL(/\/$/)
    await expect(page.getByRole('heading', { name: /bonjour/i })).toBeVisible()
  })

  test('un lien filtré collé ouvre l’écran filtré', async ({ page }) => {
    // Le cas du destinataire : il n'a pas cliqué sur le filtre, il arrive
    // dessus. Rien d'autre que l'adresse ne peut le lui dire.
    await page.goto('/modules/deploiement?q=main&statut=error')

    await expect(page.getByRole('searchbox', { name: /rechercher un déploiement/i })).toHaveValue(
      'main',
    )
    await expect(page.getByRole('button', { name: 'en échec', exact: true })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
  })

  test('le bouton « précédent » rend le filtre qu’il avait', async ({ page }) => {
    await page.goto('/modules/design')

    await page.getByRole('searchbox', { name: /rechercher un fichier/i }).fill('parcours')
    await expect(page).toHaveURL(/q=parcours/)

    await page.goto('/modules/tickets')
    await expect(page.getByRole('heading', { name: 'tickets' })).toBeVisible()

    await page.goBack()

    // Sans état dans l'adresse, on revenait sur un écran vierge : le filtre
    // était perdu alors que « précédent » promet exactement le contraire.
    await expect(page.getByRole('searchbox', { name: /rechercher un fichier/i })).toHaveValue(
      'parcours',
    )
  })

  // ──────────────────────────────────────────────── l'entrée dans le contenu

  test('la première tabulation offre d’aller au contenu', async ({ page }) => {
    await page.goto('/modules/tickets')
    await expect(page.getByRole('heading', { name: 'tickets' })).toBeVisible()

    // Le menu latéral compte une vingtaine d'éléments focalisables, identiques
    // sur toutes les pages. Sans ce lien, il faut tous les retraverser à
    // CHAQUE changement d'écran pour atteindre ce qu'on vient d'ouvrir.
    await page.locator('body').press('Tab')

    const lien = page.getByRole('link', { name: /aller au contenu/i })
    await expect(lien).toBeFocused()

    // Invisible jusque-là, visible une fois atteint : il n'existe que pour
    // cette tabulation.
    await expect(lien).toBeInViewport()

    await lien.press('Enter')

    await expect(page.locator('main#contenu')).toBeFocused()

    // Et il ne laisse pas « #contenu » dans l'adresse : le saut est fait à la
    // main précisément pour éviter cette trace.
    expect(page.url()).not.toContain('#contenu')
  })

  test('changer d’écran amène le focus sur le nouvel écran', async ({ page }) => {
    await page.goto('/modules/tickets')
    await expect(page.getByRole('heading', { name: 'tickets' })).toBeVisible()

    await page.getByRole('link', { name: /supervision/i }).first().click()
    await expect(page.getByRole('heading', { name: 'supervision' })).toBeVisible()

    // Dans une application d'une seule page, changer d'écran ne déplace ni le
    // focus ni le curseur virtuel d'un lecteur d'écran : le titre du document
    // change, et rien ne l'annonce.
    const main = page.locator('main#contenu')
    await expect(main).toBeFocused()
    await expect(main).toHaveAttribute('aria-label', 'Supervision')
  })

  // ──────────────────────────────────────────── fraîcheur et erreurs muettes

  test('le retour du réseau relit les données, sans vider l’écran', async ({ page }) => {
    await page.goto('/modules/tickets')
    await expect(page.getByRole('heading', { name: 'tickets' })).toBeVisible()

    // Le titre s'affiche AVANT la liste : « count » ne réessaie pas, et
    // compterait zéro carte sur un écran encore en chargement. On attend donc
    // la première par une assertion qui, elle, patiente.
    const cartes = page.getByRole('option')
    await expect(cartes.first()).toBeVisible()

    const avant = await cartes.count()

    // Le seuil d'absence est éprouvé en test unitaire, horloge feinte à
    // l'appui. CE QUI SE VÉRIFIE ICI, ET NULLE PART AILLEURS : que le
    // composable est bien BRANCHÉ sur cet écran. Le retour du réseau
    // déclenche sans seuil — donc sans faire attendre la suite.
    const relecture = page.waitForResponse(
      (reponse) =>
        reponse.url().includes('/api/tickets') && reponse.request().method() === 'GET',
    )

    await page.evaluate(() => window.dispatchEvent(new Event('online')))
    await relecture

    // SILENCIEUX : les lignes restent à l'écran pendant la relecture.
    // Les remplacer par un rond qui tourne donnerait l'impression de les
    // avoir perdues, pour une opération que personne n'a demandée.
    await expect(cartes).toHaveCount(avant)
  })

  test('une promesse rejetée que personne n’attrape ne disparaît plus', async ({ page }) => {
    await page.goto('/')
    await expect(page.getByRole('heading', { name: /bonjour/i })).toBeVisible()

    // Un « await » oublié, une écriture lancée sans « catch » : cela partait
    // dans la console, invisible pour qui n'a pas les outils de développement
    // ouverts. L'écran restait tel quel, l'action n'aboutissait pas, et rien
    // ne disait pourquoi.
    await page.evaluate(() => {
      Promise.reject(new Error('sonde de recette'))
    })

    await expect(page.getByText(/n’a pas abouti|n'a pas abouti/i)).toBeVisible()
  })

  test('taper dans la recherche ne vole pas le curseur', async ({ page }) => {
    await page.goto('/modules/tickets')

    const champ = page.getByRole('searchbox', { name: /rechercher un ticket/i })
    await champ.fill('rec')

    // LE PIÈGE DE CES DEUX CORRECTIONS MISES ENSEMBLE : les filtres écrivent
    // dans l'adresse, et le focus suit la navigation. Si le second observait
    // l'adresse entière plutôt que le seul chemin, chaque lettre arracherait
    // le curseur du champ.
    await expect(page).toHaveURL(/q=rec/)
    await expect(champ).toBeFocused()

    await champ.pressSequentially('ette')
    await expect(champ).toHaveValue('recette')
    await expect(champ).toBeFocused()
  })
})
