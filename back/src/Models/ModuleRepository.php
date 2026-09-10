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
     * Modules ouverts à une organisation, avec le nombre d'éléments qu'elle
     * y possède. Une seule requête, agrégation faite par PostgreSQL.
     *
     * Ouverts à L'ORGANISATION et non à la personne : toute l'équipe voit la
     * même barre latérale. Un module qu'un coéquipier active apparaît chez
     * les autres, ce qui est bien le sens d'un espace de travail commun.
     *
     * Ces compteurs ne valent QUE pour les modules encore adossés à la table
     * générique module_items. Ceux qui ont leur propre modèle — « tickets » —
     * sont corrigés ensuite par App\Services\ModuleMetrics, seul endroit où
     * l'on décide de ce que le chiffre d'un module signifie.
     *
     * @return list<array<string, mixed>>
     */
    public function listForOrganization(string $organizationId): array
    {
        $statement = Database::connection()->prepare(
            "SELECT m.id,
                    m.slug,
                    m.name,
                    m.description,
                    m.icon,
                    m.position,
                    om.settings,
                    COUNT(i.id) FILTER (WHERE i.deleted_at IS NULL)                        AS items_count,
                    COUNT(i.id) FILTER (WHERE i.deleted_at IS NULL AND i.status = 'active') AS active_count
               FROM modules m
               JOIN organization_modules om
                 ON om.module_id = m.id
                AND om.organization_id = :organization_id
                AND om.is_enabled
               LEFT JOIN module_items i
                 ON i.module_id = m.id
                AND i.organization_id = :organization_id
              WHERE m.is_active
              GROUP BY m.id, om.settings
              ORDER BY m.position, m.name",
        );

        $statement->execute(['organization_id' => $organizationId]);

        return array_map($this->hydrate(...), $statement->fetchAll());
    }

    /**
     * Un module précis, à condition qu'il soit attribué à l'organisation.
     * Renvoie null si le module n'existe pas OU si l'organisation n'y a pas
     * accès : le client ne peut pas distinguer les deux cas (pas de fuite
     * d'information sur l'existence d'un module).
     *
     * @return array<string, mixed>|null
     */
    public function findBySlugForOrganization(string $slug, string $organizationId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT m.id, m.slug, m.name, m.description, m.icon, m.position, om.settings
               FROM modules m
               JOIN organization_modules om
                 ON om.module_id = m.id
                AND om.organization_id = :organization_id
                AND om.is_enabled
              WHERE m.slug = :slug
                AND m.is_active',
        );

        $statement->execute(['slug' => $slug, 'organization_id' => $organizationId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Met à jour les réglages propres à un module, pour toute l'organisation : activer un module est une
     * décision d'équipe, pas une préférence personnelle.
     *
     * @param array<string, mixed> $settings
     */
    public function updateSettings(string $moduleId, string $organizationId, array $settings): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE organization_modules
                SET settings = :settings::jsonb
              WHERE module_id = :module_id AND organization_id = :organization_id',
        );

        $statement->execute([
            'module_id'       => $moduleId,
            'organization_id' => $organizationId,
            'settings'        => json_encode($settings, JSON_UNESCAPED_UNICODE),
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
