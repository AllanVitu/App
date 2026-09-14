<?php

declare(strict_types=1);

/**
 * ---------------------------------------------------------------------------
 * Table de routage de l'API
 *
 * Vue d'ensemble des endpoints exposés. Les routes marquées [AuthMiddleware]
 * exigent un en-tête « Authorization: Bearer <access_token> ».
 * ---------------------------------------------------------------------------
 */

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\BackendController;
use App\Controllers\ClientErrorController;
use App\Controllers\DashboardController;
use App\Controllers\DataController;
use App\Controllers\DeploymentController;
use App\Controllers\DesignController;
use App\Controllers\DocController;
use App\Controllers\ErrorController;
use App\Controllers\FileController;
use App\Controllers\HealthController;
use App\Controllers\ItemController;
use App\Controllers\ModuleController;
use App\Controllers\OrganizationController;
use App\Controllers\ProbeController;
use App\Controllers\ProfileController;
use App\Controllers\SearchController;
use App\Controllers\SessionController;
use App\Controllers\SettingsController;
use App\Controllers\StreamController;
use App\Controllers\TicketController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\IngestMiddleware;
use App\Middleware\RequireAdmin;
use App\Middleware\RequireOwner;

$router = new Router();

$auth = [AuthMiddleware::class];

// Rôle DANS L'ORGANISATION, à ne pas confondre avec « users.role », qui reste
// l'administration de l'instance. Les gardes s'empilent après AuthMiddleware :
// c'est lui qui pose l'organisation dont elles lisent le rôle.
$admin = [AuthMiddleware::class, RequireAdmin::class];
$owner = [AuthMiddleware::class, RequireOwner::class];

// Ingestion : accepte AUSSI une clé d'API de service, parce qu'une
// application qui signale une erreur ne peut pas détenir de session
// utilisateur (cf. IngestMiddleware).
$ingest = [IngestMiddleware::class];

// --- Public ----------------------------------------------------------------
$router->get('/api/health', [HealthController::class, 'index']);

$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/refresh', [AuthController::class, 'refresh']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);

// Confirmation d'adresse et mot de passe oublié : le jeton reçu par e-mail
// tient lieu d'authentification, ces routes sont donc publiques.
$router->post('/api/auth/email/verify', [AccountController::class, 'verifyEmail']);
$router->post('/api/auth/password/forgot', [AccountController::class, 'forgotPassword']);
$router->post('/api/auth/password/reset', [AccountController::class, 'resetPassword']);

// --- Session ---------------------------------------------------------------
$router->get('/api/auth/me', [AuthController::class, 'me'], $auth);

$router->post('/api/auth/email/resend', [AccountController::class, 'resendVerification'], $auth);

// Sessions ouvertes : lister ses appareils, en fermer un à distance.
//
// Sous « /api/auth » alors que l'écran qui les consomme est le PROFIL. Le
// cookie de rafraîchissement est déposé avec « path=/api/auth » : ailleurs le
// navigateur ne l'enverrait pas, et c'est lui SEUL qui permet de reconnaître
// la session courante parmi les autres. Cf. SessionController.
$router->get('/api/auth/sessions', [SessionController::class, 'index'], $auth);
$router->delete('/api/auth/sessions', [SessionController::class, 'destroyOthers'], $auth);
$router->delete('/api/auth/sessions/{id}', [SessionController::class, 'destroy'], $auth);

// --- Espaces de travail -----------------------------------------------------
//
// LE CLOISONNEMENT DE TOUTE L'API TIENT À CES ROUTES. Les autres reçoivent leur
// « organization_id » d'AuthMiddleware et ne peuvent pas en changer ; ici seul
// « activate » le déplace, après avoir vérifié l'appartenance.
//
// Les rôles apparaissent enfin dans la pile de middlewares : $admin pour ce qui
// gère l'équipe, $owner pour ce qui est définitif. Ce qu'ils ne savent pas
// dire — « pas le dernier propriétaire », « pas soi-même » — dépend de la
// cible et se vérifie dans le contrôleur.
$router->get('/api/organizations', [OrganizationController::class, 'index'], $auth);
$router->post('/api/organizations', [OrganizationController::class, 'store'], $auth);

// Avant « /{id} », qui capturerait « members » comme identifiant.
$router->get('/api/organizations/members', [OrganizationController::class, 'members'], $auth);
$router->put('/api/organizations/members/{id}', [OrganizationController::class, 'updateMember'], $admin);
$router->delete('/api/organizations/members/{id}', [OrganizationController::class, 'removeMember'], $admin);

// Partir de soi-même : ouvert à tous, c'est le pendant volontaire de
// l'exclusion.
$router->post('/api/organizations/leave', [OrganizationController::class, 'leave'], $auth);

$router->post('/api/organizations/invitations', [OrganizationController::class, 'invite'], $admin);
$router->delete('/api/organizations/invitations/{id}', [OrganizationController::class, 'revokeInvitation'], $admin);

