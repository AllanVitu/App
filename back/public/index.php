<?php

declare(strict_types=1);

/**
 * ===========================================================================
 *  Point d'entrée unique de l'API
 *
 *  Nginx route TOUTES les requêtes ici (cf. docker/nginx/default.conf).
 *  Le reste du code source vit hors du document root, donc hors du web.
 * ===========================================================================
 */

use App\Config\Env;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\CorsMiddleware;

require __DIR__ . '/../src/autoload.php';

// Les en-têtes de sécurité statiques (nosniff, X-Frame-Options,
// Referrer-Policy) sont posés une seule fois par Nginx : cf. docker/nginx.
header_remove('X-Powered-By');

$request = Request::capture();

// Le préflight CORS est traité avant le routage : une requête OPTIONS ne
// correspond à aucune route et serait sinon rejetée en 404 par le navigateur.
(new CorsMiddleware())->handle($request);

try {
    // Un JSON illisible est signalé ici, une fois les en-têtes CORS posés.
    $request->assertBodyIsValid();

    /** @var Router $router */
    $router = require __DIR__ . '/../routes/api.php';

    $router->dispatch($request);
} catch (HttpException $e) {
    // Erreur métier prévue : le message est destiné à l'utilisateur.
    Response::error($e->getMessage(), $e->getStatus(), $e->getErrors());
} catch (Throwable $e) {
    // Bug ou panne : journalisé côté serveur, réponse volontairement vague.
    // Le détail (requête SQL, chemin, trace) ne doit jamais atteindre le client.
    error_log(sprintf(
        "[API] %s: %s in %s:%d\n%s",
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString(),
    ));

    Response::error(
        Env::isDebug()
            ? sprintf('%s: %s (%s:%d)', $e::class, $e->getMessage(), basename($e->getFile()), $e->getLine())
            : 'Une erreur interne est survenue.',
        500,
    );
}
