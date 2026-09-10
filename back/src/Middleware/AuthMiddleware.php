<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Models\OrganizationRepository;
use App\Models\UserRepository;
use App\Services\Jwt;

/**
 * Protège les routes nécessitant une authentification.
 *
 * Le jeton est vérifié cryptographiquement, PUIS l'utilisateur est rechargé
 * depuis la base. Ce second aller-retour est volontaire : un compte
 * désactivé ou supprimé perd immédiatement l'accès, sans attendre
 * l'expiration de son jeton.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  L'ORGANISATION SUIT LE MÊME CHEMIN, POUR LA MÊME RAISON                │
 * │                                                                         │
 * │  Elle est résolue ICI, à chaque requête, et jamais portée par le jeton. │
 * │  Un jeton vit quinze minutes ; pendant ces quinze minutes il            │
 * │  affirmerait une appartenance qui peut avoir été révoquée. Le           │
 * │  raisonnement est exactement celui du rechargement du compte au-dessus. │
 * │                                                                         │
 * │  C'est aussi ce qui fait de « organization_id » une valeur que le       │
 * │  client ne choisit JAMAIS : il ne l'envoie pas, il ne peut donc pas la  │
 * │  falsifier. Le seul geste qui la change est POST /api/organizations/    │
 * │  {id}/activate, qui vérifie l'appartenance avant d'écrire.              │
 * └─────────────────────────────────────────────────────────────────────────┘
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

        $organization = (new OrganizationRepository())->activeFor(
            $user['id'],
            $user['active_organization_id'],
        );

        // Un compte sans aucune appartenance ne peut rien faire : l'inscription
        // lui en crée une, et le retrait du dernier membre est refusé. Y
        // arriver signalerait une donnée abîmée, pas un cas d'usage.
        if ($organization === null) {
            throw HttpException::forbidden(
                'Ce compte n\'appartient à aucun espace de travail.',
            );
        }

        $request->setAttribute('organization', $organization);
    }
}
