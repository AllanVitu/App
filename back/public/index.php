<?php

declare(strict_types=1);

/**
 * ===========================================================================
 *  Point d'entrée unique de l'API
 *
 *  Nginx route TOUTES les requêtes ici (cf. docker/nginx/default.conf).
 *  Le reste du code source vit hors du document root, donc hors du web.
 *
 *  Ce fichier se limite au câblage : la logique de traitement vit dans
 *  App\Core\Kernel, que la suite de tests exerce à l'identique.
 * ===========================================================================
 */

use App\Core\Kernel;
use App\Core\Request;
use App\Middleware\CorsMiddleware;

require __DIR__ . '/../src/autoload.php';

// Les en-têtes de sécurité statiques (nosniff, X-Frame-Options,
// Referrer-Policy) sont posés une seule fois par Nginx : cf. docker/nginx.
header_remove('X-Powered-By');

$request = Request::capture();

// Le préflight CORS est traité avant le routage : une requête OPTIONS ne
// correspond à aucune route et serait sinon rejetée en 404 par le navigateur.
(new CorsMiddleware())->handle($request);

(new Kernel())->handle($request);
