/**
 * Ce que les textes légaux affirment, en un seul endroit.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  À COMPLÉTER AVANT TOUTE MISE EN LIGNE PUBLIQUE                         │
 * │                                                                         │
 * │  Les mentions légales (LCEN, art. 6-III) exigent l'identité de          │
 * │  l'éditeur et celle de l'hébergeur. Elles ne s'inventent pas : tant     │
 * │  qu'un champ est vide, la page l'affiche « à compléter », en toutes     │
 * │  lettres, plutôt que de publier une identité fausse.                    │
 * └─────────────────────────────────────────────────────────────────────────┘
 */

/**
 * L'édition de bureau (desktop/) : l'application, sa base et ses fichiers
 * restent sur le poste. Plusieurs affirmations des textes légaux en changent —
 * aucun hébergeur, aucun prestataire d'envoi d'e-mails, pas de HTTPS sur une
 * adresse de bouclage. Fixée à la compilation (VITE_EDITION=bureau).
 */
export const EDITION_BUREAU = import.meta.env.VITE_EDITION === 'bureau'

/** Doit rester égale à App\Config\Terms::CURRENT_VERSION : c'est elle que l'API enregistre. */
export const TERMS_VERSION = '1.1'
export const TERMS_EFFECTIVE = '14 septembre 2026'
export const PRIVACY_UPDATED = '14 septembre 2026'

export const EDITEUR = {
  /** Personne physique ou raison sociale. */
  nom: '',
  /** « SAS au capital de 1 000 € », « entrepreneur individuel »… */
  statut: '',
  adresse: '',
  email: '',
  telephone: '',
  /** RCS ou SIREN, si l'activité est immatriculée. */
  immatriculation: '',
  directeurPublication: '',
}

export const HEBERGEUR = {
  nom: '',
  adresse: '',
  telephone: '',
}

/**
 * Les durées de conservation, telles que le worker les applique.
 *
 * Une ligne ici sans purge serait une promesse fausse : chacune correspond à
 * une tâche planifiée (migration « conformite ») et à un test
 * (tests/Integration/RetentionTest.php).
 */
export const CONSERVATION = [
  {
    donnees: 'Compte : nom, adresse e-mail, mot de passe haché, préférences, photo',
    duree: 'Jusqu’à la suppression du compte',
  },
  {
    donnees: 'Contenus des modules : tickets, commentaires, pages, déploiements, fichiers…',
    duree: 'Tant que l’espace existe ; 30 jours dans la corbeille après une suppression',
  },
  {
    donnees: 'Sessions : appareil, adresse IP, date d’ouverture',
    duree: '14 jours après leur expiration ou leur fermeture',
  },
  {
    donnees: 'Tentatives de connexion : adresse e-mail, adresse IP',
    duree: '30 jours',
  },
  {
    donnees: 'Liens de confirmation d’adresse et de réinitialisation',
    duree: '7 jours après leur usage ou leur expiration',
  },
  {
    donnees: 'Invitations : adresse e-mail de la personne invitée',
    duree: '30 jours après leur acceptation ou leur expiration',
  },
  {
    donnees: 'Historique de l’espace : qui a fait quoi, et quand',
    duree: '12 mois',
  },
  {
    donnees: 'Erreurs de production reçues : message, pile d’appels, contexte',
    duree: '90 jours',
  },
  {
    donnees: 'Relevés des sondes de disponibilité',
    duree: '30 jours',
  },
  {
    donnees: 'E-mails dont l’envoi a échoué',
    duree: '30 jours',
  },
]

/** Vrai quand le compte n'a pas accepté la version en vigueur des conditions. */
export const needsTermsAcceptance = (user) => Boolean(user) && user.terms_version !== TERMS_VERSION
