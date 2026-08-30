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
      {
        // Chemin STATIQUE déclaré avant le paramétré : vue-router retient la
        // première correspondance, « modules/tickets » ne tombe donc jamais
        // dans la vue générique.
        //
        // Ce module a son propre modèle de données (numéro, priorité
        // ordonnée, cycle de vie) et son propre écran. Les quatre autres
        // attendent le même traitement.
        path: 'modules/tickets',
        name: 'module-tickets',
        component: () => import('@/views/modules/TicketsView.vue'),
        meta: { title: 'Tickets' },
      },
      {
        // Vue générique, pour les modules qui n'ont pas encore de modèle
        // propre : structure identique, seul le slug change.
        path: 'modules/:slug',
        name: 'module',
        component: () => import('@/views/modules/ModuleView.vue'),
        props: true,
        meta: { title: 'Module' },
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
  document.title = to.meta.title ? `${to.meta.title} · SaaS App` : 'SaaS App'
})

export default router
