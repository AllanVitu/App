<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\RateLimiter;
use App\Services\SelfMonitor;

/**
 * POST /api/client-errors — ce que le navigateur ne disait qu'à sa console.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  LES QUATRE FILETS DE main.js ATTRAPAIENT, ET GARDAIENT POUR EUX        │
 * │                                                                         │
 * │  Une exception de rendu, une promesse rejetée, un écran qui ne se       │
 * │  charge plus : l'utilisateur voyait un bandeau, la console une trace,   │
 * │  et l'équipe rien du tout. Le défaut n'existait que pour celui qui le   │
 * │  subissait.                                                             │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * RÉSERVÉ AUX SESSIONS OUVERTES. Une route publique offrirait à n'importe qui
 * un moyen d'écrire dans la base de l'instance ; les erreurs des écrans
 * publics — connexion, inscription — sont le prix de cette fermeture.
 *
 * RIEN N'IDENTIFIE L'AUTEUR dans ce qui est rangé : ni compte, ni adresse IP,
 * ni navigateur. Savoir QUI a rencontré une panne n'aide pas à la corriger,
 * et ferait de chaque occurrence une donnée personnelle.
 */
final class ClientErrorController
{
    /** Les quatre filets posés dans main.js, et eux seuls. */
    private const KINDS = ['component', 'promise', 'exception', 'navigation'];

    /**
     * Par compte et par tranche de dix minutes. Le client déduplique déjà :
     * ce plafond est pour l'écran qui rejetterait à chaque image malgré tout,
     * ou pour le client qui ne serait pas le nôtre.
     */
    private const MAX_PER_WINDOW = 20;
    private const WINDOW_SECONDS = 600;

    public function store(Request $request): void
    {
        (new RateLimiter())->hit('client-errors', $request->userId(), self::MAX_PER_WINDOW, self::WINDOW_SECONDS);

        $validator = new Validator($request->all());
        $kind      = $validator->enum('kind', self::KINDS);
        $message   = $validator->string('message', min: 1, max: 1000, label: 'message');
        $stack     = $validator->string('stack', required: false, max: 8000, label: 'pile d\'appels');
        $route     = $validator->string('route', required: false, max: 120, label: 'écran');
        $component = $validator->string('component', required: false, max: 120, label: 'composant');
        $release   = $validator->string('release', required: false, max: 40, label: 'version');
        $validator->check();

        /** @var string $kind */
        /** @var string $message */
        (new SelfMonitor())->captureClientError([
            'kind'      => $kind,
            'message'   => $message,
            'stack'     => $stack,
            'route'     => $route,
            'component' => $component,
            'release'   => $release,
        ]);

        // 202 : reçu. Le rangement peut échouer sans que le navigateur ait à le
        // savoir — il n'aurait rien à en faire.
        Response::json(['received' => true], 202);
    }
}
