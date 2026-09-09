# SaaS Starter — Vue 3 / PHP 8.3 / PostgreSQL 16 / Docker

Application SaaS complète : authentification (avec vérification d'adresse et
mot de passe oublié), tableau de bord, cinq modules métier, profil et
paramètres, recherche transverse. Interface « signalétique » à colonnes :
une couleur de ligne par module, des blocs francs, un grotesque serré.
Animations anime.js, suite de tests et intégration continue.

## Les modules

Les cinq modules ont chacun leur modèle de données, leurs endpoints et leur
écran. La table générique `module_items` (titre, statut, échéance, charge
utile JSONB) demeure comme REPLI : un module ajouté en base sans code dédié
apparaît dans le menu et dispose aussitôt d'un écran, en attendant le sien.

| Module        | Tables                               | Ce que l'écran fait                                   |
| ------------- | ------------------------------------ | ----------------------------------------------------- |
| `backend`     | `backend_tables`, `backend_api_keys` | Schémas de données, colonnes typées, clés d'API       |
| `deploiement` | `deployments`                        | Déploiements Git, journaux, relance                   |
| `tickets`     | `tickets`, `ticket_counters`         | Suivi clavier-first, priorités, cycle de vie          |
| `supervision` | `error_groups`, `error_events`       | Erreurs groupées, piles d'appels, courbe sur 14 jours |
| `design`      | `design_files`, `design_versions`    | Fichiers et historique de versions                    |

Deux services font converger le tout sans que le client connaisse le métier
d'aucun module : `ModuleMetrics` décide de ce que signifie le chiffre de
chaque module (tickets ouverts, tables, erreurs non résolues…), et
`AttentionFeed` hiérarchise les alertes de tous les modules — les faits
avant les intentions.

Les invariants sont tenus par la BASE, pas par l'application : numérotation
par compte ou par fichier, dates de clôture dérivées du statut, agrégats
d'occurrences. Une insertion manuelle en psql produit une ligne aussi
correcte qu'un passage par l'API.

Le compteur affiché pour un module vient de SA source de données, assemblé
par `App\Services\ModuleMetrics` — c'est le seul endroit à modifier quand un
module passe au modèle propre.

## Démarrage

```bash
cp .env.example .env      # puis adapter les secrets
docker compose up -d --build
```

| Service        | URL                                                            |
| -------------- | -------------------------------------------------------------- |
| Client Vue     | http://localhost:5173                                          |
| API PHP        | http://localhost:8080/api                                      |
| Santé API      | http://localhost:8080/api/health                               |
| Mailpit        | http://localhost:8025 — tous les e-mails sortants              |
| Adminer (opt.) | http://localhost:8081 — `docker compose --profile tools up -d` |

**Compte de démonstration :** `demo@saas.local` / `Password123!`

## Architecture

```
App/
├── docker-compose.yml          # développement : Nginx + PHP-FPM + Worker + PostgreSQL + Vite + Mailpit
├── docker-compose.prod.yml     # déploiement  : images autonomes, même origine
├── docker/                     # images et configuration d'infrastructure
├── back/                       # API REST PHP — seul back/public/ est exposé
│   ├── public/index.php        # contrôleur frontal unique
│   ├── routes/api.php          # table de routage
│   ├── src/
│   │   ├── Config/Env.php      # accès typé aux variables d'environnement
│   │   ├── Core/               # Router, Request, Response, Database, Validator
│   │   ├── Middleware/         # Cors, Auth, Ingest (clés d'API)
│   │   ├── Services/           # Jwt, RefreshToken, UserToken, Throttle, Mailer,
│   │   │                       #   SchemaBuilder, Search, ModuleMetrics, AttentionFeed
│   │   ├── Models/             # 9 dépôts PDO (requêtes préparées)
│   │   └── Controllers/        # 15 : un par domaine, plus Health et Search
│   ├── bin/                    # migrate.php (schéma) · worker.php (tâches)
│   ├── tests/                  # PHPUnit : unit + integration
│   └── database/
│       ├── init/               # LIGNE DE BASE, jouée à la création du volume
│       ├── migrations/         # tout ce qui vient après — cf. « Le schéma »
│       └── seeds/              # jeux de données, choisis par DB_SEED
├── e2e/                        # Playwright — hors conteneur
└── front/                      # client Vue 3
    ├── tests/                  # Vitest — hors de src/
    ├── scripts/                # deux garde-fous : les lots du chemin public,
    │                           #   et le contraste de la palette
    └── src/
        ├── animations/         # motion.js (tronc commun), layout.js, reveal.js + PLAN.md
        ├── composables/        # drag, gestes, file d'écritures, raccourcis, pagination
        ├── components/board/   # BoardColumns : le tableau partagé par les cinq modules
        ├── router/ stores/ services/ layouts/ views/
        └── utils/              # format (fuseau), modules, text, tickets
```

