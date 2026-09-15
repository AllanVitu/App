import { coequipier, expect, login, test } from './support.js'

/**
 * Deux personnes, deux navigateurs, le même tableau.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  LE SEUL NIVEAU OÙ CE JALON EXISTE VRAIMENT                             │
 * │                                                                         │
 * │  Les tests d'API prouvent chaque maillon : le journal consigne, le flux │
 * │  suit un curseur, le conflit nomme son champ. Ils le font avec deux     │
 * │  jetons dans le même processus.                                         │
 * │                                                                         │
 * │  Ici il y a deux VRAIS navigateurs, deux sessions, deux horloges. C'est │
 * │  le seul endroit où l'on constate qu'un geste d'Alice apparaît chez Bob │
 * │  sans que personne ne recharge — la promesse entière du jalon.          │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * DEUX COMPTES DISTINCTS, ET C'EST INDISPENSABLE. La présence ne se montre
 * pas à soi-même, et l'arbitrage ne s'applique pas à ses propres écritures :
 * deux onglets d'un même compte verraient passer les deux mécanismes sans
 * jamais les déclencher, et le test passerait au vert en ne prouvant rien.
 */

/** Le flux bat toutes les trois secondes ; on lui laisse deux battements. */
const PROPAGATION = 8000

/** Contexte outillé comme celui du fixture : sans le son, qui masquerait tout. */
const creerContexte = (browser) => async () => {
  const contexte = await browser.newContext()

  await contexte.addInitScript(() => {
    try {
      window.localStorage.setItem('sound', 'off')
    } catch {
      /* stockage indisponible : l'écran s'affichera, le test le dira */
    }
  })

  return contexte
}

