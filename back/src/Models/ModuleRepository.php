<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Accès au catalogue des modules et aux droits associés.
 *
 * C'est cette source qui alimente la navigation du front : le menu latéral
 * est construit depuis la base, jamais codé en dur côté client.
 */
final class ModuleRepository
{
    /**
     * Modules accessibles à un utilisateur, avec le nombre d'éléments qu'il
     * y possède. Une seule requête, agrégation faite par PostgreSQL.
     *
     * Ces compteurs ne valent QUE pour les modules encore adossés à la table
     * générique module_items. Ceux qui ont leur propre modèle — « tickets » —
     * sont corrigés ensuite par App\Services\ModuleMetrics, seul endroit où
     * l'on décide de ce que le chiffre d'un module signifie.
     *
     * @return list<array<string, mixed>>
     */
    public function listForUser(string $userId): array
    {
        $statement = Database::connection()->prepare(
            "SELECT m.id,
                    m.slug,
                    m.name,
                    m.description,
                    m.icon,
                    m.position,
                    um.settings,
                    COUNT(i.id) FILTER (WHERE i.deleted_at IS NULL)                        AS items_count,
                    COUNT(i.id) FILTER (WHERE i.deleted_at IS NULL AND i.status = 'active') AS active_count
               FROM modules m
               JOIN user_modules um
                 ON um.module_id = m.id
                AND um.user_id = :user_id
                AND um.is_enabled
               LEFT JOIN module_items i
                 ON i.module_id = m.id
                AND i.user_id = :user_id
              WHERE m.is_active
              GROUP BY m.id, um.settings
              ORDER BY m.position, m.name",
        );

        $statement->execute(['user_id' => $userId]);

        return array_map($this->hydrate(...), $statement->fetchAll());
    }

    /**
     * Un module précis, à condition qu'il soit attribué à l'utilisateur.
     * Renvoie null si le module n'existe pas OU si l'utilisateur n'y a pas
     * accès : le client ne peut pas distinguer les deux cas (pas de fuite
     * d'information sur l'existence d'un module).
     *
     * @return array<string, mixed>|null
     */
    public function findBySlugForUser(string $slug, string $userId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT m.id, m.slug, m.name, m.description, m.icon, m.position, um.settings
               FROM modules m
               JOIN user_modules um
                 ON um.module_id = m.id
                AND um.user_id = :user_id
                AND um.is_enabled
              WHERE m.slug = :slug
                AND m.is_active',
        );

        $statement->execute(['slug' => $slug, 'user_id' => $userId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Met à jour les réglages propres à un module pour un utilisateur.
     *
     * @param array<string, mixed> $settings
     */
    public function updateSettings(string $moduleId, string $userId, array $settings): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE user_modules
                SET settings = :settings::jsonb
              WHERE module_id = :module_id AND user_id = :user_id',
        );

        $statement->execute([
            'module_id' => $moduleId,
            'user_id'   => $userId,
            'settings'  => json_encode($settings, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $module = [
            'id'          => (string) $row['id'],
            'slug'        => (string) $row['slug'],
            'name'        => (string) $row['name'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'icon'        => $row['icon'] !== null ? (string) $row['icon'] : null,
            'position'    => (int) $row['position'],
            'settings'    => Database::toObject($row['settings'] ?? null),
        ];

        if (array_key_exists('items_count', $row)) {
            $module['items_count'] = (int) $row['items_count'];
            // Consommé par ModuleMetrics puis retiré de la réponse : c'est un
            // chiffre intermédiaire, pas une donnée que le client interprète.
            $module['active_count'] = (int) ($row['active_count'] ?? 0);
        }

        return $module;
    }
}
