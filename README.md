# Relais — Vue 3 / PHP 8.3 / PostgreSQL 16 / Docker

Relais réunit ce qu'une équipe produit ouvre dans six onglets : tickets,
déploiements, erreurs de production, backend et fichiers de design, au même
endroit et pour toute l'équipe. Authentification (avec vérification d'adresse
et mot de passe oublié), espaces de travail partagés, tableau de bord, profil
et paramètres, recherche transverse. Interface « signalétique » : les modules
sont des lignes, chacune sa couleur, des blocs francs, un grotesque serré.
Animations anime.js, suite de tests et intégration continue.

## Les modules

Les six modules ont chacun leur modèle de données, leurs endpoints et leur
écran. La table générique `module_items` (titre, statut, échéance, charge
utile JSONB) demeure comme REPLI : un module ajouté en base sans code dédié
apparaît dans le menu et dispose aussitôt d'un écran, en attendant le sien.

| Module        | Tables                               | Ce que l'écran fait                                       |
| ------------- | ------------------------------------ | --------------------------------------------------------- |
| `backend`     | `backend_tables`, `backend_api_keys` | Schémas de données, colonnes typées, clés d'API           |
| `deploiement` | `deployments`                        | Déploiements Git, journaux, relance                       |
| `tickets`     | `tickets`, `ticket_counters`, `ticket_comments` | Suivi clavier-first, priorités, cycle de vie, assignation, discussion |
| `supervision` | `error_groups`, `error_events`       | Erreurs groupées, piles d'appels, courbe sur 14 jours     |
| `design`      | `design_files`, `design_versions`    | Maquettes téléversées, versions, aperçu sur la carte      |
| `disponibilite` | `probes`, `probe_states`, `probe_checks`, `probe_incidents` | Sondes HTTP, pannes, disponibilité sur 30 jours |
| `documentation` | `doc_pages` | Pages en arbre, recherche dans les textes, mise en forme sans HTML |

Deux services font converger le tout sans que le client connaisse le métier
d'aucun module : `ModuleMetrics` décide de ce que signifie le chiffre de
chaque module (tickets ouverts, tables, erreurs non résolues…), et
`AttentionFeed` hiérarchise les alertes de tous les modules — les faits
avant les intentions.

Les invariants sont tenus par la BASE, pas par l'application : numérotation
par espace ou par fichier, dates de clôture dérivées du statut, agrégats
d'occurrences. Une insertion manuelle en psql produit une ligne aussi
correcte qu'un passage par l'API.

### Ce qui possède les données

Un compte n'est PLUS ce qui cloisonne. Les données appartiennent à une
**organisation** — un espace de travail — et un compte y appartient par une
_membership_ qui porte son rôle. Sur les tables métier, les deux sens que
`user_id` confondait sont désormais séparés :

| Colonne           | Question                       | Effet                                    |
| ----------------- | ------------------------------ | ---------------------------------------- |
| `organization_id` | qui a le droit de voir ceci ?  | filtre TOUTE lecture et toute écriture   |
| `created_by`      | qui a écrit ceci ?             | affiché, n'ouvre et ne ferme aucun accès |

Le renommage de `user_id` était le point : un `WHERE user_id = :user_id` oublié
dans un dépôt aurait continué de filtrer, sur la mauvaise colonne, et laissé
fuir les données d'un coéquipier. Il produit maintenant une erreur SQL
immédiate.

Restent PERSONNELS, et le resteront : `user_settings`, `user_tokens`,
`refresh_tokens`. Le thème n'appartient pas à l'équipe.

Un troisième pointeur vers un compte s'ajoute sur les tickets, et il ne faut
pas le confondre avec les deux autres :

| Colonne       | Question           | Nature                      |
| ------------- | ------------------ | --------------------------- |
| `created_by`  | qui l'a ouvert ?   | passé, immuable             |
| `assigned_to` | à qui revient-il ? | présent, mouvant, filtrable |

Un ticket ouvert par Alice et confié à Bob est le cas COURANT. Les fondre
aurait fait disparaître l'un des deux faits à chaque réassignation. Seul le
module Tickets en dispose : c'est le module-patron, les quatre autres suivront
après validation.

### Le journal, source unique

