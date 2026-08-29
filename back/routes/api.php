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
use App\Controllers\DashboardController;
use App\Controllers\HealthController;
use App\Controllers\ItemController;
use App\Controllers\ModuleController;
use App\Controllers\ProfileController;
use App\Controllers\SettingsController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;

$router = new Router();

$auth = [AuthMiddleware::class];

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

// --- Tableau de bord -------------------------------------------------------
$router->get('/api/dashboard', [DashboardController::class, 'index'], $auth);

// --- Profil ----------------------------------------------------------------
$router->get('/api/profile', [ProfileController::class, 'show'], $auth);
$router->put('/api/profile', [ProfileController::class, 'update'], $auth);
$router->put('/api/profile/password', [ProfileController::class, 'updatePassword'], $auth);
$router->delete('/api/profile', [ProfileController::class, 'destroy'], $auth);

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

return $router;