$router->put('/api/organizations/{id}', [OrganizationController::class, 'update'], $admin);
$router->delete('/api/organizations/{id}', [OrganizationController::class, 'destroy'], $owner);
$router->post('/api/organizations/{id}/activate', [OrganizationController::class, 'activate'], $auth);

// Accueil d'un lien d'invitation : PUBLIQUE, parce que l'invité n'a le plus
// souvent pas encore de compte et doit savoir à quoi il est convié avant d'en
// créer un. Elle ne révèle que ce que le porteur du lien sait déjà.
$router->get('/api/invitations/{token}', [OrganizationController::class, 'showInvitation']);
$router->post('/api/invitations/{token}/accept', [OrganizationController::class, 'acceptInvitation'], $auth);

// --- Le flux ---------------------------------------------------------------
//
// Ce qui a changé depuis un curseur, ET qui est là — en un seul aller-retour,
// parce qu'on regarde toujours les deux ensemble.
//
// SONDAGE COURT, PAS DE SSE, et c'est un choix documenté : sous PHP-FPM un
// flux ouvert immobilise un processus enfant à vie, et dix coéquipiers
// suffiraient à bloquer l'API entière (cf. StreamController).
$router->get('/api/stream', [StreamController::class, 'index'], $auth);
$router->delete('/api/stream', [StreamController::class, 'leave'], $auth);

// L'historique complet — la même table, lue en sens inverse et paginée par
// clé. Ouverte à TOUS les membres : un journal réservé aux administrateurs
// servirait à surveiller plutôt qu'à se coordonner.
$router->get('/api/activity', [StreamController::class, 'history'], $auth);

// --- Supervision de l'instance ---------------------------------------------
// Ce que le navigateur ne disait qu'à sa console. Rangé dans l'espace de
// l'instance, JAMAIS dans celui de l'appelant : une panne du client est une
// panne de l'application, pas une donnée de l'équipe qui l'a rencontrée.
$router->post('/api/client-errors', [ClientErrorController::class, 'store'], $auth);

// --- Tableau de bord -------------------------------------------------------
$router->get('/api/dashboard', [DashboardController::class, 'index'], $auth);

// --- Recherche transverse ---------------------------------------------------
// Un seul endpoint pour les cinq modules : on se souvient d'un mot, pas du
// module où il vit (cf. SearchService).
$router->get('/api/search', [SearchController::class, 'index'], $auth);

// --- Profil ----------------------------------------------------------------
$router->get('/api/profile', [ProfileController::class, 'show'], $auth);
$router->put('/api/profile', [ProfileController::class, 'update'], $auth);
$router->put('/api/profile/password', [ProfileController::class, 'updatePassword'], $auth);
$router->delete('/api/profile', [ProfileController::class, 'destroy'], $auth);

// La photo se TÉLÉVERSE. POST et non PUT : PHP ne lit un envoi multipart que
// sur POST.
$router->post('/api/profile/avatar', [ProfileController::class, 'uploadAvatar'], $auth);
$router->delete('/api/profile/avatar', [ProfileController::class, 'removeAvatar'], $auth);

// --- Fichiers ----------------------------------------------------------------
// SANS session, et c'est la règle : une balise <img> n'en envoie pas. La
// signature de l'adresse tient lieu d'autorisation, et l'API ne la remet qu'à
// qui a le droit de voir le fichier (cf. FileController, SignedUrl).
$router->get('/api/files/{id}', [FileController::class, 'show']);

// --- Paramètres ------------------------------------------------------------
$router->get('/api/settings', [SettingsController::class, 'show'], $auth);
$router->put('/api/settings', [SettingsController::class, 'update'], $auth);

// --- Modules ---------------------------------------------------------------
$router->get('/api/modules', [ModuleController::class, 'index'], $auth);
$router->get('/api/modules/{slug}', [ModuleController::class, 'show'], $auth);

// --- Éléments d'un module --------------------------------------------------
$router->get('/api/modules/{slug}/items', [ItemController::class, 'index'], $auth);
$router->post('/api/modules/{slug}/items', [ItemController::class, 'store'], $auth);

$router->get('/api/items/{id}', [ItemController::class, 'show'], $auth);
$router->put('/api/items/{id}', [ItemController::class, 'update'], $auth);
$router->delete('/api/items/{id}', [ItemController::class, 'destroy'], $auth);
$router->post('/api/items/{id}/restore', [ItemController::class, 'restore'], $auth);

// --- Tickets ---------------------------------------------------------------
// Module « Tickets » : endpoints DÉDIÉS, hors de /api/modules/{slug}/items.
// Un ticket a son propre modèle (numéro, priorité ordonnée, cycle de vie) que
// la structure générique des items ne sait pas porter. Les quatre modules
// restants continuent d'emprunter ItemController en attendant le même
// traitement.
$router->get('/api/tickets', [TicketController::class, 'index'], $auth);
$router->post('/api/tickets', [TicketController::class, 'store'], $auth);

$router->get('/api/tickets/{id}', [TicketController::class, 'show'], $auth);
$router->put('/api/tickets/{id}', [TicketController::class, 'update'], $auth);
$router->delete('/api/tickets/{id}', [TicketController::class, 'destroy'], $auth);
$router->post('/api/tickets/{id}/restore', [TicketController::class, 'restore'], $auth);

