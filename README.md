# SaaS Starter — Vue 3 / PHP 8.3 / PostgreSQL 16 / Docker

Application SaaS complète : authentification (avec vérification d'adresse et
mot de passe oublié), tableau de bord, quatre modules métier, profil et
paramètres. Animations GSAP et fond WebGL.

## Démarrage

```bash
cp .env.example .env      # puis adapter les secrets
docker compose up -d --build
```

| Service        | URL                                                              |
|----------------|------------------------------------------------------------------|
| Client Vue     | http://localhost:5173                                            |
| API PHP        | http://localhost:8080/api                                        |
| Santé API      | http://localhost:8080/api/health                                 |
| Mailpit        | http://localhost:8025 — tous les e-mails sortants                |
| Adminer (opt.) | http://localhost:8081 — `docker compose --profile tools up -d`   |

**Compte de démonstration :** `demo@saas.local` / `Password123!`

## Architecture

```
App/
├── docker-compose.yml          # développement : Nginx + PHP-FPM + PostgreSQL + Vite + Mailpit
├── docker-compose.prod.yml     # déploiement  : images autonomes, même origine
├── docker/                     # images et configuration d'infrastructure
├── back/                       # API REST PHP — seul back/public/ est exposé
│   ├── public/index.php        # contrôleur frontal unique
│   ├── routes/api.php          # table de routage
│   ├── src/
│   │   ├── Config/Env.php      # accès typé aux variables d'environnement
│   │   ├── Core/               # Router, Request, Response, Database, Validator
│   │   ├── Middleware/         # CorsMiddleware, AuthMiddleware
│   │   ├── Services/           # Jwt, RefreshToken, UserToken, Throttle, Mailer
│   │   ├── Models/             # dépôts PDO (requêtes préparées)
│   │   └── Controllers/        # Auth, Account, Dashboard, Module, Item, Profile, Settings
│   └── database/
│       ├── init/               # schéma, joué à la création du volume
│       └── seeds/              # jeux de données, choisis par DB_SEED
└── front/                      # client Vue 3
    └── src/
        ├── animations/         # gsap.js (plugins, courbes) + PLAN.md
        ├── composables/        # useGsap : équivalent Vue de useGSAP
        ├── router/ stores/ services/ layouts/ components/ views/
        └── utils/format.js
```

L'API n'a **aucune dépendance tierce** : autoload PSR-4 maison, JWT signé à la
main en HS256, client SMTP écrit sur le protocole. `composer install` n'est pas
nécessaire pour démarrer.

## Endpoints

| Méthode | Route                         | Auth | Rôle                                   |
|---------|-------------------------------|:----:|----------------------------------------|
| GET     | `/api/health`                 |      | Sonde de disponibilité                 |
| POST    | `/api/auth/register`          |      | Inscription (+ e-mail de confirmation) |
| POST    | `/api/auth/login`             |      | Connexion                              |
| POST    | `/api/auth/refresh`           |      | Rotation du jeton via cookie HttpOnly  |
| POST    | `/api/auth/logout`            |      | Révocation de la session               |
| POST    | `/api/auth/email/verify`      |      | Confirmation d'adresse (jeton e-mail)  |
| POST    | `/api/auth/password/forgot`   |      | Demande de réinitialisation            |
| POST    | `/api/auth/password/reset`    |      | Nouveau mot de passe (jeton e-mail)    |
| POST    | `/api/auth/email/resend`      |  ✓   | Renvoi du lien de confirmation         |
| GET     | `/api/auth/me`                |  ✓   | Utilisateur + préférences              |
| GET     | `/api/dashboard`              |  ✓   | Indicateurs, modules, activité récente |
| GET     | `/api/modules`                |  ✓   | Modules accessibles                    |
| GET     | `/api/modules/{slug}`         |  ✓   | Détail d'un module                     |
| GET     | `/api/modules/{slug}/items`   |  ✓   | Liste paginée, filtrable, triable      |
| POST    | `/api/modules/{slug}/items`   |  ✓   | Création                               |
| GET     | `/api/items/{id}`             |  ✓   | Détail                                 |
| PUT     | `/api/items/{id}`             |  ✓   | Mise à jour partielle                  |
| DELETE  | `/api/items/{id}`             |  ✓   | Suppression logique                    |
| GET/PUT | `/api/profile`                |  ✓   | Profil                                 |
| PUT     | `/api/profile/password`       |  ✓   | Changement de mot de passe             |
| DELETE  | `/api/profile`                |  ✓   | Suppression du compte                  |
| GET/PUT | `/api/settings`               |  ✓   | Préférences                            |

