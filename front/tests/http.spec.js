import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * L'intercepteur HTTP porte deux comportements invisibles mais critiques :
 * le rafraîchissement automatique sur 401, et le fait qu'un seul appel à
 * /auth/refresh soit émis même si plusieurs requêtes échouent en même temps.
 * Une régression ici se traduirait par des déconnexions aléatoires.
 */
describe('client HTTP', () => {
  let http
  let configureSession
  let setAccessToken

  beforeEach(async () => {
    vi.resetModules()

    const module = await import('@/services/http')
    http = module.default
    configureSession = module.configureSession
    setAccessToken = module.setAccessToken
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('ajoute le jeton d’accès aux requêtes', async () => {
    setAccessToken('jeton-de-test')

    const config = await runRequestInterceptors(http, { url: '/api/modules', headers: {} })

    expect(config.headers.Authorization).toBe('Bearer jeton-de-test')
  })

  it('n’ajoute rien quand aucune session n’est ouverte', async () => {
    setAccessToken(null)

    const config = await runRequestInterceptors(http, { url: '/api/modules', headers: {} })

    expect(config.headers.Authorization).toBeUndefined()
  })

  it('normalise une panne réseau en message lisible', async () => {
    const rejected = errorHandler(http)

    await expect(rejected({ config: { url: '/api/modules' } })).rejects.toMatchObject({
      status: 0,
      message: expect.stringContaining('Impossible de joindre'),
    })
  })

  it('remonte les erreurs de validation champ par champ', async () => {
    const rejected = errorHandler(http)

    await expect(
      rejected({
        config: { url: '/api/modules/module-1/items' },
        response: { status: 422, data: { message: 'Invalide', errors: { title: 'obligatoire' } } },
      }),
    ).rejects.toMatchObject({
      status: 422,
      errors: { title: 'obligatoire' },
    })
  })

  it('ne rafraîchit pas la session sur les routes publiques d’authentification', async () => {
    const refresh = vi.fn()
    configureSession({ refresh, onExpired: vi.fn() })

    const rejected = errorHandler(http)

    await expect(
      rejected({
        config: { url: '/api/auth/login' },
        response: { status: 401, data: { message: 'Identifiants incorrects.' } },
      }),
    ).rejects.toMatchObject({ status: 401 })

    // Un 401 sur /auth/login est une réponse métier : rafraîchir n'aurait
    // aucun sens et masquerait l'erreur à l'utilisateur.
    expect(refresh).not.toHaveBeenCalled()
  })

  it('ne déclenche qu’un seul rafraîchissement pour plusieurs 401 simultanés', async () => {
    let resolveRefresh
    const refresh = vi.fn(() => new Promise((resolve) => (resolveRefresh = resolve)))
    configureSession({ refresh, onExpired: vi.fn() })

    // Après le rafraîchissement, l'intercepteur rejoue la requête d'origine.
    // L'adaptateur est remplacé pour que ce rejeu n'atteigne jamais le réseau :
    // sinon jsdom tenterait une connexion réelle et polluerait la sortie.
    http.defaults.adapter = vi
      .fn()
      .mockResolvedValue({ data: {}, status: 200, headers: {}, config: {} })

    const rejected = errorHandler(http)

    const first = rejected({
      config: { url: '/api/dashboard' },
      response: { status: 401, data: {} },
    })
    const second = rejected({
      config: { url: '/api/modules' },
      response: { status: 401, data: {} },
    })

    resolveRefresh()
    await Promise.allSettled([first, second])

    // Deux requêtes échouées, un seul appel à /auth/refresh : c'est le
    // verrou « single-flight » qui évite une rafale de rafraîchissements.
    expect(refresh).toHaveBeenCalledTimes(1)
  })
})

/** Exécute la chaîne d'intercepteurs de requête sur une configuration. */
async function runRequestInterceptors(http, config) {
  let result = config

  for (const handler of http.interceptors.request.handlers) {
    if (handler?.fulfilled) result = await handler.fulfilled(result)
  }

  return result
}

/** Récupère le gestionnaire d'erreur de réponse. */
function errorHandler(http) {
  const handler = http.interceptors.response.handlers.find((h) => h?.rejected)

  return handler.rejected
}