Une table `activity` porte ce qui s'est passé : qui, quoi, sur quoi, et le
détail `{ champ: [avant, après] }`. **Les cinq modules y écrivent.**

Elle est LA SOURCE, pas un doublon. Le fil du tableau de bord la lit à
l'envers, l'écran Historique aussi, le flux temps réel la suit à l'endroit.
Auparavant le fil était assemblé par `UNION ALL` sur les cinq tables métier,
ce qui ne pouvait montrer que des **créations** — une table de données ne
garde aucune trace de ce qui l'a modifiée, ni de qui.

Deux choix qui ne se devinent pas :

- **Le nom de l'auteur est recopié** dans chaque entrée, contre toutes les
  habitudes de normalisation. Un journal qui se réécrit quand un compte est
  renommé ou supprimé n'est plus un journal : « Bob a supprimé la table
  clients » doit rester lisible le jour où Bob n'est plus là. Même raison
  pour le titre du sujet, figé au moment du fait.
- **Une erreur qui se répète ne consigne que sa première occurrence.** Une
  panne de production émet des centaines de fois par minute ; tout consigner
  noierait le fil de toute l'équipe sous un seul incident. Le compteur du
  groupe, lui, monte — l'information n'est pas perdue, elle est à sa place.

### Deux personnes sur le même objet

La dernière écriture gagnait, en silence : Alice tapait une description, Bob
changeait la priorité, et le second à enregistrer effaçait le travail du
premier sans que personne ne l'apprenne.

Une colonne `version` — un entier posé par un déclencheur, jamais fourni par le
client — existe sur les **cinq** tables métier. Le panneau de détail la renvoie
avec chaque champ ; les raccourcis clavier, non : ils écrivent un champ unique
et instantané, et leur imposer un aller-retour de lecture annulerait ce qui
fait l'intérêt du module.

> **Le serveur arbitre exactement quand le client annonce une version.**
> C'est la règle entière, et elle vaut pour les cinq modules.

Un cas prouve que l'arbitrage ne peut PAS reposer sur la seule comparaison de
versions : celle d'un groupe d'erreurs monte à chaque occurrence reçue, par
déclencheur. Une application en panne la ferait grimper des dizaines de fois
par minute, et un arbitrage naïf refuserait de marquer « résolu » précisément
pendant l'incident. C'est le **journal** qui tranche — quels champs QUELQU'UN
a changés — et un compteur qui monte tout seul n'y écrit rien.

**Mais une version périmée n'est PAS un conflit**, et c'est tout le sujet.
Neuf écritures concurrentes sur dix portent sur des champs différents. Le
journal dit lesquels ont bougé et par qui ; seule l'intersection avec ceux
qu'on écrit est un vrai désaccord. Le reste passe, et personne ne perd son
paragraphe.

Quand le désaccord est réel, le 409 transporte l'état courant du serveur dans
son `meta` : l'écran montre les deux versions côte à côte et laisse choisir,
au lieu du « rechargez » qui emporte ce qu'on venait d'écrire.

Conséquence à assumer : supprimer un compte ne supprime plus ses tickets. Ils
appartiennent à l'organisation, et `created_by` passe simplement à `NULL` — le
départ d'un membre ne doit pas emporter le travail de l'équipe.

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
│   │   ├── Config/             # Env (variables typées), Secrets (sous-clés dérivées)
│   │   ├── Core/               # Router, Request, Response, Database, Validator
│   │   ├── Middleware/         # Cors, Auth, Ingest (clés d'API)
│   │   ├── Services/           # Jwt, RefreshToken, UserToken, Throttle, Mailer,
│   │   │                       #   SchemaBuilder, Search, ModuleMetrics, AttentionFeed,
│   │   │                       #   Journal, Queue, RateLimiter, Scrubber, SelfMonitor,
│   │   │                       #   FileStorage, MetadataStripper, SignedUrl
│   │   ├── Models/             # 13 dépôts PDO (requêtes préparées)
│   │   └── Controllers/        # 20 : un par domaine, plus Health, Search et File
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
| GET     | `/api/invitations/{token}`  | Accueil d'un lien d'invitation         |

