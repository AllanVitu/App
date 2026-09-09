<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Préférences utilisateur (page Paramètres).
 *
 * La ligne est créée par le trigger users_provision_defaults à l'inscription ;
 * find() sait néanmoins la recréer si elle manque (compte importé, migration).
 */
final class SettingsRepository
{
    /**
     * @return array<string, mixed>
     */
    public function findOrCreate(string $userId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT theme, language, timezone, density, reduce_motion, notifications, preferences, updated_at
               FROM user_settings WHERE user_id = :user_id',
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();

        if ($row === false) {
            $insert = Database::connection()->prepare(
                'INSERT INTO user_settings (user_id) VALUES (:user_id)
                 RETURNING theme, language, timezone, density, reduce_motion, notifications, preferences, updated_at',
            );
            $insert->execute(['user_id' => $userId]);
            /** @var array<string, mixed> $row */
            $row = $insert->fetch();
        }

        return $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function update(string $userId, array $attributes): array
    {
        $statement = Database::connection()->prepare(
            'UPDATE user_settings
                SET theme = :theme,
                    language = :language,
                    timezone = :timezone,
                    density = :density,
                    reduce_motion = :reduce_motion,
                    notifications = :notifications::jsonb
              WHERE user_id = :user_id
          RETURNING theme, language, timezone, density, reduce_motion, notifications, preferences, updated_at',
        );

        $statement->execute([
            'user_id'       => $userId,
            'theme'         => $attributes['theme'],
            'language'      => $attributes['language'],
            'timezone'      => $attributes['timezone'],
            'density'       => $attributes['density'],
            // PDO n'a pas de type booléen pour PostgreSQL : on passe la
            // représentation que le pilote sait convertir.
            'reduce_motion' => $attributes['reduce_motion'] ? 'true' : 'false',
            'notifications' => json_encode($attributes['notifications'], JSON_UNESCAPED_UNICODE),
        ]);

        /** @var array<string, mixed> $row */
        $row = $statement->fetch();

        return $this->hydrate($row);
    }

    /**
     * Prépare les préférences pour la réponse JSON.
     *
     * En interne les champs JSONB restent des tableaux PHP (nécessaire pour
     * les fusionner) ; en sortie ils sont castés en objets afin qu'un jeu de
     * préférences vide se sérialise en `{}` et non en `[]`.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function present(array $settings): array
    {
        $settings['notifications'] = (object) $settings['notifications'];
        $settings['preferences']   = (object) $settings['preferences'];

        return $settings;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'theme'         => (string) $row['theme'],
            'language'      => (string) $row['language'],
            'timezone'      => (string) $row['timezone'],
            'density'       => (string) $row['density'],
            'reduce_motion' => Database::toBool($row['reduce_motion']),
            'notifications' => Database::toArray($row['notifications']),
            'preferences'   => Database::toArray($row['preferences']),
            'updated_at'    => Database::toIso($row['updated_at']),
        ];
    }
}
