<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Module « Déploiement ».
 *
 * Comme tous les dépôts, CHAQUE requête est filtrée sur organization_id.
 *
 * « finished_at » et « duration_ms » ne figurent dans aucune écriture : ils
 * sont dérivés du statut par trigger (cf. 07_modules.sql). Les fournir ici
 * dupliquerait la règle et la ferait diverger tôt ou tard.
 */
final class DeploymentRepository
{
    private const SORTABLE = ['created_at', 'finished_at', 'duration_ms', 'branch'];

    /**
     * Sous-requête scalaire plutôt que jointure : la liste sert aussi bien à
     * des SELECT qu'à des RETURNING, et ces derniers n'acceptent pas de JOIN.
     * « created_by » y reste sans qualificatif pour valoir dans les deux cas.
     */
    private const COLUMNS = 'id, environment, branch, commit_sha, commit_message, status,
                             url, log, finished_at, duration_ms, created_at, updated_at, created_by,
                             (SELECT u.full_name FROM users u WHERE u.id = created_by) AS author_name';

    /**
     * @param array{environment?: string|null, status?: string|null, branch?: string|null,
     *              search?: string|null, sort?: string|null, direction?: string|null} $filters
     * @return array{deployments: list<array<string, mixed>>, total: int}
     */
    public function search(
        string $organizationId,
        array $filters,
        int $limit = 200,
        int $offset = 0,
    ): array {
        $conditions = ['organization_id = :organization_id', 'deleted_at IS NULL'];
        $params     = ['organization_id' => $organizationId];

        if (!empty($filters['environment'])) {
            $conditions[]          = 'environment = :environment::deployment_env';
            $params['environment'] = $filters['environment'];
        }

        if (!empty($filters['status'])) {
            $conditions[]     = 'status = :status::deployment_status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['branch'])) {
            $conditions[]     = 'branch = :branch';
            $params['branch'] = $filters['branch'];
        }

        if (!empty($filters['search'])) {
            $conditions[]     = '(branch ILIKE :search OR commit_message ILIKE :search OR commit_sha ILIKE :search)';
            $params['search'] = '%' . $this->escapeLike($filters['search']) . '%';
        }

        $where = implode(' AND ', $conditions);

        $countStatement = Database::connection()->prepare(
            "SELECT COUNT(*) FROM deployments WHERE {$where}",
        );
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $sort      = in_array($filters['sort'] ?? '', self::SORTABLE, true) ? $filters['sort'] : 'created_at';
        $direction = strtoupper($filters['direction'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . "
               FROM deployments
              WHERE {$where}
              ORDER BY {$sort} {$direction} NULLS LAST, created_at DESC
              LIMIT :limit OFFSET :offset",
        );

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }

        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return [
            'deployments' => array_map($this->hydrate(...), $statement->fetchAll()),
            'total'       => $total,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id, string $organizationId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM deployments
              WHERE id = :id AND organization_id = :organization_id AND deleted_at IS NULL',
        );

        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function create(string $organizationId, ?string $authorId, array $attributes): array
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO deployments (organization_id, created_by, environment, branch, commit_sha, commit_message, status, url, log)
             VALUES (
                 :organization_id,
                 :created_by,
                 :environment::deployment_env,
                 :branch,
                 :commit_sha,
                 :commit_message,
                 :status::deployment_status,
                 :url,
                 :log
             )
             RETURNING ' . self::COLUMNS,
        );

        $statement->execute($this->bindings($attributes) + [
            'organization_id' => $organizationId,
            'created_by'      => $authorId,
        ]);

        /** @var array<string, mixed> $row */
        $row = $statement->fetch();

        return $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>|null
     */
    public function update(string $id, string $organizationId, array $attributes): ?array
    {
        $statement = Database::connection()->prepare(
            'UPDATE deployments
                SET environment    = :environment::deployment_env,
                    branch         = :branch,
                    commit_sha     = :commit_sha,
                    commit_message = :commit_message,
                    status         = :status::deployment_status,
                    url            = :url,
                    log            = :log
              WHERE id = :id AND organization_id = :organization_id AND deleted_at IS NULL
          RETURNING ' . self::COLUMNS,
        );

        $statement->execute($this->bindings($attributes) + ['id' => $id, 'organization_id' => $organizationId]);

        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function softDelete(string $id, string $organizationId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE deployments
                SET deleted_at = NOW()
              WHERE id = :id AND organization_id = :organization_id AND deleted_at IS NULL',
        );

        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);