test.describe('collaboration', () => {
  test('un changement apparaît chez l’autre sans rechargement', async ({ page, browser }) => {
    const titre = `Partagé ${Date.now()}`

    await login(page)

    // Deux fenêtres du MÊME compte suffisent ici : le journal ne filtre pas
    // par auteur, et c'est la propagation qu'on regarde. Les deux tests
    // suivants, eux, ont besoin d'un vrai second compte.
    const contexte = await creerContexte(browser)()
    const autre = await contexte.newPage()

    await login(autre)
    await autre.goto('/modules/tickets')
    await expect(autre.getByRole('heading', { name: 'tickets' })).toBeVisible()

    await page.goto('/modules/tickets')
    await expect(page.getByRole('heading', { name: 'tickets' })).toBeVisible()

    // --- La première fenêtre crée -------------------------------------------
    await page.keyboard.press('c')
    await page.getByPlaceholder(/Entrée pour créer/i).fill(titre)
    await page.keyboard.press('Enter')
    await page.keyboard.press('Escape')

    await expect(page.getByRole('option').filter({ hasText: titre })).toBeVisible()

    // --- La seconde le voit arriver, seule ----------------------------------
    //
    // AUCUN rechargement, aucun changement d'onglet : c'est tout l'objet. Sans
    // le flux, il aurait fallu revenir sur l'onglet après trente secondes
    // d'absence pour que useRevalidate s'en aperçoive.
    await expect(autre.getByRole('option').filter({ hasText: titre })).toBeVisible({
      timeout: PROPAGATION,
    })

    // --- Un changement de priorité traverse aussi ----------------------------
    await page.keyboard.press('1')

    const ligneDistante = autre.getByRole('option').filter({ hasText: titre })

    await expect(ligneDistante.getByRole('img', { name: 'priorité urgente' })).toBeVisible({
      timeout: PROPAGATION,
    })

    // --- Et la suppression --------------------------------------------------
    await page.keyboard.press('Backspace')

    await expect(autre.getByRole('option').filter({ hasText: titre })).toBeHidden({
      timeout: PROPAGATION,
    })

    await contexte.close()
  })

  test('un ticket ouvert par quelqu’un d’autre le dit avant qu’on y touche', async ({
    page,
    browser,
    request,
  }) => {
    const titre = `Occupé ${Date.now()}`

    await login(page)

    const bob = await coequipier(page, browser, request, {
      creerContexte: creerContexte(browser),
    })

    await page.goto('/modules/tickets')

    // L'écran AVANT la frappe : « c » sur une page encore vide n'ouvre rien,
    // et l'échec se lirait vingt lignes plus bas comme un défaut de flux.
    await expect(page.getByRole('heading', { name: 'tickets' })).toBeVisible()

    await page.keyboard.press('c')
    await page.getByPlaceholder(/Entrée pour créer/i).fill(titre)
    await page.keyboard.press('Enter')
    await page.keyboard.press('Escape')

    const ligne = page.getByRole('option').filter({ hasText: titre })
    await expect(ligne).toBeVisible()

    // Bob ouvre le ticket.
    await bob.page.goto('/modules/tickets')
    await expect(bob.page.getByRole('option').filter({ hasText: titre })).toBeVisible({
      timeout: PROPAGATION,
    })
    await bob.page.getByRole('option').filter({ hasText: titre }).click()
    await bob.page.keyboard.press('Enter')

    // On l'apprend AVANT d'écrire, pas après. Un conflit qu'on peut ne pas
    // provoquer vaut mieux qu'un conflit bien arbitré.
    await expect(ligne.getByText(new RegExp(`${bob.nom} y est`))).toBeVisible({
      timeout: PROPAGATION,
    })

    await bob.effacer()

    // Le marqueur s'efface : la présence d'un compte disparu ne doit pas
    // rester affichée sur un ticket que plus personne ne regarde.
    await expect(ligne.getByText(/y est$/)).toBeHidden({ timeout: PROPAGATION })

    await page.getByRole('option').filter({ hasText: titre }).click()
    await page.keyboard.press('Backspace')
  })

  test('un conflit propose les deux versions au lieu de choisir', async ({
    page,
    browser,
    request,
  }) => {
    const titre = `Disputé ${Date.now()}`

    await login(page)

    const bob = await coequipier(page, browser, request, {
      creerContexte: creerContexte(browser),
    })

    await page.goto('/modules/tickets')

    // L'écran AVANT la frappe : « c » sur une page encore vide n'ouvre rien,
    // et l'échec se lirait vingt lignes plus bas comme un défaut de flux.
    await expect(page.getByRole('heading', { name: 'tickets' })).toBeVisible()

    await page.keyboard.press('c')
    await page.getByPlaceholder(/Entrée pour créer/i).fill(titre)
    await page.keyboard.press('Enter')
    await page.keyboard.press('Escape')

    await expect(page.getByRole('option').filter({ hasText: titre })).toBeVisible()

    // La première fenêtre ouvre le panneau : elle détient désormais une
    // version, et écrira en partant d'elle.
    await page.keyboard.press('Enter')
    await expect(page.getByLabel(/description/i)).toBeVisible()

    // --- Pendant ce temps, Bob écrit le MÊME champ ---------------------------
    await bob.page.goto('/modules/tickets')
    await expect(bob.page.getByRole('option').filter({ hasText: titre })).toBeVisible({
      timeout: PROPAGATION,
    })
    await bob.page.getByRole('option').filter({ hasText: titre }).click()
    await bob.page.keyboard.press('Enter')

    const descriptionDeBob = bob.page.getByLabel(/description/i)
    await expect(descriptionDeBob).toBeVisible()
    await descriptionDeBob.fill('Le texte de Bob')
    await descriptionDeBob.blur()
    await expect(bob.page.getByText('enregistrement…')).toBeHidden()

    // --- La première écrit à son tour, en partant de sa version périmée -----
    //
    // Le panneau est délibérément épargné par le flux : voir un champ se
    // réécrire sous ses doigts est pire que de l'ignorer. L'arbitrage a donc
    // lieu ICI, à l'enregistrement.
    const description = page.getByLabel(/description/i)

    await description.fill('Le texte de la première')
    await description.blur()

    // --- L'arbitrage --------------------------------------------------------
    const dialogue = page.getByRole('dialog')

    await expect(dialogue).toBeVisible({ timeout: PROPAGATION })

    // LES DEUX VERSIONS SONT LÀ. C'est la différence avec un « rechargez » qui
    // emporte le paragraphe qu'on venait d'écrire.
    await expect(dialogue.getByText('Le texte de la première')).toBeVisible()
    await expect(dialogue.getByText('Le texte de Bob')).toBeVisible()

    // Et le champ est NOMMÉ, avec qui l'a touché.
    await expect(dialogue.getByText(new RegExp(`${bob.nom} a modifié`))).toBeVisible()

    // --- On garde la sienne --------------------------------------------------
    await dialogue.getByRole('button', { name: /garder la mienne/i }).click()

    await expect(dialogue).toBeHidden()
    await expect(page.getByLabel(/description/i)).toHaveValue('Le texte de la première')

    await bob.effacer()

    await page.keyboard.press('Escape')
    await page.getByRole('option').filter({ hasText: titre }).click()
    await page.keyboard.press('Backspace')
  })
})
