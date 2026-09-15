import { nextTick, ref } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

import { useQuerySync } from '@/composables/useQuerySync'

/**
 * L'état d'un écran dans son adresse.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  CE COMPOSABLE MARCHE SUR DEUX FILS TENDUS                          │
 * │                                                                     │
 * │  Il écrit dans l'adresse quand les champs changent, et écrit dans   │
 * │  les champs quand l'adresse change. Chaque sens déclenche l'autre : │
 * │  une garde trop étroite et l'écriture boucle indéfiniment, une      │
 * │  garde trop large et le bouton « précédent » ne fait plus rien.     │
 * │                                                                     │
 * │  Ni l'un ni l'autre ne se voit dans un parcours navigateur qui se   │
 * │  contente de filtrer et de constater la liste.                      │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Un VRAI routeur, en mémoire. Le remplacer par un simulacre reviendrait à
 * éprouver mes hypothèses sur vue-router plutôt que vue-router — et c'est
 * précisément sur son comportement réel (l'ordre des observateurs, le moment
 * où « currentRoute » change) que tout repose ici.
 *
 * Les minuteurs restent RÉELS, avec un délai d'écriture ramené à zéro. Sous
 * minuteurs feints, `advanceTimersByTime` déclenche bien l'écriture mais la
 * navigation qu'elle lance ne s'achève jamais : les tests constataient une
 * adresse vide et accusaient le composable.
 */

const Vide = { template: '<div />' }

/** Monte un composant qui ne fait qu'appeler le composable. */
async function monter(champs, { url = '/liste', precedent, ...options } = {}) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/liste', component: Vide },
      { path: '/ailleurs', component: Vide },
    ],
  })

  // « precedent » donne de la profondeur à l'historique : sans page derrière,
  // « précédent » ne peut rien prouver.
  if (precedent) await router.push(precedent)

  await router.push(url)
  await router.isReady()

  const wrapper = mount(
    {
      setup() {
        useQuerySync(champs, { delai: 0, ...options })

        return {}
      },
      template: '<div />',
    },
    { global: { plugins: [router] } },
  )

  return { router, wrapper }
}

/**
 * Laisse le tour de boucle se terminer.
 *
 * Les observateurs de Vue sont différés, la temporisation d'écriture passe
 * par un minuteur, et la navigation qui suit enchaîne plusieurs promesses.
 * Rien de tout cela n'a eu lieu à l'instant où le test affecte un champ.
 */
async function reposer(ms = 0) {
  await nextTick()
  await new Promise((resolve) => setTimeout(resolve, ms))
  await nextTick()
}