L'API n'a **aucune dépendance tierce** : autoload PSR-4 maison, JWT signé à la
main en HS256, client SMTP écrit sur le protocole. `composer install` n'est pas
nécessaire pour démarrer.

## Endpoints

Toutes les routes sont déclarées dans [`back/routes/api.php`](back/routes/api.php).

**Ouvertes**

| Méthode | Route                       | Rôle                                   |
| ------- | --------------------------- | -------------------------------------- |
| GET     | `/api/health`               | Sonde de disponibilité                 |
| POST    | `/api/auth/register`        | Inscription (+ e-mail de confirmation) |
| POST    | `/api/auth/login`           | Connexion                              |
| POST    | `/api/auth/refresh`         | Rotation du jeton via cookie HttpOnly  |
| POST    | `/api/auth/logout`          | Révocation de la session               |
| POST    | `/api/auth/email/verify`    | Confirmation d'adresse (jeton e-mail)  |
| POST    | `/api/auth/password/forgot` | Demande de réinitialisation            |
| POST    | `/api/auth/password/reset`  | Nouveau mot de passe (jeton e-mail)    |

**Compte** — jeton d'accès requis

| Méthode | Route                    | Rôle                                |
| ------- | ------------------------ | ----------------------------------- |
| GET     | `/api/auth/me`           | Utilisateur + préférences           |
| POST    | `/api/auth/email/resend` | Renvoi du lien de confirmation      |
| GET/PUT | `/api/profile`           | Profil                              |
| PUT     | `/api/profile/password`  | Changement de mot de passe          |
| DELETE  | `/api/profile`           | Suppression du compte               |
| GET/PUT | `/api/settings`          | Préférences (thème, fuseau, langue) |
| GET     | `/api/dashboard`         | Alertes, état des modules, activité |
| GET     | `/api/search`            | Recherche dans les cinq modules     |

**Sessions ouvertes** — sous `/api/auth` alors que l'écran qui les consomme
est le profil : le cookie de rafraîchissement est déposé avec
`path=/api/auth`, et c'est lui SEUL qui permet de reconnaître la session
courante parmi les autres.

| Méthode | Route                     | Rôle                                 |
| ------- | ------------------------- | ------------------------------------ |
| GET     | `/api/auth/sessions`      | Appareils connectés, courant désigné |
| DELETE  | `/api/auth/sessions/{id}` | Fermer une session à distance        |
| DELETE  | `/api/auth/sessions`      | Fermer toutes les autres             |

**Modules** — le catalogue, et le repli générique `module_items`

| Méthode        | Route                       | Rôle                                |
| -------------- | --------------------------- | ----------------------------------- |
| GET            | `/api/modules`              | Modules accessibles, avec état      |
| GET            | `/api/modules/{slug}`       | Détail d'un module                  |
| GET/POST       | `/api/modules/{slug}/items` | Liste paginée, filtrable ; création |
| GET/PUT/DELETE | `/api/items/{id}`           | Détail, mise à jour, suppression    |

**Les cinq modèles propres** — même forme partout : liste, création, détail,
mise à jour partielle, suppression logique.

