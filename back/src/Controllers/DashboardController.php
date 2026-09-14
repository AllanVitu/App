<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityRepository;
use App\Models\ModuleRepository;
use App\Services\ActivityFeed;
use App\Services\AttentionFeed;
use App\Services\DashboardDay;
use App\Services\ModuleMetrics;

/**
 * Données d'accueil.
 *
 * Le tableau de bord n'est PAS un second menu — la navigation appartient au
 * menu latéral. Il répond à quatre questions, et à rien d'autre :
 *
 *   1. Où en est-on, en quatre chiffres ?        -> summary
 *   2. Que s'est-il passé en production ?        -> line
 *   3. Qu'est-ce qui demande une action, et
 *      qu'est-ce qui me revient à moi ?          -> attention, my_day
 *   4. Où en est chaque module ?                 -> modules (avec leur état)
 *
 * « trends » et « recent » restent servis : l'historique et les tests
 * d'intégration du journal les lisent, et un client ancien encore ouvert
 * dans un onglet ne doit pas se retrouver avec un écran vide au déploiement.
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
        $organizationId = $request->organizationId();

        $modules = (new ModuleRepository())->listForOrganization($organizationId);
        $feed    = new ActivityFeed();
        $day     = new DashboardDay();

        Response::json([
            // Les quatre chiffres de tête, avec la période précédente pour
            // trois d'entre eux — le quatrième est un état, pas un flux.
            'summary'   => $feed->summary($organizationId, 7),
            // Mises en production et erreurs par heure, sur un même axe
            // (cf. DashboardDay::productionLine).
            'line'      => $day->productionLine($organizationId),
            // Tous modules confondus, et hiérarchisé : un déploiement en
            // échec passe avant un ticket marqué urgent (cf. AttentionFeed).
            'attention' => (new AttentionFeed())->forOrganization($organizationId, 5),
            // Ce qui revient à la personne connectée, pas à l'espace entier.
            'my_day'    => $day->myDay($organizationId, $request->userId()),
            'modules'   => (new ModuleMetrics())->decorate($modules, $organizationId),
            'trends'    => $feed->dailySeries($organizationId, 14),
            // Le fil vient du journal, qui sait dire QUI a fait quoi — une
            // union des tables métier ne savait montrer que des créations.
            'recent'    => (new ActivityRepository())->recent($organizationId, 12),
        ]);
    }
}