describe('useQuerySync', () => {
  it('remplit les champs depuis l’adresse, au montage', async () => {
    const search = ref('')
    const statut = ref(null)

    await monter({ q: search, statut }, { url: '/liste?q=quota&statut=unresolved' })

    // C'est ce qui rend un lien partageable : le destinataire voit le même
    // écran, pas l'écran par défaut.
    expect(search.value).toBe('quota')
    expect(statut.value).toBe('unresolved')
  })

  it('écrit dans l’adresse ce que les champs disent', async () => {
    const search = ref('')
    const { router } = await monter({ q: search })

    search.value = 'timeout'
    await reposer()

    expect(router.currentRoute.value.query).toEqual({ q: 'timeout' })
  })

  it('ne montre jamais les valeurs par défaut', async () => {
    const search = ref('')
    const statut = ref(null)
    const { router } = await monter({ q: search, statut })

    search.value = 'quota'
    await reposer()

    // Sans cette règle, ouvrir un module afficherait aussitôt « ?q=&statut= »,
    // et le lien copié porterait un état que personne n'a choisi.
    expect(router.currentRoute.value.query).toEqual({ q: 'quota' })

    search.value = ''
    await reposer()

    expect(router.currentRoute.value.fullPath).toBe('/liste')
  })

  it('remplace l’entrée d’historique au lieu d’en empiler une', async () => {
    const search = ref('')
    const { router } = await monter({ q: search }, { precedent: '/ailleurs' })

    const empile = vi.spyOn(router, 'push')

    search.value = 'timeout'
    await reposer()

    expect(empile).not.toHaveBeenCalled()
    expect(router.currentRoute.value.fullPath).toBe('/liste?q=timeout')

    // CE QUE LA RÈGLE PROMET : un seul « précédent » ramène à la PAGE d'avant.
    // Si le filtrage empilait, il faudrait d'abord défaire le filtre — et une
    // recherche tapée lettre par lettre en produirait autant d'entrées.
    router.back()
    await reposer()

    expect(router.currentRoute.value.fullPath).toBe('/ailleurs')
  })

  it('regroupe une rafale de frappes en une seule écriture', async () => {
    const search = ref('')
    const { router } = await monter({ q: search }, { delai: 40 })

    const remplace = vi.spyOn(router, 'replace')

    for (const valeur of ['t', 'ti', 'tim', 'time']) {
      search.value = valeur
      await nextTick()
    }

    await reposer(80)

    expect(remplace).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.query).toEqual({ q: 'time' })
  })

  it('ne réécrit pas l’adresse qu’il vient d’écrire', async () => {
    const search = ref('')
    const { router } = await monter({ q: search })

    const remplace = vi.spyOn(router, 'replace')

    search.value = 'quota'
    await reposer()

    expect(remplace).toHaveBeenCalledTimes(1)

    // LA BOUCLE : l'écriture change l'adresse, l'observateur d'adresse
    // remplit les champs, ce qui redéclenche une écriture… La comparaison
    // entre l'adresse et ce que disent les champs est ce qui l'arrête, et
    // rien d'autre ne le ferait.
    await reposer()
    await reposer()

    expect(remplace).toHaveBeenCalledTimes(1)
  })

  it('suit le bouton « précédent »', async () => {
    const search = ref('')
    const { router } = await monter({ q: search }, { url: '/liste?q=quota' })

    expect(search.value).toBe('quota')

    await router.push('/liste?q=timeout')
    await reposer()
    expect(search.value).toBe('timeout')

    router.back()
    await reposer()

    expect(search.value).toBe('quota')
  })

  it('prévient l’écran quand l’adresse a changé sans lui', async () => {
    const search = ref('')
    const surRetour = vi.fn()

    const { router } = await monter({ q: search }, { url: '/liste?q=a', surRetour })

    // Pas au montage : l'écran est sur le point de charger de lui-même.
    expect(surRetour).not.toHaveBeenCalled()

    await router.push('/liste?q=b')
    await reposer()

    // Un écran qui filtre CÔTÉ SERVEUR doit relancer sa requête ici. Sans ce
    // signal, l'adresse dirait « b » et la liste montrerait encore « a ».
    expect(surRetour).toHaveBeenCalledTimes(1)
    expect(search.value).toBe('b')
  })

  it('ne prévient pas l’écran de ses propres écritures', async () => {
    const search = ref('')
    const surRetour = vi.fn()

    await monter({ q: search }, { surRetour })

    search.value = 'quota'
    await reposer()

    // Sinon chaque frappe dans la recherche relancerait une requête, ce que
    // la temporisation était justement censée éviter.
    expect(surRetour).not.toHaveBeenCalled()
  })

  it('laisse tranquilles les paramètres qui ne sont pas les siens', async () => {
    const search = ref('')
    const { router } = await monter({ q: search }, { url: '/liste?redirect=%2Fmodules' })

    search.value = 'quota'
    await reposer()

    // « redirect » appartient au parcours de connexion : l'effacer perdrait
    // la destination mémorisée après identification.
    expect(router.currentRoute.value.query).toEqual({ redirect: '/modules', q: 'quota' })
  })

  it('convertit ce qui n’est pas du texte', async () => {
    const lire = (texte) => Math.max(1, Number(texte) || 1)

    const page = ref(1)
    await monter({ page: { ref: page, lire } }, { url: '/liste?page=3' })

    // Sans conversion, la page vaudrait la chaîne « 3 », et « page + 1 »
    // donnerait « 31 ».
    expect(page.value).toBe(3)

    // Une adresse forgée à la main ne doit pas casser l'écran.
    const perdue = ref(1)
    await monter({ page: { ref: perdue, lire } }, { url: '/liste?page=nimporte' })

    expect(perdue.value).toBe(1)
  })

  it('n’écrit pas sur la page suivante après avoir été démonté', async () => {
    const search = ref('')
    const { router, wrapper } = await monter({ q: search }, { delai: 40 })

    // Taper puis changer d'écran aussitôt. L'observateur est arrêté par Vue,
    // mais le minuteur, lui, court encore : sans son annulation explicite, il
    // écrirait « ?q=… » dans l'adresse d'un écran qui n'a rien demandé.
    search.value = 'quota'
    await nextTick()
    wrapper.unmount()

    await router.push('/ailleurs')
    await reposer(80)

    expect(router.currentRoute.value.fullPath).toBe('/ailleurs')
  })
})
