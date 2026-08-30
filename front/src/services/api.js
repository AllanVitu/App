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

// --- Tableau de bord --------------------------------------------------------

export const dashboardApi = {
  overview: () => http.get('/dashboard').then(unwrap),
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
  /** Une version s'ajoute ; elle ne se modifie ni ne se supprime. */
  addVersion: (id, payload) => http.post(`/design/files/${id}/versions`, payload).then(unwrap),
}

// --- Profil -----------------------------------------------------------------

export const profileApi = {
  show: () => http.get('/profile').then(unwrap),
  update: (payload) => http.put('/profile', payload).then(unwrap),
  updatePassword: (payload) => http.put('/profile/password', payload).then(unwrap),
  destroy: (password) => http.delete('/profile', { data: { password } }),
}

// --- Paramètres -------------------------------------------------------------

export const settingsApi = {
  show: () => http.get('/settings').then(unwrap),
  update: (payload) => http.put('/settings', payload).then(unwrap),
}
