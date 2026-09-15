<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Models\OrganizationRepository;

/**
 * Exige le rôle « owner » dans l'organisation courante.
 *
 * Réservé aux gestes irréversibles ou définitifs : supprimer l'espace,
 * transmettre la propriété. Un administrateur peut tout gérer au quotidien,
 * mais pas décider de la fin.
 *
 * Cf. RequireAdmin pour la raison d'être de ces deux classes jumelles.
 */
final class RequireOwner
{
    public function handle(Request $request): void
    {
        if (!OrganizationRepository::allows($request->organizationRole(), 'owner')) {
            throw HttpException::forbidden(
                'Seul un propriétaire de cet espace peut effectuer cette action.',
            );
        }
    }
}
