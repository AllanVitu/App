<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\ModuleRepository;
use App\Services\ModuleMetrics;

/**
 * Catalogue des modules accessibles à l'utilisateur connecté.
 *
 * Le front construit sa navigation à partir de GET /api/modules : ajouter
 * un module en base le fait apparaître dans le menu, sans redéploiement.
 */
final class ModuleController
{
    private ModuleRepository $modules;

    private ModuleMetrics $metrics;

    public function __construct()
    {
        $this->modules = new ModuleRepository();
        $this->metrics = new ModuleMetrics();
    }

    /**
     * GET /api/modules
     *
     * Le catalogue passe par ModuleMetrics : chaque module y reçoit le
     * compteur qui correspond à SA source de données. Sans cela, le menu
     * afficherait pour « tickets » le nombre de lignes qu'il possède dans la
     * table générique — c'est-à-dire un chiffre faux.
     */
    public function index(Request $request): void
    {
        $userId = $request->organizationId();

        Response::json($this->metrics->decorate($this->modules->listForOrganization($userId), $userId));
    }

    /**
     * GET /api/modules/{slug}
     */
    public function show(Request $request): void
    {
        Response::json($this->resolve($request));
    }

    /**
     * Récupère le module ciblé par l'URL en vérifiant les droits d'accès.
     * Mutualisé avec ItemController via le conteneur de requête.
     *
     * @return array<string, mixed>
     * @throws HttpException 404 si le module n'existe pas ou n'est pas attribué
     */
    public static function resolveModule(Request $request): array
    {
        $slug = (string) $request->param('slug');
        $module = (new ModuleRepository())->findBySlugForOrganization($slug, $request->organizationId());

        if ($module === null) {
            throw HttpException::notFound('Module introuvable ou non accessible.');
        }

        return $module;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve(Request $request): array
    {
        return self::resolveModule($request);
    }
}