`GET /api/invitations/{token}` est **publique à dessein** : celui qui ouvre le
lien n'a le plus souvent pas encore de compte, et doit savoir à quoi il est
convié avant d'en créer un. Elle ne divulgue que ce que le porteur du lien sait
déjà — le nom de l'espace et l'adresse invitée.

**Compte** — jeton d'accès requis

| Méthode | Route                    | Rôle                                            |
| ------- | ------------------------ | ----------------------------------------------- |
| GET     | `/api/auth/me`           | Utilisateur + préférences                       |
| POST    | `/api/auth/email/resend` | Renvoi du lien de confirmation                  |
| GET/PUT | `/api/profile`           | Profil                                          |
| PUT     | `/api/profile/password`  | Changement de mot de passe                      |
| DELETE  | `/api/profile`           | Suppression du compte                           |
| POST    | `/api/profile/avatar`    | Photo de profil, téléversée (multipart)         |
| DELETE  | `/api/profile/avatar`    | Retrait de la photo                             |
| GET/PUT | `/api/settings`          | Préférences (thème, densité, mouvement, fuseau) |
| GET     | `/api/dashboard`         | Chiffres, ligne de production, alertes, ma journée, état des modules |
| GET     | `/api/search`            | Recherche dans les cinq modules                 |
| POST    | `/api/client-errors`     | Erreur du navigateur, rangée pour l'instance    |

**Le flux** — ce qui a changé, et qui est là.

| Méthode | Route           | Rôle                                                |
| ------- | --------------- | --------------------------------------------------- |
| GET     | `/api/stream`   | Événements depuis un curseur + présence de l'équipe |
| DELETE  | `/api/stream`   | Départ explicite, à la fermeture de l'onglet        |
| GET     | `/api/activity` | L'historique complet, filtrable et paginé par clé   |

`/api/stream` et `/api/activity` lisent **la même table**, en sens inverse :
ce que l'un a montré passer, l'autre le retrouve. L'historique est ouvert à
tous les membres, sans condition de rôle — un journal réservé aux
administrateurs servirait à surveiller plutôt qu'à se coordonner.

Trois décisions valent d'être dites, parce qu'elles ne se devinent pas :

- **Pas de SSE, et c'est un choix.** Sous PHP-FPM, un flux ouvert immobilise un
  processus enfant à vie : dix coéquipiers suffiraient à bloquer l'API entière,
  et la panne ressemblerait à une lenteur réseau. Un sondage court coûte
  quelques millisecondes toutes les trois secondes. La porte reste ouverte —
  le flux lit le JOURNAL, pas une file en mémoire.
- **Le journal est la source, pas un doublon.** Le fil d'activité le lit à
  l'envers, le flux le suit à l'endroit. Diffuser d'un côté et journaliser de
  l'autre aurait fait deux chemins qui divergent au premier oubli.
- **Le curseur ne peut pas sauter un événement.** Une séquence attribue son
  numéro à l'insertion, pas à la validation : deux écritures concurrentes
  prennent 5 et 6, et si 6 valide en premier, un lecteur naïf ne verra jamais
  le 5. La colonne `xact_id` ferme ce trou — on ne lit que les transactions
  plus anciennes que la plus vieille encore en cours.

**Espaces de travail** — c'est d'ici que vient le cloisonnement de tout le
reste. `[A]` exige le rôle `admin`, `[O]` le rôle `owner`.

| Méthode    | Route                                       | Rôle                                    |
| ---------- | ------------------------------------------- | --------------------------------------- |
| GET/POST   | `/api/organizations`                        | Ses espaces ; en créer un               |
| PUT        | `/api/organizations/{id}` `[A]`             | Renommer                                |
| DELETE     | `/api/organizations/{id}` `[O]`             | Supprimer, avec tout son contenu        |
| POST       | `/api/organizations/{id}/activate`          | **Basculer** — le seul geste qui change |
| GET        | `/api/organizations/members`                | L'équipe (visible de tous)              |
| PUT/DELETE | `/api/organizations/members/{id}` `[A]`     | Changer un rôle, exclure                |
| POST       | `/api/organizations/leave`                  | Partir de soi-même                      |
| POST       | `/api/organizations/invitations` `[A]`      | Inviter par e-mail                      |
| DELETE     | `/api/organizations/invitations/{id}` `[A]` | Révoquer une invitation                 |
| POST       | `/api/invitations/{token}/accept`           | Accepter, et basculer dessus            |

