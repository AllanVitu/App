<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\ModuleItemRepository;
use App\Models\ModuleRepository;

/**
 * Données d'accueil : indicateurs, répartition par module et activité récente.
 *
 * Regroupées en un seul endpoint pour que le tableau de bord s'affiche en un
 * aller-retour réseau.
 */
final class DashboardController
{
    /**
     * GET /api/dashboard
     */
    public function index(Request $request): void
    {
        $userId = $request->userId();
        $items  = new ModuleItemRepository();

        Response::json([
            'stats'   => $items->statsForUser($userId),
            'modules' => (new ModuleRepository())->listForUser($userId),
            'recent'  => $items->recentForUser($userId, 6),
        ]);
    }
}
