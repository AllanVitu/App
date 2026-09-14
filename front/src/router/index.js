import { createRouter, createWebHistory } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

/**
 * Table de routage du client.
 *
 * Toutes les vues sauf l'authentification sont chargées en différé
 * (import dynamique) : le bundle initial ne contient que ce qui est
 * nécessaire à l'écran de connexion.
 */
const routes = [
  {
    path: '/',
    component: () => import('@/layouts/AppLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'dashboard',
        component: () => import('@/views/DashboardView.vue'),
        meta: { title: 'Tableau de bord' },
      },
      // Chemins STATIQUES déclarés AVANT le paramétré : vue-router retient la
      // première correspondance, ces cinq slugs ne tombent donc jamais dans la
      // vue générique. Chacun a son propre modèle de données et son écran.
      {
        path: 'modules/backend',
        name: 'module-backend',
        component: () => import('@/views/modules/BackendView.vue'),
        meta: { title: 'Backend' },
      },
      {
        path: 'modules/deploiement',
        name: 'module-deploiement',
        component: () => import('@/views/modules/DeploymentsView.vue'),
        meta: { title: 'Déploiement' },
      },
      {
        path: 'modules/tickets',
        name: 'module-tickets',
        component: () => import('@/views/modules/TicketsView.vue'),
        meta: { title: 'Tickets' },
      },
      {
        path: 'modules/supervision',
        name: 'module-supervision',
        component: () => import('@/views/modules/SupervisionView.vue'),
        meta: { title: 'Supervision' },
      },
      {
        path: 'modules/design',
        name: 'module-design',
        component: () => import('@/views/modules/DesignView.vue'),
        meta: { title: 'Design' },
      },
      {
        path: 'modules/disponibilite',
        name: 'module-disponibilite',
        component: () => import('@/views/modules/AvailabilityView.vue'),
        meta: { title: 'Disponibilité' },
      },
      {
        path: 'modules/documentation',
        name: 'module-documentation',
        component: () => import('@/views/modules/DocumentationView.vue'),
        meta: { title: 'Documentation' },
      },
      {
        // Repli pour un module ajouté EN BASE sans écran dédié : le catalogue
        // le fait apparaître dans le menu, et cette vue générique lui donne
        // de quoi exister (titre, statut, échéance) en attendant le sien.
        path: 'modules/:slug',
        name: 'module',
        component: () => import('@/views/modules/ModuleView.vue'),
        props: true,
        meta: { title: 'Module' },
      },
      {
        path: 'equipe',
        name: 'team',
        component: () => import('@/views/TeamView.vue'),
        meta: { title: 'Équipe' },
      },
      {
        path: 'historique',
        name: 'history',
        component: () => import('@/views/HistoryView.vue'),
        meta: { title: 'Historique' },
      },
      {
        path: 'profil',
        name: 'profile',
        component: () => import('@/views/ProfileView.vue'),
        meta: { title: 'Profil' },
      },
      {
        path: 'parametres',
        name: 'settings',
        component: () => import('@/views/SettingsView.vue'),
        meta: { title: 'Paramètres' },
      },
    ],
  },

  {
    path: '/',
    component: () => import('@/layouts/AuthLayout.vue'),
    meta: { guestOnly: true },
    children: [
      {
        path: 'connexion',
        name: 'login',
        component: () => import('@/views/auth/LoginView.vue'),
        meta: { title: 'Connexion', silent: true },
      },
      {
        path: 'inscription',
        name: 'register',
        component: () => import('@/views/auth/RegisterView.vue'),
        meta: { title: 'Inscription', silent: true },
      },
      {
        path: 'mot-de-passe-oublie',
        name: 'forgot-password',
        component: () => import('@/views/auth/ForgotPasswordView.vue'),
        meta: { title: 'Mot de passe oublié', silent: true },
      },
    ],
  },

  {
    // Parcours ouverts par un lien reçu par e-mail : accessibles connecté
    // comme déconnecté. Les placer sous « guestOnly » renverrait un
    // utilisateur déjà connecté vers le tableau de bord sans rien traiter.
    path: '/',
    component: () => import('@/layouts/AuthLayout.vue'),
    children: [
      {
        path: 'reinitialisation',
        name: 'reset-password',
        component: () => import('@/views/auth/ResetPasswordView.vue'),
        meta: { title: 'Nouveau mot de passe', silent: true },
      },
      {
        path: 'verification-email',
        name: 'verify-email',
        component: () => import('@/views/auth/VerifyEmailView.vue'),
        meta: { title: "Confirmation de l'adresse", silent: true },
      },
      {
        // Une invitation s'ouvre le plus souvent SANS compte : c'est même le
        // cas courant. Elle appartient donc à ce groupe, ni « guestOnly » ni
        // « requiresAuth ».
        path: 'invitation',
        name: 'invitation',
        component: () => import('@/views/auth/InvitationView.vue'),
        meta: { title: 'Invitation', silent: true },
      },
    ],
  },

  {
    // Hors layout et sans garde : on ne peut pas demander d'accepter un texte
    // qu'il faudrait un compte pour lire.
    path: '/conditions',
    name: 'terms',
    component: () => import('@/views/TermsView.vue'),
    meta: { title: 'Conditions générales', silent: true },
  },
  {
    path: '/confidentialite',
    name: 'privacy',
    component: () => import('@/views/legal/PrivacyView.vue'),
    meta: { title: 'Confidentialité', silent: true },
  },
  {
    path: '/mentions-legales',
    name: 'legal',
    component: () => import('@/views/legal/LegalNoticeView.vue'),
    meta: { title: 'Mentions légales', silent: true },
  },

  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/views/NotFoundView.vue'),
    meta: { title: 'Page introuvable' },
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: (to, from, saved) => saved ?? { top: 0 },
})

/**
 * Garde globale.
 *
 * Elle attend que la session soit restaurée (appel à /auth/refresh) avant de
 * trancher : sans cela, un rechargement sur une page protégée renverrait
 * l'utilisateur vers la connexion alors que sa session est valide.
 */
router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (!auth.ready) {
    await auth.initialize()
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    // La destination est mémorisée pour y revenir après connexion.
    return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} }
  }

  if (to.meta.guestOnly && auth.isAuthenticated) {
    return { name: 'dashboard' }
  }

  return true
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} · Relais` : 'Relais'
})

export default router