Réponses : `{ "data": … }` en succès (avec `meta` pour la pagination),
`{ "message": …, "errors": { champ: message } }` en erreur.

## Sécurité

- **Mots de passe** : bcrypt coût 12, réhachage transparent si le coût évolue.
- **Jetons de session** : JWT HS256 de 15 min (algorithme imposé côté serveur,
  signature comparée en temps constant) + jeton de rafraîchissement opaque de
  14 j, stocké **haché en SHA-256** et transmis par cookie **HttpOnly**, avec
  rotation à chaque usage. Le jeton d'accès ne touche jamais `localStorage`.
- **Jetons e-mail** (confirmation, réinitialisation) : valeur aléatoire de
  32 octets, stockée hachée, usage unique, 24 h / 1 h de validité. Une nouvelle
  demande invalide la précédente.
- **Pas d'énumération de comptes** : « mot de passe oublié » répond la même
  chose pour une adresse connue ou non ; la connexion utilise un hachage
  factice pour aligner les temps de réponse.
- **Limitation de débit** par e-mail ET par IP : 5 connexions, 3 demandes de
  réinitialisation, 3 renvois de confirmation par quart d'heure.
- **SQL** : PDO en requêtes réellement préparées (`EMULATE_PREPARES` désactivé),
  `ORDER BY` restreint à une liste blanche, jokers `LIKE` échappés.
- **Cloisonnement** : chaque requête est filtrée par `user_id`.
- **En-têtes** : `nosniff`, `X-Frame-Options: DENY`, CSP en déploiement.
- **Notification hors bande** à chaque changement de mot de passe.

### Jeu de données de démonstration

Le compte de démo vit dans `back/database/seeds/dev.sql`, **hors** du dossier
d'initialisation : rien n'est chargé du seul fait de sa présence dans le dépôt.
`init/03_seed.sh` choisit le fichier via `DB_SEED` (`dev.sql` ou `none.sql`),
et `dev.sql` refuse en plus de s'exécuter si `APP_ENV=production`.

## Animations

GSAP 3.15, tous les plugins sous licence gratuite. Le plan complet — quel
plugin, à quel endroit, et pourquoi certains sont volontairement écartés — est
dans [`front/src/animations/PLAN.md`](front/src/animations/PLAN.md).

Points saillants : `Flip` pour le rejeu de mise en page au filtrage,
`MorphSVG` pour la bascule de thème, `SplitText` pour les titres, `Draggable`
+ `Inertia` pour le tiroir mobile, `DrawSVG` + `Physics2D` pour les écrans de
confirmation. `prefers-reduced-motion` est respecté globalement.

`@gsap/react` n'est pas utilisable en Vue : l'équivalent est le composable
[`useGsap`](front/src/composables/useGsap.js).

Le fond animé [`ShaderBackground.vue`](front/src/components/ShaderBackground.vue)
reproduit **ShaderGradient** en WebGL natif — le paquet officiel exige React et
react-three-fiber.

**Coût mesuré** : GSAP pèse 236 Ko (92 Ko gzip), isolé dans son propre chunk.
C'est plus que Vue + Router + Pinia + axios réunis. Retirer un plugin =
supprimer son import dans `animations/gsap.js`.

## Déploiement

```bash
cp .env.production.example .env.production   # renseigner les variables
docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build
```

Différences avec la stack de développement :

- code PHP et client compilé **embarqués dans les images** (aucun bind mount) ;
- Nginx sert `front/dist` **et** relaie `/api` vers PHP-FPM : même origine,
  donc plus de CORS et cookie `SameSite=Strict` ;
- PostgreSQL n'est pas publié sur l'hôte, `DB_SEED=none.sql` ;
- `display_errors=Off`, OPcache figé, en-têtes CSP ;
- les variables sensibles sont **obligatoires** : la stack refuse de démarrer
  si elles manquent.

⚠ Le conteneur web écoute en HTTP : placez-le derrière une terminaison TLS.
Sans HTTPS, le cookie de session marqué `Secure` ne sera pas transmis.

## Commandes utiles

```bash
docker compose logs -f php              # journaux de l'API
docker compose exec db psql -U saas_user -d saas_db
docker compose exec node npm run build  # build de production
docker compose down -v                  # remise à zéro complète de la base
```
