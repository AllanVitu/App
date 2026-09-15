<?php

declare(strict_types=1);

namespace App\Core;

use App\Config\Env;
use App\Services\SelfMonitor;
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
            Response::error($e->getMessage(), $e->getStatus(), $e->getErrors(), $e->getMeta());
        } catch (Throwable $e) {
            // Bug ou panne : rangé dans la supervision de l'instance, réponse
            // volontairement vague. Le détail (requête SQL, chemin, trace) ne
            // doit jamais atteindre le client.
            //
            // La RÉFÉRENCE, si. C'est ce qui permet à l'utilisateur de désigner
            // SA panne en écrivant au support, et à l'équipe de la retrouver
            // parmi les autres au lieu de chercher « vers 14 h, sur les tickets ».
            $reference = (new SelfMonitor())->captureException($e, $request);

            Response::error(
                Env::isDebug()
                    ? sprintf('%s: %s (%s:%d)', $e::class, $e->getMessage(), basename($e->getFile()), $e->getLine())
                    : 'Une erreur interne est survenue.',
                500,
                [],
                ['reference' => $reference],
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