Trois invariants ne sont **pas** dans les middlewares, parce qu'ils dépendent
de la cible : un administrateur ne touche pas à un propriétaire ; on ne modifie
pas son propre rôle par ces routes ; l'espace garde toujours un propriétaire et
le compte toujours un espace. Les deux derniers ne sont vérifiés que dans
`leave()` et `destroy()` — les seuls chemins qui peuvent les rompre.

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
| POST           | `/api/design/files/{id}/versions` | Ajoute ; image jointe en multipart          |

**Disponibilité et Documentation**

| Méthode        | Route                    | Particularité                                              |
| -------------- | ------------------------ | ---------------------------------------------------------- |
| GET/POST       | `/api/probes`            | Création réservée aux administrateurs ; 25 sondes par espace |
| GET/PUT/DELETE | `/api/probes/{id}`       | Détail avec l'historique des appels                        |
| POST           | `/api/probes/{id}/check` | Avance l'échéance du worker ; 202, une fois par 30 s        |
| GET/POST       | `/api/docs`              | `?q=` cherche dans les titres ET les textes                 |
| GET/PUT/DELETE | `/api/docs/{id}`         | Fil d'Ariane ; une page ne se range pas sous elle-même     |

**Les modules se parlent**

| Méthode  | Route                                  | Rôle                                                            |
| -------- | -------------------------------------- | --------------------------------------------------------------- |
| GET/POST | `/api/tickets/{id}/comments`           | Discussion d'un ticket ; trente commentaires par minute au plus |
| DELETE   | `/api/tickets/{id}/comments/{comment}` | Par son auteur, ou un administrateur                            |
| POST     | `/api/errors/{id}/ticket`              | Ouvre le ticket d'une erreur, ou rend celui qui existe          |
| GET      | `/api/deployments/{id}/errors`         | Erreurs apparues pendant que cette version était la dernière    |

**Annuler une suppression.** Toutes les suppressions sont LOGIQUES : la ligne
reste en base, marquée. Il ne manquait que le chemin de retour — l'interface
l'offre pendant huit secondes, l'API sans limite de temps.

| Méthode | Route                            |
| ------- | -------------------------------- |
| POST    | `/api/tickets/{id}/restore`      |
| POST    | `/api/items/{id}/restore`        |
| POST    | `/api/deployments/{id}/restore`  |
| POST    | `/api/errors/{id}/restore`       |
| POST    | `/api/design/files/{id}/restore` |
| POST    | `/api/probes/{id}/restore`       |
| POST    | `/api/docs/{id}/restore`         |

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

Deux travaux périodiques tournent. La **purge des jetons de rafraîchissement**
révoqués ou expirés depuis plus de quatorze jours : la rotation en produit un à
chaque rafraîchissement, et sans purge la table croît indéfiniment — 2 082
lignes accumulées sur une base de développement avant que ce travail n'existe.
Et la **purge des compteurs de limitation de débit**, dont les fenêtres closes
depuis plus d'un jour ne comptent plus rien.

Une tâche **abandonnée** après son dernier essai reste en table avec son
erreur, et elle est signalée à la supervision de l'instance : personne ne la
réessaiera, c'est donc une panne.

## La supervision de l'instance

Le module Supervision rangeait les erreurs des applications de ses
utilisateurs, pendant que celles de l'application qui l'héberge partaient dans
`error_log` — la sortie d'un conteneur que personne ne lit. Le navigateur, lui,
écrivait les siennes dans une console que personne n'ouvre. Trois sources
arrivent désormais par le même chemin :

| Source     | Porte d'entrée                              | Ce qui est rangé                                   |
| ---------- | ------------------------------------------- | -------------------------------------------------- |
| API        | `Kernel`, toute exception non prévue        | classe, fichier, trace sans arguments, route       |
| Navigateur | `POST /api/client-errors`, depuis `main.js` | message, pile, écran, composant, version du client |
| Worker     | `Queue::fail`, au dernier essai             | type de tâche, message                             |

