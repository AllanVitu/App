import http from './http'

/**
 * Surface d'appel de l'API REST.
 *
 * Les composants n'écrivent jamais d'URL : un changement de contrat côté PHP
 * se répercute ici seulement. Chaque fonction renvoie directement la charge
 * utile (`data`), le format d'enveloppe { data, meta } restant interne.
 */

const unwrap = (response) => response.data.data

// --- Authentification -------------------------------------------------------

export const authApi = {
  login: (credentials) => http.post('/auth/login', credentials).then(unwrap),
  register: (payload) => http.post('/auth/register', payload).then(unwrap),
  refresh: () => http.post('/auth/refresh').then(unwrap),
  logout: () => http.post('/auth/logout'),
  me: () => http.get('/auth/me').then(unwrap),
}

// --- Vérification d'adresse et mot de passe oublié --------------------------

export const accountApi = {
  verifyEmail: (token) => http.post('/auth/email/verify', { token }).then(unwrap),
  resendVerification: () => http.post('/auth/email/resend').then(unwrap),
  forgotPassword: (email) => http.post('/auth/password/forgot', { email }).then(unwrap),
  resetPassword: (payload) => http.post('/auth/password/reset', payload).then(unwrap),
}

// --- Espaces de travail -----------------------------------------------------

export const organizationsApi = {
  list: () => http.get('/organizations').then(unwrap),
  create: (name) => http.post('/organizations', { name }).then(unwrap),
  rename: (id, name) => http.put(`/organizations/${id}`, { name }).then(unwrap),
  destroy: (id) => http.delete(`/organizations/${id}`),

  /**
   * Bascule d'espace. C'est le SEUL appel qui change ce que l'API renvoie
   * ensuite : le cloisonnement est résolu côté serveur à chaque requête, le
   * client ne l'envoie jamais.
   */
  activate: (id) => http.post(`/organizations/${id}/activate`).then(unwrap),

  /**
   * Renvoie { members, invitations, role } : la liste des membres, celle des
   * invitations en attente — vide pour un simple membre, qui n'a pas à les
   * voir — et son propre rôle, dont dépend l'affichage des commandes.
   */
  members: () =>
    http.get('/organizations/members').then((response) => ({
      members: response.data.data,
      invitations: response.data.meta?.invitations ?? [],
      role: response.data.meta?.role ?? 'member',
    })),

  updateMember: (id, role) => http.put(`/organizations/members/${id}`, { role }).then(unwrap),
  removeMember: (id) => http.delete(`/organizations/members/${id}`),
  leave: () => http.post('/organizations/leave'),

  invite: (email, role) => http.post('/organizations/invitations', { email, role }).then(unwrap),
  revokeInvitation: (id) => http.delete(`/organizations/invitations/${id}`),
}

// --- Invitations reçues ------------------------------------------------------

export const invitationsApi = {
  /** Publique : celui qui ouvre le lien n'a souvent pas encore de compte. */
  show: (token) => http.get(`/invitations/${token}`).then(unwrap),
  accept: (token) => http.post(`/invitations/${token}/accept`).then(unwrap),
}

// --- Le flux ----------------------------------------------------------------

export const streamApi = {
  /**
   * Renvoie { events, cursor, distanced, presence }.
   *
   * « depuis » est OMIS au premier appel, jamais mis à zéro : un espace neuf a
   * un curseur à 0, et confondre les deux ferait sauter son tout premier
   * événement.
   */
  poll: ({ cursor, screen, subject }, signal) =>
    http
      .get('/stream', {
        params: {
          ...(cursor === null || cursor === undefined ? {} : { depuis: cursor }),
          ecran: screen,
          ...(subject ? { sujet: subject } : {}),
        },
        signal,
      })
      .then((response) => ({
        events: response.data.data,
        cursor: response.data.meta.cursor,
        distanced: response.data.meta.distanced,
        presence: response.data.meta.presence ?? [],
      })),

  /** Départ explicite, à la fermeture de l'onglet. */
  leave: () => http.delete('/stream'),
}

// --- Tableau de bord --------------------------------------------------------

export const dashboardApi = {
  overview: () => http.get('/dashboard').then(unwrap),
}

// --- Recherche transverse ----------------------------------------------------

export const searchApi = {
  /**
   * Renvoie { results, query }.
   *
   * Le TERME est renvoyé avec les résultats, et ce n'est pas décoratif : on
   * tape plus vite que le réseau ne répond, et sans lui une réponse lente à
   * « re » écraserait celle de « refresh » déjà affichée. L'appelant compare
   * et jette ce qui est périmé.
   */
  query: (q, signal) =>
    http.get('/search', { params: { q }, signal }).then((response) => ({
      results: response.data.data,
      query: response.data.meta?.query ?? q,
    })),
}

// --- Modules ----------------------------------------------------------------

export const modulesApi = {
  list: () => http.get('/modules').then(unwrap),
  find: (slug) => http.get(`/modules/${slug}`).then(unwrap),
}

// --- Éléments d'un module ---------------------------------------------------

export const itemsApi = {
  /**
   * Renvoie { items, meta } : la pagination est nécessaire à l'affichage,
   * c'est la seule ressource où l'enveloppe complète est exposée.
   */
  list: (slug, params = {}, signal) =>
    http.get(`/modules/${slug}/items`, { params, signal }).then((response) => ({
      items: response.data.data,
      meta: response.data.meta,
    })),

  create: (slug, payload) => http.post(`/modules/${slug}/items`, payload).then(unwrap),
  update: (id, payload) => http.put(`/items/${id}`, payload).then(unwrap),
  remove: (id) => http.delete(`/items/${id}`),

  /**
   * Restauration après une suppression.
   *
   * Toutes les suppressions de l'application sont LOGIQUES : la ligne reste
   * en base, marquée. C'est ce qui rend l'annulation possible — il ne
   * manquait que ce chemin de retour.
   */
  restore: (id) => http.post(`/items/${id}/restore`).then(unwrap),
}