| Méthode        | Route                             | Particularité                               |
| -------------- | --------------------------------- | ------------------------------------------- |
| GET/POST       | `/api/tickets`                    | Numéro attribué par la base                 |
| GET/PUT/DELETE | `/api/tickets/{id}`               | Indicateurs, projets, étiquettes            |
| GET/POST       | `/api/backend/tables`             | Renvoie AUSSI les clés d'API (§ ci-dessous) |
| GET/PUT/DELETE | `/api/backend/tables/{id}`        | La structure suit en PostgreSQL             |
| GET/POST       | `/api/deployments`                | Empreinte de commit validée                 |
| GET/PUT/DELETE | `/api/deployments/{id}`           | Durée effacée par la base à la relance      |
| GET/POST       | `/api/errors`                     | Groupées par empreinte                      |
| GET/PUT/DELETE | `/api/errors/{id}`                | Occurrences agrégées par la base            |
| GET/POST       | `/api/design/files`               |                                             |
| GET/PUT/DELETE | `/api/design/files/{id}`          |                                             |
| POST           | `/api/design/files/{id}/versions` | Ajoute, n'écrase jamais                     |

**Clés d'API et données ingérées** — le module `backend`

| Méthode | Route                            | Auth                                   |
| ------- | -------------------------------- | -------------------------------------- |
| POST    | `/api/backend/keys`              | Session                                |
| DELETE  | `/api/backend/keys/{id}`         | Session                                |
| GET     | `/api/backend/data/{table}`      | Session **ou** clé `sk_`/`pk_`         |
| POST    | `/api/backend/data/{table}`      | Session **ou** clé de portée `service` |
| DELETE  | `/api/backend/data/{table}/{id}` | Session **ou** clé de portée `service` |

Trois choix qui se remarquent en lisant cette liste :

- **Pas de `GET /api/backend/keys`.** Les clés arrivent avec
  `GET /api/backend/tables`, dans la même réponse : l'écran affiche les deux
  ensemble, un second aller-retour n'apporterait rien.
- **Aucun `PUT` sur les données ingérées.** On peut lire, ajouter, supprimer —
  pas modifier. Une ligne ingérée est un FAIT daté, pas un brouillon ; la
  corriger effacerait ce qui a été observé.
- **Les suppressions sont logiques** — un `deleted_at` posé, jamais de ligne
  perdue. Sauf dans le module `backend`, et de façon dissymétrique : supprimer
  une table garde sa DESCRIPTION marquée effacée, mais la table PostgreSQL
  correspondante et ses lignes sont réellement détruites. Conserver des données
  devenues inatteignables coûterait de l'espace en laissant croire qu'on peut
  revenir en arrière. L'écran demande confirmation pour cette raison seule.

Réponses : `{ "data": … }` en succès (avec `meta` pour la pagination),
`{ "message": …, "errors": { champ: message } }` en erreur.

## Le schéma, et ce qui tourne en fond

### « init » est la ligne de base, « migrations » est la suite

Les fichiers de [`back/database/init`](back/database/init) sont joués par
PostgreSQL **à la création du volume**, et à ce moment-là seulement. Ils sont
donc figés : passé le premier déploiement, toute évolution du schéma passe par
[`back/database/migrations`](back/database/migrations).

```bash
docker compose exec php composer migrate          # applique ce qui est en attente
docker compose exec php composer migrate:status   # état de chaque migration
```

Les migrations sont jouées **automatiquement au démarrage du conteneur PHP**
(`DB_AUTO_MIGRATE=false` pour reprendre la main). Un fichier se nomme
`AAAAMMJJhhmm_intitulé.sql` — un horodatage plutôt qu'une séquence, pour que
deux personnes qui écrivent une migration la même semaine ne se disputent pas
le même numéro.

Trois garde-fous, chacun pour un accident classique :

| Garde-fou                     | Ce qu'il empêche                                                                                                        |
| ----------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| Verrou consultatif PostgreSQL | Deux conteneurs qui migrent au même instant                                                                             |
| Empreinte SHA-256 par fichier | Une migration modifiée **après** avoir été appliquée : elle ne sera pas rejouée, et la base diverge du dépôt en silence |
| Une transaction par migration | Un schéma à moitié transformé après un échec                                                                            |

