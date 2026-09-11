<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityRepository;
use App\Models\ModuleRepository;
use App\Services\ActivityFeed;
use App\Services\AttentionFeed;
use App\Services\ModuleMetrics;

/**
 * Données d'accueil.
 *
 * Le tableau de bord n'est PAS un second menu — la navigation appartient au
 * menu latéral. Il répond à cinq questions, et à rien d'autre :
 *
 *   1. Où en est-on, en quatre chiffres ?   -> summary
 *   2. Qu'est-ce qui demande une action ?   -> attention
 *   3. Quelle tendance sur deux semaines ?  -> trends
 *   4. Où en est chaque module ?            -> modules (avec leur état)
 *   5. Que s'est-il passé récemment ?       -> recent
 *
 * L'ordre n'est pas décoratif : il va du plus synthétique au plus détaillé,
 * et c'est celui dans lequel l'écran se lit.
 *
 * Les compteurs globaux qui figuraient ici ne comptaient que la table
 * générique module_items : depuis que « tickets » a sa propre table, ils
 * affichaient « 3 éléments » à un utilisateur qui avait quatorze tickets.
 * Ceux de « summary » lisent chacun leur table métier.
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
        $userId = $request->organizationId();

        $modules = (new ModuleRepository())->listForOrganization($userId);
        $feed    = new ActivityFeed();

        Response::json([
            // Les quatre chiffres de tête, avec la période précédente pour
            // trois d'entre eux — le quatrième est un état, pas un flux.
            'summary'   => $feed->summary($userId, 7),
            // Tous modules confondus, et hiérarchisé : un déploiement en
            // échec passe avant un ticket marqué urgent (cf. AttentionFeed).
            'attention' => (new AttentionFeed())->forOrganization($userId, 5),
            'modules'   => (new ModuleMetrics())->decorate($modules, $userId),
            // Deux séries quotidiennes, tracées dans DEUX cadres distincts :
            // un déploiement par jour et quarante erreurs par jour n'ont pas
            // d'unité commune (cf. ActivityFeed::dailySeries).
            'trends'    => $feed->dailySeries($userId, 14),
            // ┌───────────────────────────────────────────────────────────────┐
            // │  LE FIL VIENT DU JOURNAL, PLUS D'UNE UNION DE CINQ TABLES     │
            // │                                                               │
            // │  Il était assemblé par UNION ALL sur les cinq tables métier,  │
            // │  ce qui ne pouvait montrer que des CRÉATIONS — une table de   │
            // │  données ne garde pas la trace de ce qui l'a modifiée.        │
            // │                                                               │
            // │  Depuis qu'un journal existe, deux sources décrivaient les    │
            // │  mêmes faits sans pouvoir s'accorder : celle-ci disait « un   │
            // │  ticket existe depuis mardi », celle-là « Bob l'a rouvert ce  │
            // │  matin ». Une seule survit, et c'est celle qui sait dire QUI. │
            // └───────────────────────────────────────────────────────────────┘
            'recent'    => (new ActivityRepository())->recent($userId, 12),
        ]);
    }
}
