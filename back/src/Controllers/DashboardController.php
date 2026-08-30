<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\ModuleItemRepository;
use App\Models\ModuleRepository;
use App\Models\TicketRepository;
use App\Services\ModuleMetrics;

/**
 * Données d'accueil.
 *
 * Le tableau de bord n'est PAS un second menu — la navigation appartient au
 * menu latéral. Il répond à trois questions, et à rien d'autre :
 *
 *   1. Qu'est-ce qui demande une action ?   -> attention
 *   2. Où en est chaque module ?            -> modules (avec leur état)
 *   3. Que s'est-il passé récemment ?       -> recent
 *
 * Les compteurs globaux qui figuraient ici ne comptaient que la table
 * générique module_items : depuis que « tickets » a sa propre table, ils
 * affichaient « 3 éléments » à un utilisateur qui avait quatorze tickets.
 * Ils sont remplacés par l'état par module, qui a une source par module.
 *
 * Le tout en un seul aller-retour réseau : l'écran s'affiche d'un bloc.
 */
final class DashboardController
{
    /**
     * GET /api/dashboard
     */
    public function index(Request $request): void
    {
        $userId = $request->userId();

        $modules = (new ModuleRepository())->listForUser($userId);

        Response::json([
            'attention' => (new TicketRepository())->needsAttention($userId, 5),
            'modules'   => (new ModuleMetrics())->decorate($modules, $userId),
            'recent'    => (new ModuleItemRepository())->recentForUser($userId, 6),
        ]);
    }
}