Elles aboutissent dans un **espace « Instance »**, que l'écran de supervision
existant affiche tel quel, avec ses statuts, sa courbe et son flux. On y entre
**par le rôle d'instance** (`users.role = 'admin'`), jamais par invitation : un
déclencheur tient l'appartenance à jour, et l'API refuse d'y inviter, d'en
exclure, de le renommer ou de le quitter. Une invitation permettrait à
n'importe quel administrateur d'équipe d'ouvrir les pannes de toute l'instance
à qui il veut. L'espace naît au premier besoin, par la fonction SQL
`instance_organization()`, et ne compte pas comme un espace de repli : un
administrateur ne peut pas supprimer sa dernière équipe en s'y croyant à
l'abri.

Trois décisions valent d'être dites :

- **La réponse 500 porte une référence** (`meta.reference`), que le client
  ajoute au message affiché. C'est ce qui relie « j'ai eu une erreur vers
  14 h » à une ligne précise.
- **Rien de personnel n'est rangé.** Les traces sont reconstruites cadre par
  cadre, sans un seul argument — celles de PHP recopient le mot de passe passé
  à `password_verify()` — et tout texte passe par `Scrubber` : adresses,
  jetons, clés d'API, IP, numéros longs. Une erreur du navigateur n'emporte ni
  compte, ni IP, ni navigateur.
- **Une rafale est comptée, pas détaillée.** Au-delà de vingt occurrences par
  minute pour une même panne, le compteur monte sans nouvelle ligne : c'est
  pendant un incident que la base a le moins de marge.

Côté navigateur, ce qui ne part PAS compte autant : ni une erreur HTTP déjà
normalisée (l'API l'a rangée, ou ce n'est pas une panne), ni deux fois la même
erreur sur le même écran, ni plus de dix signalements par page. Et un envoi
raté est avalé — sinon le filet des promesses rejetées l'attraperait, le
signalerait, échouerait encore, et bouclerait.

Un défaut de l'ingestion a été trouvé en chemin : un groupe d'erreurs
**supprimé** qui revenait restait invisible, et l'API répondait `data: null`.
Il réapparaît désormais, et son retour — comme celui d'une erreur résolue —
est consigné dans le fil.

## Les fichiers

Le module Design promettait des fichiers et n'en stockait aucun ; l'avatar
était une URL tapée à la main, et chaque affichage envoyait l'adresse IP de
toute l'équipe au site qui hébergeait l'image. Les deux se téléversent
désormais.

| Route                                   | Champ    | Accepte               | Au plus |
| --------------------------------------- | -------- | --------------------- | ------- |
| `POST /api/profile/avatar`              | `avatar` | PNG, JPEG, WebP       | 2 Mo    |
| `POST /api/design/files/{id}/versions`  | `file`   | PNG, JPEG, WebP, GIF  | 10 Mo   |
| `GET /api/files/{id}?expires&signature` | —        | lecture, sans session | —       |

Un fichier n'entre dans le volume qu'après cinq contrôles, du moins coûteux au
plus coûteux : la réception, la taille, le **type lu dans les octets** — jamais
l'extension ni ce qu'annonce le navigateur —, la structure, et les dimensions,
contre l'image de quelques kilo-octets qui en occupe des centaines une fois
décodée.

Quatre décisions :

- **Les métadonnées partent sans recompression.** Position GPS, date,
  appareil, auteur : la structure JPEG, PNG ou WebP est parcourue bloc par bloc
  et seuls les blocs de métadonnées sont omis. Les pixels ressortent à l'octet
  près, et aucune bibliothèque de décodage d'image n'entre dans l'API. Ce qui
  suit la fin déclarée d'une image est coupé.
- **Ni SVG, ni PDF.** Un SVG peut porter du script, qui s'exécuterait depuis
  l'origine de l'application ; un PDF garde le nom de son auteur dans des
  propriétés qu'on ne sait pas retirer sans le réécrire.
- **Des adresses signées plutôt qu'une session.** Une balise `<img>` n'envoie
  pas d'en-tête d'autorisation. L'API remet donc une adresse signée (HMAC,
  valable une à deux heures) à qui a le droit de voir le fichier, arrondie à
  l'heure pour que le navigateur la garde en cache. Le fichier part avec
  `nosniff`, une CSP `default-src 'none'; sandbox` et aucun référent.
