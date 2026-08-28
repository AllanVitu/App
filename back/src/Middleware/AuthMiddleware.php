<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Models\UserRepository;
use App\Services\Jwt;

/**
 * Protège les routes nécessitant une authentification.
 *
 * Le jeton est vérifié cryptographiquement, PUIS l'utilisateur est rechargé
 * depuis la base. Ce second aller-retour est volontaire : un compte
 * désactivé ou supprimé perd immédiatement l'accès, sans attendre
 * l'expiration de son jeton.
 */
final class AuthMiddleware
{
    public function handle(Request $request): void
    {
        $token = $request->bearerToken();

        if ($token === null) {
            throw HttpException::unauthorized('Jeton d\'accès manquant.');
        }

        $claims = Jwt::verify($token);

        $user = (new UserRepository())->findById((string) $claims['sub']);

        if ($user === null) {
            throw HttpException::unauthorized('Compte introuvable.');
        }

        if (!$user['is_active']) {
            throw HttpException::forbidden('Ce compte est désactivé.');
        }

        $request->setAttribute('user', $user);
    }
}