Le troisième impose une règle : **pas de `BEGIN`/`COMMIT` dans un fichier de
migration**, le migrateur ouvrant déjà la transaction. Les rares ordres qui la
refusent — `CREATE INDEX CONCURRENTLY` — se déclarent par
`-- @sans-transaction` en tête de fichier.

### Le worker

Un second conteneur, **même image que `php`**, autre point d'entrée. Il exécute
les tâches de fond et déclenche les travaux périodiques.

```bash
docker compose logs -f worker
docker compose exec php php bin/worker.php --once   # vide la file puis s'arrête
```

**Les e-mails ont quitté la requête HTTP.** Le message était remis au serveur
SMTP _pendant_ la requête : une inscription attendait donc la messagerie —
lente, elle était lente ; muette, elle expirait. Le corps est désormais déposé
en file, et le worker le remet avec trois essais et un recul croissant.

La file vit dans PostgreSQL, sans courtier de messages : `SELECT … FOR UPDATE
SKIP LOCKED` permet à plusieurs workers d'y puiser sans jamais se prendre la
même tâche. Le contrat est **« au moins une fois »** — un worker tué entre le
travail et l'acquittement fera reprendre la tâche, et les gestionnaires
supportent d'être rejoués.

Un travail périodique est livré : la **purge des jetons de rafraîchissement**
révoqués ou expirés depuis plus de quatorze jours. La rotation en produit un à
chaque rafraîchissement ; sans purge, la table croît indéfiniment — 2 082
lignes accumulées sur une base de développement avant que ce travail n'existe.

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
`init/09_seed.sh` choisit le fichier via `DB_SEED` (`dev.sql` ou `none.sql`),
et `dev.sql` refuse en plus de s'exécuter si `APP_ENV=production`.

## Animations

**anime.js 4.5, et lui seul.** Le front en a utilisé deux — anime.js pour les
écrans publics, GSAP pour l'application — avec une frontière que rien ne
faisait respecter : un seul composant partagé important GSAP suffisait à le
ramener dans le chemin de connexion, sans erreur pour le signaler. anime.js 4.5
couvrant nativement les quatre plugins pour lesquels GSAP avait été retenu, la
frontière a été supprimée avec lui. Le détail — quel plugin, remplacé par quoi,
et les pièges de la traduction — est dans
[`front/src/animations/PLAN.md`](front/src/animations/PLAN.md).

Le moteur est servi en trois morceaux, pour que l'écran de connexion ne
télécharge que ce qu'il anime :

| Fichier                | Contenu                                    | Chargé par       |
| ---------------------- | ------------------------------------------ | ---------------- |
| `animations/motion.js` | tronc commun : durées, courbes, `settle()` | tout le monde    |
| `animations/layout.js` | `createLayout` (rejeu de mise en page)     | l'application    |
| `animations/reveal.js` | IntersectionObserver, sans dépendance      | les écrans longs |

Cette séparation n'est pas tenue à la main : `npm run check:chunks` calcule ce
que le chemin de connexion importe VRAIMENT et échoue s'il y trouve `layout`
ou `svg`. Le garde-fou a été validé en enfreignant la règle exprès.

**Deux pièges consignés**, tous deux rencontrés :

- anime.js compte en **millisecondes** là où GSAP comptait en secondes ;
- ses animations partent d'un `[depuis, vers]` explicite, donc
  `prefers-reduced-motion` doit **poser l'état final** (`settle()`) et non
  sauter l'animation — sinon l'élément reste dans son état de départ.## Déploiement

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

## Tests et qualité

Trois portes, exécutables en local exactement comme en intégration continue.

```bash
docker compose exec php composer check   # PSR-12 + PHPStan niveau 6 + PHPUnit
docker compose exec node npm run check   # ESLint + Prettier + Vitest + build + 2 garde-fous
docker compose exec php composer migrate  # applique les migrations en attente
cd e2e && npm test                       # parcours navigateur (Playwright)
```

| Suite                 | Portée                                    | Volume    |
| --------------------- | ----------------------------------------- | --------- |
| PHPUnit `unit`        | Jetons JWT — sans base                    | 7 tests   |
| PHPUnit `integration` | Routeur, middlewares, PostgreSQL réel     | 129 tests |
| Vitest                | Formatage, intercepteur HTTP, composables | 67 tests  |
| Playwright            | Parcours complets dans Chromium           | 54 tests  |