- **Un déclencheur, et non du code, pour l'effacement.** Supprimer un compte
  ou un espace passe par des cascades où aucun PHP ne s'exécute. Chaque
  suppression de fichier pose donc une « pierre tombale » en base, quel que
  soit son chemin, et le worker efface les octets ensuite. Une photo
  remplacée, retirée, ou celle d'un compte effacé ne reste pas sur le disque.

Côté navigateur, la photo est recadrée au carré par le canevas avant l'envoi,
ce qui ne transmet déjà plus aucune métadonnée — le serveur les retire malgré
tout, puisque le client n'est pas une barrière.

Les octets vivent dans le volume `storage_data`, hors de la racine web ; la base
garde ce qui se requête (`stored_files`). Chaque espace dispose de
`STORAGE_QUOTA_BYTES` octets, un gigaoctet par défaut. **Sauvegardez le volume
avec la base** : l'une décrit les fichiers, l'autre les contient.

## Sécurité

- **Mots de passe** : bcrypt coût 12, réhachage transparent si le coût évolue.
  Un nouveau mot de passe doit valoir **80 bits au sens de la CNIL**
  (délibération n° 2022-100 : longueur × log2 de l'alphabet employé), mesurés
  sur les 72 octets que bcrypt lit réellement (`App\Core\PasswordPolicy`, copiée
  côté client pour l'indicateur). « Motdepasse1 » est refusé, « cheval batterie
  agrafe » accepté. La connexion n'applique pas la règle : un compte plus ancien
  continue d'ouvrir sa session.
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
  réinitialisation, 3 renvois de confirmation par quart d'heure, 20 inscriptions
  par heure. Pour le reste, `RateLimiter` compte des appels par compte, IP ou
  clé, en fenêtre fixe ; la clé n'est stockée qu'en **empreinte HMAC**, jamais
  en clair.
- **L'adresse IP ne se déclare pas** : `X-Forwarded-For` n'est lu que s'il
  vient d'un intermédiaire listé dans `TRUSTED_PROXIES`, et de droite à gauche
  (`App\Core\ClientIp`). Lu sans condition, il laissait chaque requête choisir
  son adresse, donc rouvrir le verrou par IP à chaque tentative.
- **Traces d'exception sans arguments** : `zend.exception_ignore_args` est
  activé en développement comme en production, et la supervision reconstruit
  ses propres traces sans eux.
- **SQL** : PDO en requêtes réellement préparées (`EMULATE_PREPARES` désactivé),
  `ORDER BY` restreint à une liste blanche, jokers `LIKE` échappés.
- **Cloisonnement** : chaque requête est filtrée par `organization_id`, jamais
  par le compte. Cette valeur est résolue par `AuthMiddleware` à chaque requête
  depuis l'appartenance en base, et **le client ne l'envoie jamais** : il ne
  peut donc pas la falsifier. Le seul geste qui la déplace est
  `POST /api/organizations/{id}/activate`, qui vérifie l'appartenance avant
  d'écrire.
- **Rôles** : `owner`, `admin`, `member` dans l'organisation — distincts de
  `users.role`, qui reste l'administration de l'instance. Ils sont appliqués
  par `RequireAdmin` / `RequireOwner` sur les routes, et relus en base à chaque
  requête : une rétrogradation prend effet immédiatement, sans attendre
  l'expiration d'un jeton.
- **Jetons d'invitation** : mêmes règles que les jetons e-mail — 32 octets
  aléatoires, stockés hachés, usage unique, 7 jours. Réinviter la même adresse
  invalide le lien précédent.
- **En-têtes** : `nosniff`, `X-Frame-Options: DENY`, `Permissions-Policy`,
  COOP, CORP, HSTS, et en déploiement une CSP **sans
  `'unsafe-inline'` ni origine tierce** : un script ou un style glissé dans la
  page par une injection n'est pas exécuté. `npm run check:csp` vérifie la
  compilation, `e2e/tests/csp.spec.js` un navigateur sous l'image de production.
- **Aucun tiers au chargement** : les polices sont servies par l'application.
  Chargées depuis Google, elles lui transmettaient l'adresse IP de chaque
  visiteur avant tout consentement.
- **Fichiers téléversés** : type lu dans les octets, métadonnées retirées, ni
  SVG ni PDF, servis par adresse signée avec `nosniff` et une CSP `sandbox` ;
  les octets quittent le disque avec leur description (cf. « Les fichiers »).
- **Sondes du module Disponibilité** : une sonde est une requête que le
  serveur émet pour le compte d'un espace — la porte d'une falsification de
  requête côté serveur (SSRF). `App\Services\UrlGuard` n'accepte que http et
  https, sans identifiants, et refuse toute adresse privée, locale ou réservée
  (métadonnées des clouds comprises) ; le nom est RÉSOLU PUIS ÉPINGLÉ à chaque
  appel (`CURLOPT_RESOLVE`), pour qu'un DNS malveillant ne redirige pas la
  connexion après la vérification. Aucune redirection suivie, 64 Ko lus au
  plus, dix secondes au plus ; créer ou régler une sonde est réservé aux
  administrateurs, et « vérifier maintenant » n'émet rien dans la requête :
  il avance l'échéance du worker.
- **Pages de Documentation** : le texte est stocké tel qu'il a été écrit et
  n'est JAMAIS converti en HTML — ni par l'API, ni dans le navigateur. Le
  client le découpe en titres, listes et blocs de code et l'affiche comme du
  texte, sans `v-html` ; un lien n'est cliquable que vers http, https, mailto
  ou un chemin de l'application. `<script>` écrit dans une page s'affiche
  `<script>`, et `e2e/tests/documentation.spec.js` le vérifie dans un vrai
  navigateur.
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
  sauter l'animation — sinon l'élément reste dans son état de départ.

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
- un **worker** tourne sur la même image que l'API. Il manquait jusqu'ici :
  aucun e-mail ne partait en production, et ni les jetons ni les fichiers
  supprimés n'étaient purgés ;
- les fichiers téléversés vivent dans le volume `saas_storage_data_prod`, à
  sauvegarder avec la base ;
- `display_errors=Off`, OPcache figé, en-têtes CSP ;
- les variables sensibles sont **obligatoires** : la stack refuse de démarrer
  si elles manquent.

⚠ Le conteneur web écoute en HTTP : placez-le derrière une terminaison TLS.
Sans HTTPS, le cookie de session marqué `Secure` ne sera pas transmis.

⚠ Renseignez alors `TRUSTED_PROXIES` avec l'adresse de cette terminaison.
Sans elle, l'API voit tous les visiteurs arriver de la même adresse : vingt
mots de passe erronés, de qui que ce soit, fermeraient la connexion à tout le
monde pour un quart d'heure.

## Tests et qualité

Trois portes, exécutables en local exactement comme en intégration continue.

```bash
docker compose exec php composer check   # PSR-12 + PHPStan niveau 6 + PHPUnit
docker compose exec node npm run check   # ESLint + Prettier + Vitest + build + 3 garde-fous
docker compose exec php composer migrate  # applique les migrations en attente
cd e2e && npm test                       # parcours navigateur (Playwright)
```

| Suite                 | Portée                                          | Volume    |
| --------------------- | ----------------------------------------------- | --------- |
| PHPUnit `unit`        | Jetons, traces, images, IP, mots de passe       | 70 tests  |
| PHPUnit `integration` | Routeur, middlewares, PostgreSQL réel           | 251 tests |
| Vitest                | Formatage, client HTTP, composables, signaleur  | 179 tests |
| Playwright            | Parcours complets dans Chromium                 | 79 tests  |

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

## Réglages, et ce qu’ils changent

Cet écran a déjà **perdu** des réglages : trois interrupteurs de notification
et un choix « English » en ont été retirés parce qu'ils étaient enregistrés en
base et consommés par personne. Un réglage qui ne change rien fait croire à un
contrôle qui n'existe pas.

Chacun de ceux qui restent agit, et un parcours navigateur le vérifie par son
**effet** — pas par la case cochée.

| Réglage                    | Ce qu’il change                                                    | Où il vit    |
| -------------------------- | ------------------------------------------------------------------ | ------------ |
| Thème                      | clair / sombre / système                                           | compte       |
| **Densité**                | la taille de base, dont dépendent toutes les mesures en `rem`      | compte       |
| **Réduire les animations** | coupe transitions CSS et animations JS, en plus du réglage système | compte       |
| Fuseau horaire             | toutes les dates et échéances                                      | compte       |
| **Retours sonores**        | survols, clics, notifications                                      | **appareil** |

Le son reste sur l'appareil, et c'est délibéré : l'écran d'entrée sonore est
proposé AVANT toute connexion, et le stocker par compte le rendrait
indisponible au moment précis où il est demandé. Ce qui lui manquait n'était
pas la persistance mais la PLACE — son seul interrupteur vivait dans la barre
du haut.

Densité et mouvement sont **mis en miroir dans `localStorage`** et relus par le
script d'avant-rendu de `index.html`, comme le thème. Sans cette avance, la
page s'affichait à la densité par défaut puis sautait — et jouait ses
animations d'entrée alors même qu'on venait de les couper. Le serveur reste la
source de vérité ; le miroir est corrigé dès sa réponse.

## Adresse, clavier, erreurs

**Chaque écran vit dans son adresse.** Filtres, recherche, onglet, tri et
numéro de page s'y inscrivent — `?q=`, `?statut=`, `?assigne=`, `?env=`,
`?type=`, `?onglet=`, `?tri=`, `?page=` — par
[`useQuerySync`](front/src/composables/useQuerySync.js). Un lien décrit donc
ce qu'on regarde : il se partage, se met en favori, survit à un rechargement.

Deux règles rendent la chose vivable. Les valeurs par défaut n'apparaissent
JAMAIS, pour qu'un écran qu'on n'a pas touché garde une URL nue. Et l'écriture
se fait en `replace`, jamais en `push` : sans quoi une recherche tapée lettre
par lettre empilerait autant d'entrées d'historique, et « précédent » les
effacerait une à une au lieu de revenir à la page d'avant.

`?assigne=moi` porte un mot et non un identifiant, et ce n'est pas de la
commodité : un lien « mes tickets » envoyé à un collègue lui montre les SIENS.
Avec l'identifiant en clair, il aurait vu les vôtres en croyant regarder les
siens — une adresse partageable doit rester vraie chez son destinataire.

**Au-delà du plafond, les filtres interrogent le serveur.** Les écrans chargent
leurs lignes d'un bloc, avec un plafond — 500 tickets —, pour que la recherche
et les filtres restent locaux et instantanés. Au-delà, une recherche ne voyait
que ce qui était chargé, et l'avertissement conseillait même de « chercher par
numéro, par projet ou par étiquette pour atteindre le reste » : aucune recherche
ne le faisait, et celle du serveur ignorait justement ces trois champs.

[`useServerFilters`](front/src/composables/useServerFilters.js) tient désormais
la règle, et sa première moitié compte autant que la seconde : tant que la liste
de départ est complète, rien ne part ; dès qu'elle déborde, un filtre actif
interroge le serveur, qui cherche exactement là où l'écran cherche. Une frappe
ne vaut pas une requête, une réponse lente n'écrase jamais une plus récente, et
le retour sur l'onglet, le flux temps réel ou une restauration relisent dans le
mode courant plutôt que de remplacer une recherche par la liste brute. Construit
sur Tickets, le module-patron ; Déploiement, Supervision et Design le
recevront à leur tour — leur recherche serveur cherche déjà là où cherche leur
écran.

**Les données se rafraîchissent en revenant.**
[`useRevalidate`](front/src/composables/useRevalidate.js) relit au retour sur
l'onglet — après une absence d'au moins trente secondes — et au retour du
réseau. Le rechargement est silencieux, les lignes restent à l'écran.

**Et elles bougent pendant qu'on regarde.**
[`useLiveStream`](front/src/composables/useLiveStream.js) suit le journal
d'activité toutes les trois secondes, et **seulement pendant que l'onglet est
visible**. Les deux se complètent : le flux suit les changements au fil de
l'eau, `useRevalidate` relit tout au retour d'une absence, quand rattraper
une heure d'événements un par un ferait clignoter l'écran plus longtemps
qu'une relecture franche.

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
