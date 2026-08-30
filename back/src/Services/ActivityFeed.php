<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * Fil d'activité, tous modules confondus.
 *
 * Chaque module ayant sa propre table, l'activité récente ne peut plus se
 * lire dans module_items : elle y montrerait des lignes qu'aucun écran
 * n'affiche plus, et cliquer dessus mènerait à un module où elles n'existent
 * pas.
 *
 * UNE seule requête, par UNION ALL, plutôt que cinq requêtes triées en PHP :
 * il faut les N plus récents TOUS MODULES CONFONDUS, ce qui obligerait sinon
 * à rapatrier N lignes par module pour n'en garder que N au total.
 *
 * Chaque branche projette la même forme — module, identifiant, référence
 * courte, titre, date — pour que le client affiche n'importe quelle ligne
 * sans rien savoir du module dont elle provient.
 */
final class ActivityFeed
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(string $userId, int $limit = 8): array
    {
        $statement = Database::connection()->prepare(
            "SELECT module, id::text AS id, ref, title, happened_at
               FROM (
                   SELECT 'tickets'::text AS module,
                          t.id,
                          '#' || t.number   AS ref,
                          t.title           AS title,
                          t.updated_at      AS happened_at
                     FROM tickets t
                    WHERE t.user_id = :user_id AND t.deleted_at IS NULL

                   UNION ALL

                   SELECT 'backend',
                          b.id,
                          b.name,
                          COALESCE(b.description, 'Schéma de données'),
                          b.updated_at
                     FROM backend_tables b
                    WHERE b.user_id = :user_id AND b.deleted_at IS NULL

                   UNION ALL

                   SELECT 'deploiement',
                          d.id,
                          d.branch || '@' || left(d.commit_sha, 7),
                          COALESCE(d.commit_message, 'Déploiement'),
                          d.updated_at
                     FROM deployments d
                    WHERE d.user_id = :user_id AND d.deleted_at IS NULL

                   UNION ALL

                   -- last_seen_at et non updated_at : pour une erreur, ce qui
                   -- constitue l'événement est sa dernière occurrence, pas la
                   -- dernière fois qu'on a changé son statut.
                   SELECT 'supervision',
                          g.id,
                          '×' || g.occurrences,
                          g.title,
                          g.last_seen_at
                     FROM error_groups g
                    WHERE g.user_id = :user_id AND g.deleted_at IS NULL

                   UNION ALL

                   SELECT 'design',
                          f.id,
                          'v' || COALESCE((SELECT MAX(number) FROM design_versions WHERE file_id = f.id), 1),
                          f.name,
                          f.updated_at
                     FROM design_files f
                    WHERE f.user_id = :user_id AND f.deleted_at IS NULL
               ) AS activite
              ORDER BY happened_at DESC
              LIMIT :limit",
        );

        $statement->bindValue('user_id', $userId);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): array => [
                'module'      => (string) $row['module'],
                'id'          => (string) $row['id'],
                'ref'         => (string) $row['ref'],
                'title'       => (string) $row['title'],
                'happened_at' => Database::toIso($row['happened_at']),
            ],
            $statement->fetchAll(),
        );
    }
}