// --- Backend : schémas de données et clés d'API ----------------------------
$router->get('/api/backend/tables', [BackendController::class, 'index'], $auth);
$router->post('/api/backend/tables', [BackendController::class, 'store'], $auth);
$router->get('/api/backend/tables/{id}', [BackendController::class, 'show'], $auth);
$router->put('/api/backend/tables/{id}', [BackendController::class, 'update'], $auth);
$router->delete('/api/backend/tables/{id}', [BackendController::class, 'destroy'], $auth);

// Une clé se crée puis se révoque — elle ne se modifie pas : le secret a
// déjà été distribué, en changer la portée après coup induirait en erreur.
$router->post('/api/backend/keys', [BackendController::class, 'storeKey'], $auth);
$router->delete('/api/backend/keys/{id}', [BackendController::class, 'revokeKey'], $auth);

// Les DONNÉES des tables créées ci-dessus. C'est ce qui fait du module un
// backend plutôt qu'un éditeur de diagrammes : une application tierce s'y
// connecte avec une clé de service, comme pour l'ingestion d'erreurs.
$router->get('/api/backend/data/{table}', [DataController::class, 'index'], $ingest);
$router->post('/api/backend/data/{table}', [DataController::class, 'store'], $ingest);
$router->delete('/api/backend/data/{table}/{id}', [DataController::class, 'destroy'], $ingest);

// --- Déploiement -----------------------------------------------------------
$router->get('/api/deployments', [DeploymentController::class, 'index'], $auth);
$router->post('/api/deployments', [DeploymentController::class, 'store'], $auth);
$router->get('/api/deployments/{id}', [DeploymentController::class, 'show'], $auth);
$router->put('/api/deployments/{id}', [DeploymentController::class, 'update'], $auth);
$router->delete('/api/deployments/{id}', [DeploymentController::class, 'destroy'], $auth);
$router->post('/api/deployments/{id}/restore', [DeploymentController::class, 'restore'], $auth);

// --- Supervision -----------------------------------------------------------
// Pas de PUT complet : une erreur est REÇUE, pas saisie. Seul son statut de
// traitement se modifie (cf. ErrorController).
$router->get('/api/errors', [ErrorController::class, 'index'], $auth);
$router->post('/api/errors', [ErrorController::class, 'store'], $ingest);
$router->get('/api/errors/{id}', [ErrorController::class, 'show'], $auth);
$router->put('/api/errors/{id}', [ErrorController::class, 'update'], $auth);
$router->delete('/api/errors/{id}', [ErrorController::class, 'destroy'], $auth);
$router->post('/api/errors/{id}/restore', [ErrorController::class, 'restore'], $auth);

// --- Disponibilité ----------------------------------------------------------
//
// Tout membre lit ; seuls les administrateurs règlent. Une sonde fait émettre
// des requêtes par le serveur vers l'adresse de son choix, chaque minute :
// c'est un pouvoir sur ce que Relais envoie à Internet, pas une préférence.
$router->get('/api/probes', [ProbeController::class, 'index'], $auth);
$router->post('/api/probes', [ProbeController::class, 'store'], $admin);
$router->get('/api/probes/{id}', [ProbeController::class, 'show'], $auth);
$router->put('/api/probes/{id}', [ProbeController::class, 'update'], $admin);
$router->delete('/api/probes/{id}', [ProbeController::class, 'destroy'], $admin);
$router->post('/api/probes/{id}/restore', [ProbeController::class, 'restore'], $admin);
$router->post('/api/probes/{id}/check', [ProbeController::class, 'check'], $admin);

// Documentation : tout membre écrit, comme dans Tickets (cf. DocController).
$router->get('/api/docs', [DocController::class, 'index'], $auth);
$router->post('/api/docs', [DocController::class, 'store'], $auth);
$router->get('/api/docs/{id}', [DocController::class, 'show'], $auth);
$router->put('/api/docs/{id}', [DocController::class, 'update'], $auth);
$router->delete('/api/docs/{id}', [DocController::class, 'destroy'], $auth);
$router->post('/api/docs/{id}/restore', [DocController::class, 'restore'], $auth);

// --- Design ----------------------------------------------------------------
// Une version s'ajoute, ne se modifie ni ne se supprime : c'est ce qui fait
// d'un historique un historique.
$router->get('/api/design/files', [DesignController::class, 'index'], $auth);
$router->post('/api/design/files', [DesignController::class, 'store'], $auth);
$router->get('/api/design/files/{id}', [DesignController::class, 'show'], $auth);
$router->put('/api/design/files/{id}', [DesignController::class, 'update'], $auth);
$router->delete('/api/design/files/{id}', [DesignController::class, 'destroy'], $auth);
$router->post('/api/design/files/{id}/restore', [DesignController::class, 'restore'], $auth);
$router->post('/api/design/files/{id}/versions', [DesignController::class, 'storeVersion'], $auth);

return $router;
