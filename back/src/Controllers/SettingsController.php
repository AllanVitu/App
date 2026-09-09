<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\SettingsRepository;

/**
 * Page Paramètres : préférences d'affichage et de notification.
 */
final class SettingsController
{
    private const THEMES    = ['light', 'dark', 'system'];
    private const LANGUAGES = ['fr', 'en'];

    /** L'interface est dense par construction ; ceci en règle le grain. */
    private const DENSITIES = ['compact', 'confortable'];

    private SettingsRepository $settings;

    public function __construct()
    {
        $this->settings = new SettingsRepository();
    }

    /**
     * GET /api/settings
     */
    public function show(Request $request): void
    {
        Response::json(SettingsRepository::present($this->settings->findOrCreate($request->userId())));
    }

    /**
     * PUT /api/settings
     */
    public function update(Request $request): void
    {
        $current = $this->settings->findOrCreate($request->userId());

        $validator = new Validator($request->all());
        $theme     = $validator->enum('theme', self::THEMES, required: false, default: $current['theme']);
        $language  = $validator->enum('language', self::LANGUAGES, required: false, default: $current['language']);
        $timezone  = $validator->string('timezone', required: false, max: 64, default: $current['timezone']);

        // Le fuseau est comparé à la liste officielle PHP plutôt qu'à une
        // expression régulière : seule une valeur réellement utilisable passe.
        if ($timezone !== null && !in_array($timezone, timezone_identifiers_list(), true)) {
            $validator->addError('timezone', 'Fuseau horaire inconnu.');
        }

        /**
         * ┌───────────────────────────────────────────────────────────────┐
         * │  DEUX RÉGLAGES QUI AGISSENT, ET C'EST LA SEULE RAISON D'ÊTRE  │
         * │  ICI                                                          │
         * │                                                               │
         * │  Cet écran a déjà perdu des réglages : trois interrupteurs de │
         * │  notification et un choix « English » en ont été retirés parce│
         * │  qu'ils étaient enregistrés et consommés par personne. Un     │
         * │  réglage qui ne change rien fait croire à un contrôle qui     │
         * │  n'existe pas.                                                │
         * │                                                               │
         * │  Ces deux-là sont branchés dans le même mouvement que leur    │
         * │  ajout : la densité change l'interface, la réduction de       │
         * │  mouvement coupe les animations.                              │
         * └───────────────────────────────────────────────────────────────┘
         */
        $density = $validator->enum(
            'density',
            self::DENSITIES,
            required: false,
            default: $current['density'],
        );

        $reduceMotion = $validator->boolean('reduce_motion', $current['reduce_motion']);

        $notifications = $validator->jsonObject('notifications', $current['notifications']);
        $validator->check();

        // Liste blanche des clés de notification : le client ne peut pas
        // faire grossir le JSONB avec des champs arbitraires.
        $allowedKeys   = ['email', 'push', 'weekly_digest'];
        $notifications = array_map(
            static fn (mixed $value): bool => filter_var($value, FILTER_VALIDATE_BOOL),
            array_intersect_key($notifications, array_flip($allowedKeys)),
        );

        Response::json(SettingsRepository::present($this->settings->update($request->userId(), [
            'theme'         => $theme,
            'language'      => $language,
            'timezone'      => $timezone,
            'density'       => $density,
            'reduce_motion' => $reduceMotion,
            'notifications' => $notifications + $current['notifications'],
        ])));
    }
}