Les composables portent l'essentiel de la logique du client : file
d'écritures, glisser-déposer, raccourcis, pagination, synchronisation de
l'adresse. Ils sont testés directement, pas seulement à travers un parcours
navigateur — un délai de 1,2 seconde, un écouteur oublié au démontage ou une
absence de trente secondes se vérifient en millisecondes sous Vitest, et
coûteraient une minute chacun à Playwright.

Les tests d'intégration traversent `App\Core\Kernel` — le même point d'entrée
que `public/index.php`. Un test empruntant un chemin parallèle ne dirait rien
du comportement déployé.

Ils créent leur propre base (`saas_db_test`), recréée à chaque exécution : la
base de développement n'est jamais touchée, et aucun test n'hérite de l'état
laissé par un autre.

Playwright s'exécute **hors conteneur** — le projet ne publie pas de binaires
pour Alpine. La première fois :

```bash
cd e2e && npm install && npx playwright install chromium
```

## Direction visuelle

**« Signalétique ».** La langue des plans de transport — inventée non pour
être jolie, mais pour qu'on trouve son chemin dans une information dense,
vite, sans tout lire. C'est exactement ce que demandent cinq modules
d'exploitation.

Elle tient en quatre dispositifs, tous empruntés à la même source.

**Une couleur par ligne.** Chaque module reçoit sa teinte, comme une ligne de
métro. Elle apparaît dans le menu, dans le fil d'ariane, en grand sur
l'en-tête de l'écran, en bandeau sur sa tuile du tableau de bord, et en barre
devant chaque alerte du fil « demande attention ». Elle ne dit jamais si ça va
bien ou mal — elle dit **où on est**.

**Des blocs francs.** Aplats pleins, arêtes nettes, 3 px de rayon, aucun
dégradé. Un panneau émaillé n'a pas de profondeur : il est peint. Seul ce qui
passe réellement par-dessus le reste — fenêtres, palette de commandes — porte
une ombre, et elle est dure.

**Un grotesque serré.** Archivo, à graisse **et à chasse** variables. Les
libellés en capitales se condensent au lieu de s'allonger — ce que fait toute
signalétique quand le mot est plus long que la place. Geist Mono demeure pour
ce qui s'aligne en colonne : empreintes de commit, durées, compteurs.

**Des pictogrammes qui portent.** L'icône ne suit pas le mot, elle le devance.

### La règle qui empêche les deux vocabulaires de se marcher dessus

Une signalétique introduit cinq couleurs d'identité dans une interface qui en
avait déjà trois pour l'état. Sans règle, « violet » et « rouge » deviennent
deux mots de la même phrase et on ne sait plus lequel parle.

La règle est une règle de **forme**, pas de teinte :

- la **couleur de ligne** n'existe qu'en **bloc plein** — une barre, un pavé,
  un bandeau. Jamais du texte, jamais un point ;
- la **couleur d'état** n'existe qu'en **texte et en points**. Jamais un
  aplat, jamais une barre.

Deux formes qui ne se rencontrent pas peuvent porter la même teinte sans
jamais se confondre. Le fil « demande attention » le montre en une ligne : la
barre dit sur quelle ligne ça se passe, le point à côté dit à quel point c'est
grave.

Cette règle a un effet de bord heureux. Une couleur de ligne n'ayant jamais à
porter de texte, elle n'a plus besoin de tenir 4,5:1 — les 3:1 d'un composant
non textuel suffisent, ce qui lui laisse assez de saturation pour ressembler
enfin à une couleur de ligne. Les cinq teintes restent malgré tout cantonnées
à l'arc froid, du cyan au rose : aucune ne s'approche du vert, de l'ambre ni
du rouge. La discipline de forme est une ceinture, l'arc froid en est les
bretelles.

**L'anneau de focus, lui, n'a pas de teinte.** Dans une interface où chaque
couleur désigne une ligne ou un état, un anneau coloré prétendrait dire
quelque chose. Il prend l'encre : contraste maximal, présent partout,
signifiant nulle part.