        return $statement->rowCount() > 0;
    }

    /**
     * Restauration d'un déploiement.
     *
     * Un déploiement est un FAIT daté : le restaurer ne relance rien, il rend
     * seulement sa trace visible. Son statut, sa durée et son journal sont
     * exactement ceux qu'il avait.
     */
    public function restore(string $id, string $organizationId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE deployments
                SET deleted_at = NULL
              WHERE id = :id AND organization_id = :organization_id AND deleted_at IS NOT NULL',
        );

        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);

        return $statement->rowCount() > 0;
    }

    /**
     * Branches déjà déployées, pour le filtre.
     *
     * @return list<string>
     */
    public function branchesForOrganization(string $organizationId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT DISTINCT branch
               FROM deployments
              WHERE organization_id = :organization_id AND deleted_at IS NULL
              ORDER BY branch',
        );

        $statement->execute(['organization_id' => $organizationId]);

        return array_map(static fn (array $row): string => (string) $row['branch'], $statement->fetchAll());
    }

    /**
     * Déploiements en échec — alimente le tableau de bord.
     *
     * La production passe avant la prévisualisation : une branche de travail
     * qui ne compile pas gêne son auteur, une production en échec gêne les
     * utilisateurs.
     *
     * @return list<array<string, mixed>>
     */
    public function needsAttention(string $organizationId, int $limit = 3): array
    {
        $statement = Database::connection()->prepare(
            // La MÊME liste de colonnes que partout ailleurs : hydrate() les
            // attend toutes, et une projection partielle produirait des clés
            // manquantes plutôt qu'une erreur franche.
            'SELECT ' . self::COLUMNS . "
               FROM deployments
              WHERE organization_id = :organization_id
                AND deleted_at IS NULL
                AND status = 'error'
              ORDER BY (environment = 'production') DESC, created_at DESC
              LIMIT :limit",
        );

        $statement->bindValue('organization_id', $organizationId);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map($this->hydrate(...), $statement->fetchAll());
    }

    /**
     * @return array{total: int, running: int, ready: int, failed: int, production: int,
     *               last_success_at: string|null, median_ms: int}
     */
    public function statsForOrganization(string $organizationId): array
    {
        $statement = Database::connection()->prepare(
            "SELECT
                 COUNT(*)                                                         AS total,
                 COUNT(*) FILTER (WHERE status IN ('queued', 'building'))          AS running,
                 COUNT(*) FILTER (WHERE status = 'ready')                          AS ready,
                 COUNT(*) FILTER (WHERE status = 'error')                          AS failed,
                 COUNT(*) FILTER (WHERE environment = 'production')                AS production,
                 MAX(finished_at) FILTER (WHERE status = 'ready')                  AS last_success_at,
                 -- MÉDIANE et non moyenne : un seul déploiement anormalement
                 -- long fausserait durablement une moyenne, alors que la
                 -- médiane décrit la durée réellement observée d'habitude.
                 COALESCE(
                     percentile_cont(0.5) WITHIN GROUP (ORDER BY duration_ms)
                         FILTER (WHERE status = 'ready' AND duration_ms IS NOT NULL),
                     0
                 )                                                                 AS median_ms
               FROM deployments
              WHERE organization_id = :organization_id AND deleted_at IS NULL",
        );

        $statement->execute(['organization_id' => $organizationId]);
        $row = $statement->fetch() ?: [];

        return [
            'total'           => (int) ($row['total'] ?? 0),
            'running'         => (int) ($row['running'] ?? 0),
            'ready'           => (int) ($row['ready'] ?? 0),
            'failed'          => (int) ($row['failed'] ?? 0),
            'production'      => (int) ($row['production'] ?? 0),
            'last_success_at' => Database::toIso($row['last_success_at'] ?? null),
            'median_ms'       => (int) round((float) ($row['median_ms'] ?? 0)),
        ];
    }

    /**
     * @param  array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function bindings(array $attributes): array
    {
        return [
            'environment'    => $attributes['environment'],
            'branch'         => $attributes['branch'],
            'commit_sha'     => $attributes['commit_sha'],
            'commit_message' => $attributes['commit_message'],
            'status'         => $attributes['status'],
            'url'            => $attributes['url'],
            'log'            => $attributes['log'],
        ];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id'             => (string) $row['id'],
            'environment'    => (string) $row['environment'],
            'branch'         => (string) $row['branch'],
            'commit_sha'     => (string) $row['commit_sha'],
            'commit_message' => $row['commit_message'] !== null ? (string) $row['commit_message'] : null,
            'status'         => (string) $row['status'],
            'url'            => $row['url'] !== null ? (string) $row['url'] : null,
            'log'            => $row['log'] !== null ? (string) $row['log'] : null,
            'finished_at'    => Database::toIso($row['finished_at']),
            'duration_ms'    => $row['duration_ms'] !== null ? (int) $row['duration_ms'] : null,
            'created_at'     => Database::toIso($row['created_at']),
            'updated_at'     => Database::toIso($row['updated_at']),
            // « Qui a lancé ça ? » — la question qu'on pose devant un
            // déploiement en échec qu'on n'a pas déclenché soi-même.
            'created_by'     => $row['created_by'] !== null ? (string) $row['created_by'] : null,
            'author_name'    => $row['author_name'] !== null ? (string) $row['author_name'] : null,
        ];
    }
}
