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
            'notifications' => $notifications + $current['notifications'],
        ])));
    }
}
