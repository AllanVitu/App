<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Config\Env;
use App\Core\Request;

/**
 * Politique CORS.
 *
 * Le front (Vite, port 5173) et l'API (Nginx, port 8080) sont deux origines
 * distinctes : le navigateur exige donc une autorisation explicite.
 *
 * L'origine est comparée à une liste blanche et renvoyée telle quelle —
 * jamais « * », qui est incompatible avec Allow-Credentials et ouvrirait
 * l'API à n'importe quel site.
 */
final class CorsMiddleware
{
    private const ALLOWED_METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
    private const ALLOWED_HEADERS = 'Content-Type, Authorization, Accept, X-Requested-With';
    private const MAX_AGE         = 86400; // 24 h de cache du préflight

    /**
     * Pose les en-têtes CORS. Sur une requête préflight (OPTIONS), la réponse
     * est envoyée immédiatement et l'exécution s'arrête.
     */
    public function handle(Request $request): void
    {
        $origin = $request->header('origin');

        if ($origin !== null && $this->isAllowed($origin)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            // Indispensable pour que le cookie HttpOnly de refresh circule.
            header('Access-Control-Allow-Credentials: true');
            // L'origine varie : les caches intermédiaires ne doivent pas
            // servir une réponse destinée à une autre origine.
            header('Vary: Origin');
        }

        if ($request->method === 'OPTIONS') {
            header('Access-Control-Allow-Methods: ' . self::ALLOWED_METHODS);
            header('Access-Control-Allow-Headers: ' . self::ALLOWED_HEADERS);
            header('Access-Control-Max-Age: ' . self::MAX_AGE);
            http_response_code(204);

            exit;
        }
    }

    private function isAllowed(string $origin): bool
    {
        return in_array($origin, $this->allowedOrigins(), true);
    }

    /**
     * @return list<string>
     */
    private function allowedOrigins(): array
    {
        // CORS_ALLOWED_ORIGIN accepte plusieurs origines séparées par des virgules.
        $configured = Env::get('CORS_ALLOWED_ORIGIN', 'http://localhost:5173') ?? '';

        $origins = array_filter(array_map('trim', explode(',', $configured)));

        if (!Env::isProduction()) {
            // Confort de développement : 127.0.0.1 et localhost sont deux
            // origines différentes pour le navigateur.
            $origins[] = 'http://127.0.0.1:5173';
            $origins[] = 'http://localhost:5173';
        }

        return array_values(array_unique($origins));
    }
}
