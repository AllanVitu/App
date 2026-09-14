<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Les sondes d'un espace, leur état, leurs relevés et leurs pannes.
 *
 * Chaque requête filtre par organization_id : l'identifiant d'une sonde d'un
 * autre espace ne répond jamais que « introuvable ».
 */
final class ProbeRepository
{
    /**
     * Au-delà, un espace surveille un parc de serveurs, pas un produit — c'est
     * un autre outil. La limite borne aussi ce que Relais émet vers Internet
     * pour le compte d'un seul espace.
     */
    public const QUOTA = 25;

    /**
     * La disponibilité se calcule sur trente jours : c'est la durée de
     * conservation des relevés, et celle qu'on annonce dans un engagement de
     * service. « down » seul compte comme indisponible — une page lente est
     * lente, pas absente.
     */
    private const COLUMNS = "p.id, p.name, p.url, p.method, p.interval_seconds, p.timeout_ms, p.slow_ms,
                             p.is_paused, p.version, p.created_at, p.updated_at,
                             s.last_outcome::text AS last_outcome, s.last_checked_at, s.last_response_ms,
                             s.last_http_status, s.last_error, s.next_check_at,
                             (SELECT i.started_at
                                FROM probe_incidents i
                               WHERE i.probe_id = p.id AND i.ended_at IS NULL) AS down_since,
                             (SELECT ROUND(100.0 * COUNT(*) FILTER (WHERE c.outcome <> 'down') / NULLIF(COUNT(*), 0), 2)
                                FROM probe_checks c
                               WHERE c.probe_id = p.id AND c.checked_at >= NOW() - INTERVAL '30 days') AS uptime_30d,
                             (SELECT COALESCE(json_agg(r ORDER BY r.checked_at), '[]'::json)
                                FROM (SELECT c.outcome, c.response_ms, c.checked_at
                                        FROM probe_checks c
                                       WHERE c.probe_id = p.id
                                       ORDER BY c.checked_at DESC
                                       LIMIT 30) r) AS recent";

    /**
     * Les sondes en panne d'abord : c'est la seule chose qu'on vient chercher
     * en ouvrant l'écran un jour de panne.
     *
     * @return list<array<string, mixed>>
     */
    public function listForOrganization(string $organizationId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . "
               FROM probes p
               JOIN probe_states s ON s.probe_id = p.id
              WHERE p.organization_id = :organization_id AND p.deleted_at IS NULL
              ORDER BY (s.last_outcome = 'down' AND NOT p.is_paused) DESC NULLS LAST, p.name",
        );
        $statement->execute(['organization_id' => $organizationId]);

