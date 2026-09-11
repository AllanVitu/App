<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\ActivityRepository;
use App\Models\PresenceRepository;

/**
 * Le flux : ce qui a changé depuis, et qui est là.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  POURQUOI PAS DE SSE, ALORS QUE C'EST LA RÉPONSE ÉVIDENTE               │
 * │                                                                         │
 * │  Sous PHP-FPM, chaque flux ouvert immobilise un processus enfant POUR   │
 * │  TOUTE SA DURÉE. Le nombre d'enfants est fini — quelques dizaines. Dix  │
 * │  coéquipiers avec l'application ouverte, et il ne reste plus personne   │
 * │  pour répondre aux requêtes ordinaires : l'API se bloque elle-même, et  │
 * │  la panne ressemble à une lenteur réseau.                              │
 * │                                                                         │
 * │  Un sondage court coûte une requête de quelques millisecondes toutes    │
 * │  les trois secondes, et seulement pendant que l'onglet est visible. À   │
 * │  dix personnes, trois requêtes par seconde. C'est le prix honnête de    │
 * │  cette architecture, et il est petit.                                   │
 * │                                                                         │
 * │  LA PORTE RESTE OUVERTE : le flux lit le journal, pas une file en       │
 * │  mémoire. Le jour où un processus long sert les connexions, il lira le  │
 * │  même journal et rien d'autre ne bougera.                              │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * UN SEUL ALLER-RETOUR pour les deux questions — « quoi de neuf » et « qui est
 * là ». Les séparer aurait doublé le trafic pour deux réponses qu'on regarde
 * toujours ensemble.
 */
final class StreamController
{
    /** Écrans reconnus pour la présence. Liste blanche, comme les tris. */
    private const SCREENS = [
        'dashboard',
        'tickets',
        'backend',
        'deploiement',
        'supervision',
        'design',
        'team',
        'settings',
        'profile',
    ];

    /**
     * GET /api/stream?depuis=<curseur>&ecran=tickets&sujet=<uuid>
     */
    public function index(Request $request): void
    {
        $validator = new Validator($request->query);
        $screen    = $validator->enum('ecran', self::SCREENS, required: false, default: 'dashboard');
        $subject   = $validator->uuid('sujet', required: false);
        $validator->check();

        // L'ABSENCE du paramètre marque le premier appel, jamais la valeur
        // zéro : un espace neuf a un curseur à 0, et confondre les deux ferait
        // sauter son tout premier événement (cf. ActivityRepository::since).
        $depuis = $request->queryParam('depuis');
        $cursor = $depuis === null || !is_numeric($depuis) ? null : max(0, (int) $depuis);

        $flux = (new ActivityRepository())->since($request->organizationId(), $cursor);

        Response::json($flux['events'], 200, [
            'cursor' => $flux['cursor'],
            // « Vous êtes trop loin derrière, rechargez » — dit explicitement
            // plutôt que laissé deviner à un écran qui manquerait des
            // changements sans le savoir.
            'distanced' => $flux['distanced'],
            'presence'  => (new PresenceRepository())->heartbeat(
                $request->organizationId(),
                $request->userId(),
                (string) $screen,
                $subject,
            ),
        ]);
    }

    /**
     * DELETE /api/stream
     *
     * Le départ, envoyé à la fermeture de l'onglet. Le repli du temps rendrait
     * le même service quinze secondes plus tard ; ceci fait simplement
     * disparaître le marqueur tout de suite.
     */
    public function leave(Request $request): void
    {
        (new PresenceRepository())->leave($request->organizationId(), $request->userId());

        Response::noContent();
    }

    /**
     * GET /api/activity?module=tickets&acteur=<uuid>&avant=<curseur>
     *
     * L'historique complet, pour l'écran dédié. Le flux lit la même table en
     * sens inverse : ce que l'un a montré passer, l'autre le retrouve.
     *
     * Aucune restriction de rôle. Un journal que seuls les administrateurs
     * pourraient lire servirait à surveiller plutôt qu'à se coordonner — et
     * n'apprendrait rien à personne sur ce que l'équipe vient de faire.
     */
    public function history(Request $request): void
    {
        $validator = new Validator($request->query);
        $module    = $validator->string('module', required: false, max: 32, label: 'module');
        $actor     = $validator->uuid('acteur', required: false);
        $validator->check();

        $journal = new ActivityRepository();

        $page = $journal->history(
            $request->organizationId(),
            ['module' => $module, 'actor' => $actor],
            $request->queryInt('avant', 0, 0, PHP_INT_MAX) ?: null,
        );

        Response::json($page['events'], 200, [
            'next'   => $page['next'],
            'actors' => $journal->actors($request->organizationId()),
        ]);
    }
}
