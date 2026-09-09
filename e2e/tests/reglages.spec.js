import { expect, login, test } from './support.js'

/**
 * Les réglages d'apparence.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  CET ÉCRAN A DÉJÀ PERDU DES RÉGLAGES QUI N'AGISSAIENT PAS           │
 * │                                                                     │
 * │  Trois interrupteurs de notification et un choix « English » en ont │
 * │  été retirés : ils étaient enregistrés en base et consommés par     │
 * │  personne. Un réglage qui ne change rien fait croire à un contrôle  │
 * │  qui n'existe pas.                                                  │
 * │                                                                     │
 * │  Ces tests vérifient donc l'EFFET, pas la case cochée.              │
 * └─────────────────────────────────────────────────────────────────────┘
 */
test.describe('réglages d’apparence', () => {
  test.beforeEach(async ({ page }) => {
    await login(page)
    await page.goto('/parametres')
  })

  const densite = (page) => page.evaluate(() => document.documentElement.dataset.densite)
  const corps = (page) =>
    page.evaluate(() => getComputedStyle(document.body).fontSize)

  test('la densité change vraiment la taille de l’interface', async ({ page }) => {
    await expect(page.getByRole('heading', { name: /apparence/i })).toBeVisible()

    // ┌─────────────────────────────────────────────────────────────────┐
    // │  ON POSE L'ÉTAT DE DÉPART PLUTÔT QUE DE LE SUPPOSER             │
    // │                                                                 │
    // │  La densité est une préférence de COMPTE, et le compte de       │
    // │  démonstration est partagé par toute la suite. Mesurer « avant » │
    // │  sans avoir fixé l'état fait dépendre ce test de ce qui a tourné │
    // │  avant lui — ou d'une exécution précédente interrompue, ce qui   │
    // │  s'est produit dès le premier essai.                             │
    // └─────────────────────────────────────────────────────────────────┘
    await page.getByRole('button', { name: 'Confortable', exact: true }).click()
    const avant = await corps(page)

    await page.getByRole('button', { name: 'Compact', exact: true }).click()

    // Appliqué TOUT DE SUITE, sans enregistrer : un réglage d'apparence se
    // juge à l'œil, et demander d'enregistrer pour voir fait trois
    // allers-retours là où il en faut zéro.
    expect(await densite(page)).toBe('compact')
    expect(await corps(page)).not.toBe(avant)

    // Et il survit au rechargement : c'est une préférence de COMPTE, pas un
    // état d'écran.
    await page.getByRole('button', { name: /enregistrer/i }).click()
    await expect(page.getByText(/paramètres enregistrés/i)).toBeVisible()

    await page.reload()
    expect(await densite(page)).toBe('compact')

    // Remise en état pour les autres tests.
    await page.getByRole('button', { name: 'Confortable', exact: true }).click()
    await page.getByRole('button', { name: /enregistrer/i }).click()
    await expect(page.getByText(/paramètres enregistrés/i)).toBeVisible()
  })

  test('réduire les animations coupe réellement les transitions', async ({ page }) => {
    const transition = () =>
      page.evaluate(() => {
        const cible = document.querySelector('main')

        return getComputedStyle(cible).transitionDuration
      })

    await page.getByLabel(/réduire les animations/i).check()

    expect(await page.evaluate(() => document.documentElement.dataset.mouvement)).toBe('reduit')

    // Ce n'est pas l'attribut qui compte, c'est son effet : les transitions
    // CSS tombent à une durée négligeable.
    //
    // « 0.01ms » dans la feuille de style, que le navigateur rend « 1e-05s ».
    // On compare donc un NOMBRE : la forme exacte est un détail de
    // sérialisation, ce qui compte est l'ordre de grandeur.
    expect(Number.parseFloat(await transition())).toBeLessThan(0.001)

    await page.getByLabel(/réduire les animations/i).uncheck()
    expect(await page.evaluate(() => document.documentElement.dataset.mouvement)).toBe('normal')
  })

  test('le son a enfin sa place dans l’écran des préférences', async ({ page }) => {
    // Il existait, il persistait — et son seul interrupteur vivait dans la
    // barre du haut. Ce qui manquait n'était pas la fonctionnalité mais
    // l'endroit où on la cherche.
    const bascule = page.getByLabel(/retours sonores/i)

    await expect(bascule).toBeVisible()
    await expect(bascule).not.toBeChecked()

    await bascule.check()
    await expect(bascule).toBeChecked()

    // Retenu sur l'APPAREIL et non sur le compte : l'écran d'entrée sonore
    // est proposé avant toute connexion.
    expect(await page.evaluate(() => window.localStorage.getItem('sound'))).toBe('on')

    await bascule.uncheck()
  })
})