### La couleur est la seule partie du dessin qui se mesure

`npm run check:contrast` lit les jetons de
[`main.css`](front/src/assets/css/main.css) et refuse la chaîne en dessous des
seuils. Il vérifie, pour chaque thème :

| Famille              | Contrôle                                                     |
| -------------------- | ------------------------------------------------------------ |
| encres, sémantique   | 4,5:1 sur **chacun** des fonds où la couleur se pose         |
| marques de graphique | 3:1, chroma OKLCH ≥ 0,10, ΔE ≥ 15 en vision normale          |
| marques de graphique | ΔE ≥ 8 sous protanopie, deutéranopie et tritanopie           |
| lignes de module     | 3:1 sur panneau et sur fond, et ΔE ≥ 15 entre les dix paires |

La simulation des dichromatismes emploie les matrices de Viénot, Brettel &
Mollon (1999), appliquées en RGB **linéaire** — les appliquer sur du sRGB
donnerait des couleurs plausibles et des écarts faux.

Écrit avant la refonte précédente et passé sur la palette d'alors, ce script y
a trouvé cinq manquements que deux refontes successives n'avaient pas vus : le
texte des pastilles « réussi » à 4,11:1 sur sa propre teinte, les libellés
secondaires à 4,11:1 sur les champs. Elle n'avait jamais été mesurée que
contre `panel`. Rien d'autre dans la chaîne ne pouvait le dire — ni ESLint, ni
le compilateur, ni un parcours navigateur, qui ne sait pas lire un rapport de
luminance.

## Adresse, clavier, erreurs

**Chaque écran vit dans son adresse.** Filtres, recherche, onglet, tri et
numéro de page s'y inscrivent — `?q=`, `?statut=`, `?env=`, `?type=`,
`?onglet=`, `?tri=`, `?page=` — par
[`useQuerySync`](front/src/composables/useQuerySync.js). Un lien décrit donc
ce qu'on regarde : il se partage, se met en favori, survit à un rechargement.

Deux règles rendent la chose vivable. Les valeurs par défaut n'apparaissent
JAMAIS, pour qu'un écran qu'on n'a pas touché garde une URL nue. Et l'écriture
se fait en `replace`, jamais en `push` : sans quoi une recherche tapée lettre
par lettre empilerait autant d'entrées d'historique, et « précédent » les
effacerait une à une au lieu de revenir à la page d'avant.

**Les données se rafraîchissent en revenant.**
[`useRevalidate`](front/src/composables/useRevalidate.js) relit au retour sur
l'onglet — après une absence d'au moins trente secondes — et au retour du
réseau. Pas de sondage : interroger le serveur en continu coûterait à tout le
monde pour servir un utilisateur qui, la plupart du temps, ne regarde pas. Le
rechargement est silencieux, les lignes restent à l'écran.

**Le contenu est une destination.** Un lien d'évitement ouvre la tabulation de
chaque page, et `<main>` porte le nom de l'écran. Changer d'écran y amène le
focus : dans une application d'une seule page, rien d'autre n'annonce à un
lecteur d'écran qu'on a changé de page.

**Une erreur ne disparaît plus en silence.** Quatre chemins sont couverts, pas
un seul : le code d'un composant (`app.config.errorHandler`), les promesses
rejetées que personne n'attrape, les exceptions hors de Vue, et les écrans qui
ne se chargent plus. Ce dernier cas est celui d'un onglet resté ouvert pendant
un déploiement : il réclame des morceaux de code qui n'existent plus, la
navigation échoue, et l'utilisateur clique sans que rien ne se passe. Un
rechargement le résout — tenté UNE fois, marqué en session, pour qu'un échec
d'une autre cause ne tourne pas en boucle.

## Commandes utiles

```bash
docker compose logs -f php              # journaux de l'API
docker compose exec db psql -U saas_user -d saas_db
docker compose exec node npm run build  # build de production
docker compose logs -f mailer            # e-mails interceptés (Mailpit)
docker compose down -v                  # remise à zéro complète de la base
```
