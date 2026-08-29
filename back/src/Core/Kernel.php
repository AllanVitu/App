<?php

declare(strict_types=1);

namespace App\Core;

use App\Config\Env;
use Throwable;

/**
 * Traitement d'une requête, du routage à la réponse.
 *
 * Cette logique vivait dans public/index.php. L'en extraire permet aux tests
 * d'exercer EXACTEMENT le même chemin que la production — routage, middlewares,
 * gestion d'erreurs comprise — sans passer par un serveur HTTP. Un test qui
 * emprunterait un chemin parallèle ne prouverait rien.
 */
final class Kernel
{
    /**
     * @param string|null $routesFile Table de routage à charger (par défaut celle de l'application)
     */
    public function __construct(private readonly ?string $routesFile = null)
    {
    }

    /**
     * Exécute la requête et écrit la réponse sur la sortie.
     */
    public function handle(Request $request): void
    {
        try {
            // Un JSON illisible est signalé ici, une fois les en-têtes CORS posés.
            $request->assertBodyIsValid();

            $this->router()->dispatch($request);
        } catch (HttpException $e) {
            // Erreur métier prévue : le message est destiné à l'utilisateur.
            Response::error($e->getMessage(), $e->getStatus(), $e->getErrors());
        } catch (Throwable $e) {
            // Bug ou panne : journalisé côté serveur, réponse volontairement
            // vague. Le détail (requête SQL, chemin, trace) ne doit jamais
            // atteindre le client.
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
    }

    private function router(): Router
    {
        /** @var Router $router */
        $router = require $this->routesFile ?? __DIR__ . '/../../routes/api.php';

        return $router;
    }
}