        return array_map($this->hydrate(...), $statement->fetchAll());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id, string $organizationId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM probes p
               JOIN probe_states s ON s.probe_id = p.id
              WHERE p.id = :id AND p.organization_id = :organization_id AND p.deleted_at IS NULL',
        );
        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);

        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function countForOrganization(string $organizationId): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM probes WHERE organization_id = :organization_id AND deleted_at IS NULL',
        );
        $statement->execute(['organization_id' => $organizationId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array{name: string, url: string, method: string, interval_seconds: int, timeout_ms: int, slow_ms: int, is_paused: bool} $attributes
     *
     * @return array<string, mixed>
     */
    public function create(string $organizationId, ?string $authorId, array $attributes): array
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO probes (organization_id, created_by, name, url, method, interval_seconds, timeout_ms, slow_ms, is_paused)
             VALUES (:organization_id, :created_by, :name, :url, :method, :interval_seconds, :timeout_ms, :slow_ms, :is_paused)
             RETURNING id',
        );
        $statement->execute($this->bindings($attributes) + [
            'organization_id' => $organizationId,
            'created_by'      => $authorId,
        ]);

        /** @var array<string, mixed> $probe */
        $probe = $this->find((string) $statement->fetchColumn(), $organizationId);

        return $probe;
    }

    /**
     * @param array{name: string, url: string, method: string, interval_seconds: int, timeout_ms: int, slow_ms: int, is_paused: bool} $attributes
     *
     * @return array<string, mixed>|null
     */
    public function update(string $id, string $organizationId, array $attributes): ?array
    {
        $statement = Database::connection()->prepare(
            'UPDATE probes
                SET name             = :name,
                    url              = :url,
                    method           = :method,
                    interval_seconds = :interval_seconds,
                    timeout_ms       = :timeout_ms,
                    slow_ms          = :slow_ms,
                    is_paused        = :is_paused
              WHERE id = :id AND organization_id = :organization_id AND deleted_at IS NULL
              RETURNING id',
        );
        $statement->execute($this->bindings($attributes) + ['id' => $id, 'organization_id' => $organizationId]);

        return $statement->fetchColumn() === false ? null : $this->find($id, $organizationId);
    }

    public function softDelete(string $id, string $organizationId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE probes SET deleted_at = NOW()
              WHERE id = :id AND organization_id = :organization_id AND deleted_at IS NULL',
        );
        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);

        return $statement->rowCount() === 1;
    }

    public function restore(string $id, string $organizationId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE probes SET deleted_at = NULL
              WHERE id = :id AND organization_id = :organization_id AND deleted_at IS NOT NULL',
        );
        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);

        return $statement->rowCount() === 1;
    }

    /**
     * Avance l'échéance à maintenant : le worker appellera la sonde à son
     * prochain passage, dans la minute.
     *
     * Aucun appel sortant dans la requête HTTP elle-même : un bouton qui fait
     * émettre une requête au serveur, à chaque clic, pour le compte de qui
     * clique, serait une porte ouverte qu'aucune limite ne referme tout à fait.
     */
    public function checkSoon(string $id, string $organizationId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE probe_states s
                SET next_check_at = NOW()
               FROM probes p
              WHERE s.probe_id = p.id
                AND p.id = :id
                AND p.organization_id = :organization_id
                AND p.deleted_at IS NULL
                AND NOT p.is_paused',
        );
        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);

        return $statement->rowCount() === 1;
    }

    /**
     * Les derniers relevés et les dernières pannes d'une sonde.
     *
     * @return array{checks: list<array<string, mixed>>, incidents: list<array<string, mixed>>}
     */
    public function history(string $id, string $organizationId): array
    {
        $checks = Database::connection()->prepare(
            'SELECT checked_at, outcome::text AS outcome, http_status, response_ms, error
               FROM probe_checks
              WHERE probe_id = :id AND organization_id = :organization_id
              ORDER BY checked_at DESC
              LIMIT 50',
        );
        $checks->execute(['id' => $id, 'organization_id' => $organizationId]);

        $incidents = Database::connection()->prepare(
            'SELECT id, started_at, ended_at, cause,
                    EXTRACT(EPOCH FROM (COALESCE(ended_at, NOW()) - started_at))::int AS duration_seconds
               FROM probe_incidents
              WHERE probe_id = :id AND organization_id = :organization_id
              ORDER BY started_at DESC
              LIMIT 20',
        );
        $incidents->execute(['id' => $id, 'organization_id' => $organizationId]);

        return [
            'checks'    => array_map(
                static fn (array $row): array => [
                    'checked_at'  => Database::toIso($row['checked_at']),
                    'outcome'     => $row['outcome'],
                    'http_status' => $row['http_status'] === null ? null : (int) $row['http_status'],
                    'response_ms' => $row['response_ms'] === null ? null : (int) $row['response_ms'],
                    'error'       => $row['error'],
                ],
                $checks->fetchAll(),
            ),
            'incidents' => array_map(
                static fn (array $row): array => [
                    'id'               => $row['id'],
                    'started_at'       => Database::toIso($row['started_at']),
                    'ended_at'         => Database::toIso($row['ended_at']),
                    'cause'            => $row['cause'],
                    'duration_seconds' => (int) $row['duration_seconds'],
                ],
                $incidents->fetchAll(),
            ),
        ];
    }

    /**
     * Pour la tuile du module et le menu.
     *
     * @return array{total: int, down: int, slow: int, paused: int}
     */
    public function statsForOrganization(string $organizationId): array
    {
        $statement = Database::connection()->prepare(
            "SELECT COUNT(*)                                                          AS total,
                    COUNT(*) FILTER (WHERE NOT p.is_paused AND s.last_outcome = 'down') AS down,
                    COUNT(*) FILTER (WHERE NOT p.is_paused AND s.last_outcome = 'slow') AS slow,
                    COUNT(*) FILTER (WHERE p.is_paused)                                 AS paused
               FROM probes p
               JOIN probe_states s ON s.probe_id = p.id
              WHERE p.organization_id = :organization_id AND p.deleted_at IS NULL",
        );
        $statement->execute(['organization_id' => $organizationId]);

        $row = $statement->fetch() ?: [];

        return [
            'total'  => (int) ($row['total'] ?? 0),
            'down'   => (int) ($row['down'] ?? 0),
            'slow'   => (int) ($row['slow'] ?? 0),
            'paused' => (int) ($row['paused'] ?? 0),
        ];
    }

    /**
     * Les sondes en panne, puis les lentes, pour « Demande attention ».
     *
     * @return list<array{id: string, name: string, url: string, outcome: string, since: ?string, error: ?string, response_ms: ?int}>
     */
    public function needsAttention(string $organizationId, int $limit = 3): array
    {
        $statement = Database::connection()->prepare(
            "SELECT p.id, p.name, p.url, s.last_outcome::text AS outcome, s.last_error AS error,
                    s.last_response_ms AS response_ms,
                    COALESCE(
                        (SELECT i.started_at FROM probe_incidents i WHERE i.probe_id = p.id AND i.ended_at IS NULL),
                        s.last_checked_at
                    ) AS since
               FROM probes p
               JOIN probe_states s ON s.probe_id = p.id
              WHERE p.organization_id = :organization_id
                AND p.deleted_at IS NULL
                AND NOT p.is_paused
                AND s.last_outcome IN ('down', 'slow')
              ORDER BY (s.last_outcome = 'down') DESC, since
              LIMIT :limit",
        );
        $statement->bindValue('organization_id', $organizationId);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): array => [
                'id'          => (string) $row['id'],
                'name'        => (string) $row['name'],
                'url'         => (string) $row['url'],
                'outcome'     => (string) $row['outcome'],
                'since'       => Database::toIso($row['since']),
                'error'       => $row['error'] === null ? null : (string) $row['error'],
                'response_ms' => $row['response_ms'] === null ? null : (int) $row['response_ms'],
            ],
            $statement->fetchAll(),
        );
    }

    /**
     * La disponibilité de l'espace sur trente jours, toutes sondes confondues,
     * et ce qu'ont coûté ses pannes.
     *
     * @return array{uptime: ?float, incidents: int, downtime_seconds: int}
     */
    public function availability(string $organizationId): array
    {
        $statement = Database::connection()->prepare(
            "SELECT (SELECT ROUND(100.0 * COUNT(*) FILTER (WHERE outcome <> 'down') / NULLIF(COUNT(*), 0), 2)
                       FROM probe_checks
                      WHERE organization_id = :organization_id
                        AND checked_at >= NOW() - INTERVAL '30 days') AS uptime,
                    (SELECT COUNT(*)
                       FROM probe_incidents
                      WHERE organization_id = :organization_id
                        AND started_at >= NOW() - INTERVAL '30 days') AS incidents,
                    (SELECT COALESCE(SUM(EXTRACT(EPOCH FROM (COALESCE(ended_at, NOW()) - started_at))), 0)::int
                       FROM probe_incidents
                      WHERE organization_id = :organization_id
                        AND started_at >= NOW() - INTERVAL '30 days') AS downtime",
        );
        $statement->execute(['organization_id' => $organizationId]);

        $row = $statement->fetch() ?: [];

        return [
            'uptime'           => isset($row['uptime']) ? (float) $row['uptime'] : null,
            'incidents'        => (int) ($row['incidents'] ?? 0),
            'downtime_seconds' => (int) ($row['downtime'] ?? 0),
        ];
    }

    /**
     * @param array{name: string, url: string, method: string, interval_seconds: int, timeout_ms: int, slow_ms: int, is_paused: bool} $attributes
     *
     * @return array<string, mixed>
     */
    private function bindings(array $attributes): array
    {
        return [
            'name'             => $attributes['name'],
            'url'              => $attributes['url'],
            'method'           => $attributes['method'],
            'interval_seconds' => $attributes['interval_seconds'],
            'timeout_ms'       => $attributes['timeout_ms'],
            'slow_ms'          => $attributes['slow_ms'],
            'is_paused'        => $attributes['is_paused'] ? 'true' : 'false',
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $recent = json_decode((string) ($row['recent'] ?? '[]'), true);

        return [
            'id'               => (string) $row['id'],
            'name'             => (string) $row['name'],
            'url'              => (string) $row['url'],
            'method'           => (string) $row['method'],
            'interval_seconds' => (int) $row['interval_seconds'],
            'timeout_ms'       => (int) $row['timeout_ms'],
            'slow_ms'          => (int) $row['slow_ms'],
            'is_paused'        => (bool) $row['is_paused'],
            'version'          => (int) $row['version'],
            'created_at'       => Database::toIso($row['created_at']),
            'updated_at'       => Database::toIso($row['updated_at']),
            'last_outcome'     => $row['last_outcome'],
            'last_checked_at'  => Database::toIso($row['last_checked_at']),
            'last_response_ms' => $row['last_response_ms'] === null ? null : (int) $row['last_response_ms'],
            'last_http_status' => $row['last_http_status'] === null ? null : (int) $row['last_http_status'],
            'last_error'       => $row['last_error'],
            'next_check_at'    => Database::toIso($row['next_check_at']),
            'down_since'       => Database::toIso($row['down_since']),
            'uptime_30d'       => $row['uptime_30d'] === null ? null : (float) $row['uptime_30d'],
            'recent'           => is_array($recent) ? $recent : [],
        ];
    }
}
