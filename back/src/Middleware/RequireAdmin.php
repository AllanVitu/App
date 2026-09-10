<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Models\OrganizationRepository;

/**
 * Exige le rôle « admin » — ou mieux — dans l'organisation courante.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  IL EXISTAIT DÉJÀ UN « role », ET IL NE GOUVERNAIT RIEN                 │
 * │                                                                         │
 * │  Le champ « users.role » était émis dans le jeton, affiché en badge sur │
 * │  le profil, et vérifié NULLE PART : ni middleware, ni route, ni requête │
 * │  ne le consultait. Un badge qui n'ouvre ni ne ferme aucune porte n'est  │
 * │  pas une autorisation, c'est une décoration.                            │
 * │                                                                         │
 * │  Ces deux gardes sont les premières à en faire dépendre quelque chose.  │
 * │  Elles portent sur le rôle dans l'ORGANISATION, qui est une autre       │
 * │  notion : « users.role » reste l'administration de l'INSTANCE.          │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * DEUX CLASSES PLUTÔT QU'UN PARAMÈTRE : le routeur instancie les middlewares
 * sans arguments (« new $classe() »). Paramétrer aurait demandé de changer sa
 * signature pour deux valeurs qu'on peut nommer.
 */
final class RequireAdmin
{
    public function handle(Request $request): void
    {
        // Le rôle vient de l'attribut posé par AuthMiddleware, jamais du
        // client : il est relu en base à chaque requête, donc une rétrogradation
        // prend effet immédiatement.
        if (!OrganizationRepository::allows($request->organizationRole(), 'admin')) {
            throw HttpException::forbidden(
                'Seuls les administrateurs de cet espace peuvent effectuer cette action.',
            );
        }
    }
}