// --- Tickets ----------------------------------------------------------------

export const ticketsApi = {
  /**
   * Renvoie { tickets, meta }. La méta porte les indicateurs, les projets et
   * les étiquettes connus : tout l'écran se construit en un seul appel, ce
   * qui est la condition d'une interface au clavier — un filtre ne doit
   * jamais attendre le réseau.
   */
  list: (params = {}, signal) =>
    http.get('/tickets', { params, signal }).then((response) => ({
      tickets: response.data.data,
      meta: response.data.meta,
    })),

  create: (payload) => http.post('/tickets', payload).then(unwrap),

  /**
   * Mise à jour PARTIELLE : n'envoyer que les champs modifiés. C'est ce qui
   * permet à un raccourci clavier de changer une priorité sans renvoyer un
   * ticket complet, potentiellement périmé.
   */
  update: (id, payload) => http.put(`/tickets/${id}`, payload).then(unwrap),

  remove: (id) => http.delete(`/tickets/${id}`),
  restore: (id) => http.post(`/tickets/${id}/restore`).then(unwrap),
}

// --- Backend : schémas de données et clés d'API -----------------------------

export const backendApi = {
  list: (params = {}, signal) =>
    http.get('/backend/tables', { params, signal }).then((response) => ({
      tables: response.data.data,
      meta: response.data.meta,
    })),

  create: (payload) => http.post('/backend/tables', payload).then(unwrap),
  update: (id, payload) => http.put(`/backend/tables/${id}`, payload).then(unwrap),
  remove: (id) => http.delete(`/backend/tables/${id}`),

  /**
   * Renvoie { key, token, notice }. Le jeton en clair n'est transmis QU'ICI :
   * la base n'en garde que l'empreinte, il n'est pas récupérable ensuite.
   */
  createKey: (payload) => http.post('/backend/keys', payload).then(unwrap),
  revokeKey: (id) => http.delete(`/backend/keys/${id}`),
}

// --- Déploiement ------------------------------------------------------------

export const deploymentsApi = {
  list: (params = {}, signal) =>
    http.get('/deployments', { params, signal }).then((response) => ({
      deployments: response.data.data,
      meta: response.data.meta,
    })),

  create: (payload) => http.post('/deployments', payload).then(unwrap),
  update: (id, payload) => http.put(`/deployments/${id}`, payload).then(unwrap),
  remove: (id) => http.delete(`/deployments/${id}`),
  restore: (id) => http.post(`/deployments/${id}/restore`).then(unwrap),
}

// --- Supervision ------------------------------------------------------------

export const errorsApi = {
  list: (params = {}, signal) =>
    http.get('/errors', { params, signal }).then((response) => ({
      groups: response.data.data,
      meta: response.data.meta,
    })),

  find: (id) => http.get(`/errors/${id}`).then(unwrap),
  /** Seul le statut se modifie : une erreur est reçue, pas saisie. */
  setStatus: (id, status) => http.put(`/errors/${id}`, { status }).then(unwrap),
  remove: (id) => http.delete(`/errors/${id}`),
  restore: (id) => http.post(`/errors/${id}/restore`).then(unwrap),
}

// --- Design -----------------------------------------------------------------

export const designApi = {
  list: (params = {}, signal) =>
    http.get('/design/files', { params, signal }).then((response) => ({
      files: response.data.data,
      meta: response.data.meta,
    })),

  find: (id) => http.get(`/design/files/${id}`).then(unwrap),
  create: (payload) => http.post('/design/files', payload).then(unwrap),
  update: (id, payload) => http.put(`/design/files/${id}`, payload).then(unwrap),
  remove: (id) => http.delete(`/design/files/${id}`),
  restore: (id) => http.post(`/design/files/${id}/restore`).then(unwrap),
  /** Une version s'ajoute ; elle ne se modifie ni ne se supprime. */
  addVersion: (id, payload) => http.post(`/design/files/${id}/versions`, payload).then(unwrap),
}

// --- Profil -----------------------------------------------------------------

export const profileApi = {
  show: () => http.get('/profile').then(unwrap),
  update: (payload) => http.put('/profile', payload).then(unwrap),
  updatePassword: (payload) => http.put('/profile/password', payload).then(unwrap),
  destroy: (password) => http.delete('/profile', { data: { password } }),

  /**
   * Sessions ouvertes — sous « /auth » alors que l'écran est le profil.
   *
   * Le cookie de rafraîchissement est déposé avec « path=/api/auth » : sur
   * « /profile », le navigateur ne l'enverrait pas, et le serveur ne pourrait
   * plus reconnaître la session courante ni l'épargner. Cf. SessionController.
   */
  sessions: () => http.get('/auth/sessions').then(unwrap),
  revokeSession: (id) => http.delete(`/auth/sessions/${id}`),
  revokeOtherSessions: () => http.delete('/auth/sessions').then(unwrap),
}

// --- Paramètres -------------------------------------------------------------

export const settingsApi = {
  show: () => http.get('/settings').then(unwrap),
  update: (payload) => http.put('/settings', payload).then(unwrap),
}
