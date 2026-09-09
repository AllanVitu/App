<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Module « Supervision » : erreurs de production, groupées.
 *
 * Comme tous les dépôts, CHAQUE requête est filtrée sur user_id — y compris
 * pour les occurrences, dont la table porte une copie de user_id pour que le
 * cloisonnement ne dépende jamais d'une jointure correctement écrite.
 *
 * Le nombre d'occurrences et la date de dernière vue sont entretenus par
 * trigger à l'insertion d'une occurrence : ils ne sont jamais écrits ici.
 */
final class ErrorRepository
{
    private const SORTABLE = ['last_seen_at', 'first_seen_at', 'occurrences', 'title'];

    private const COLUMNS = 'id, fingerprint, title, culprit, level, status, occurrences,
                             first_seen_at, last_seen_at, created_at, updated_at';

    /**
     * @param array{status?: string|null, level?: string|null, search?: string|null,
     *              sort?: string|null, direction?: string|null} $filters
     * @return array{groups: list<array<string, mixed>>, total: int}
     */
    public function search(
        string $userId,
        array $filters,
        int $limit = 200,
        int $offset = 0,
    ): array {
        $conditions = ['user_id = :user_id', 'deleted_at IS NULL'];
        $params     = ['user_id' => $userId];

        if (!empty($filters['status'])) {
            $conditions[]     = 'status = :status::error_status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['level'])) {
            $conditions[]    = 'level = :level::error_level';
            $params['level'] = $filters['level'];
        }

        if (!empty($filters['search'])) {
            $conditions[]     = '(title ILIKE :search OR culprit ILIKE :search)';
            $params['search'] = '%' . $this->escapeLike($filters['search']) . '%';
        }

        $where = implode(' AND ', $conditions);

        $countStatement = Database::connection()->prepare(
            "SELECT COUNT(*) FROM error_groups WHERE {$where}",
        );
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $sort      = in_array($filters['sort'] ?? '', self::SORTABLE, true) ? $filters['sort'] : 'last_seen_at';
        $direction = strtoupper($filters['direction'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . "
               FROM error_groups
              WHERE {$where}
              ORDER BY {$sort} {$direction} NULLS LAST, last_seen_at DESC
              LIMIT :limit OFFSET :offset",
        );

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }

        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return [
            'groups' => array_map($this->hydrateGroup(...), $statement->fetchAll()),
            'total'  => $total,
        ];
    }

    /**
     * Un groupe et ses dernières occurrences.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id, string $userId, int $events = 20): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM error_groups
              WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL',
        );

        $statement->execute(['id' => $id, 'user_id' => $userId]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        $group = $this->hydrateGroup($row);
        $group['events'] = $this->eventsForGroup($id, $userId, $events);

        return $group;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function eventsForGroup(string $groupId, string $userId, int $limit = 20): array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, message, stack, context, occurred_at
               FROM error_events
              WHERE group_id = :group_id AND user_id = :user_id
              ORDER BY occurred_at DESC
              LIMIT :limit',
        );

        $statement->bindValue('group_id', $groupId);
        $statement->bindValue('user_id', $userId);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): array => [
                'id'          => (string) $row['id'],
                'message'     => (string) $row['message'],
                'stack'       => $row['stack'] !== null ? (string) $row['stack'] : null,
                'context'     => Database::toObject($row['context']),
                'occurred_at' => Database::toIso($row['occurred_at']),
            ],
            $statement->fetchAll(),
        );
    }

    /**
     * Enregistre une occurrence, en créant le groupe s'il est nouveau.
     *
     * Tout se joue dans UNE transaction : le groupe et sa première occurrence
     * apparaissent ensemble ou pas du tout. Un groupe créé sans occurrence
     * afficherait « 0 occurrence » dans la liste, ce qui n'a aucun sens.
     *
     * @param  array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function record(string $userId, array $attributes): array
    {
        return Database::transaction(function () use ($userId, $attributes): array {
            // ON CONFLICT sur (user_id, fingerprint) : c'est l'empreinte qui
            // regroupe. DO UPDATE plutôt que DO NOTHING, car il faut que
            // RETURNING renvoie une ligne dans les deux cas — avec DO NOTHING,
            // un conflit ne renvoie rien et il faudrait une seconde requête.
            $group = Database::connection()->prepare(
                'INSERT INTO error_groups (user_id, fingerprint, title, culprit, level)
                 VALUES (:user_id, :fingerprint, :title, :culprit, :level::error_level)
                 ON CONFLICT (user_id, fingerprint) DO UPDATE
                        SET title   = EXCLUDED.title,
                            culprit = EXCLUDED.culprit,
                            level   = EXCLUDED.level
                 RETURNING id',
            );

            $group->execute([
                'user_id'     => $userId,
                'fingerprint' => $attributes['fingerprint'],
                'title'       => $attributes['title'],
                'culprit'     => $attributes['culprit'],
                'level'       => $attributes['level'],
            ]);

            $groupId = (string) $group->fetchColumn();

            $event = Database::connection()->prepare(
                'INSERT INTO error_events (group_id, user_id, message, stack, context)
                 VALUES (:group_id, :user_id, :message, :stack, :context::jsonb)',
            );

            $event->execute([
                'group_id' => $groupId,
                'user_id'  => $userId,
                'message'  => $attributes['message'],
                'stack'    => $attributes['stack'],
                'context'  => json_encode($attributes['context'], JSON_UNESCAPED_UNICODE),
            ]);

            // Relu APRÈS l'occurrence : c'est le trigger qui a incrémenté le
            // compteur et remonté last_seen_at, la ligne lue plus tôt serait
            // déjà périmée.
            /** @var array<string, mixed> $created */
            $created = $this->find($groupId, $userId, 5);

            return $created;
        });
    }

    /**
     * Seul le statut se modifie depuis l'interface : le reste décrit ce qui
     * s'est produit et n'a pas à être réécrit à la main.
     *
     * @return array<string, mixed>|null
     */
    public function updateStatus(string $id, string $userId, string $status): ?array
    {
        $statement = Database::connection()->prepare(
            'UPDATE error_groups
                SET status = :status::error_status
              WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL
          RETURNING ' . self::COLUMNS,
        );

        $statement->execute(['id' => $id, 'user_id' => $userId, 'status' => $status]);

        $row = $statement->fetch();

        return $row === false ? null : $this->hydrateGroup($row);
    }

    public function softDelete(string $id, string $userId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE error_groups
                SET deleted_at = NOW()
              WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL',
        );

        $statement->execute(['id' => $id, 'user_id' => $userId]);

        return $statement->rowCount() > 0;
    }

    /**
     * Restauration d'un groupe d'erreurs.
     *
     * Les occurrences ne sont pas supprimées avec le groupe — elles y sont
     * rattachées par clé étrangère et restent en base. Le groupe restauré
     * retrouve donc son compte exact, y compris les occurrences arrivées
     * pendant qu'il était masqué.
     */
    public function restore(string $id, string $userId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE error_groups
                SET deleted_at = NULL
              WHERE id = :id AND user_id = :user_id AND deleted_at IS NOT NULL',
        );

        $statement->execute(['id' => $id, 'user_id' => $userId]);

        return $statement->rowCount() > 0;
    }

    /**
     * Erreurs qui demandent une action — alimente le tableau de bord.
     *
     * Les groupes IGNORÉS sont exclus : ignorer est une décision explicite de
     * ne plus vouloir en entendre parler, un tableau de bord qui les
     * ressortirait la contredirait.
     *
     * @return list<array<string, mixed>>
     */
    public function needsAttention(string $userId, int $limit = 3): array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . "
               FROM error_groups
              WHERE user_id = :user_id
                AND deleted_at IS NULL
                AND status = 'unresolved'
              -- Le niveau prime sur la fraîcheur : une erreur fatale d'hier
              -- passe avant un avertissement de ce matin.
              ORDER BY level DESC, last_seen_at DESC
              LIMIT :limit",
        );

        $statement->bindValue('user_id', $userId);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map($this->hydrateGroup(...), $statement->fetchAll());
    }

    /**
     * Occurrences par jour sur la période, pour la courbe de la liste.
     *
     * generate_series produit TOUS les jours, y compris ceux sans erreur :
     * sans cela, la courbe relierait deux pics en sautant les jours calmes
     * et donnerait l'impression d'un problème continu.
     *
     * @return list<array{date: string, count: int}>
     */
    public function dailyCounts(string $userId, int $days = 14): array
    {
        $statement = Database::connection()->prepare(
            "SELECT d.day::date AS date, COUNT(e.id) AS count
               FROM generate_series(
                        CURRENT_DATE - make_interval(days => :days - 1),
                        CURRENT_DATE,
                        INTERVAL '1 day'
                    ) AS d(day)
               LEFT JOIN error_events e
                 ON e.user_id = :user_id
                AND e.occurred_at >= d.day
                AND e.occurred_at <  d.day + INTERVAL '1 day'
              GROUP BY d.day
              ORDER BY d.day",
        );

        $statement->bindValue('user_id', $userId);
        $statement->bindValue('days', $days, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): array => [
                'date'  => (string) $row['date'],
                'count' => (int) $row['count'],
            ],
            $statement->fetchAll(),
        );
    }

    /**
     * @return array{groups: int, unresolved: int, resolved: int, ignored: int,
     *               fatal: int, events: int, events_24h: int}
     */
    public function statsForUser(string $userId): array
    {
        $statement = Database::connection()->prepare(
            "SELECT
                 COUNT(*)                                                     AS groups,
                 COUNT(*) FILTER (WHERE status = 'unresolved')                 AS unresolved,
                 COUNT(*) FILTER (WHERE status = 'resolved')                   AS resolved,
                 COUNT(*) FILTER (WHERE status = 'ignored')                    AS ignored,
                 COUNT(*) FILTER (WHERE level = 'fatal' AND status = 'unresolved') AS fatal,
                 COALESCE(SUM(occurrences), 0)                                 AS events
               FROM error_groups
              WHERE user_id = :user_id AND deleted_at IS NULL",
        );

        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch() ?: [];

        $recent = Database::connection()->prepare(
            "SELECT COUNT(*) FROM error_events
              WHERE user_id = :user_id AND occurred_at > NOW() - INTERVAL '24 hours'",
        );
        $recent->execute(['user_id' => $userId]);

        return [
            'groups'     => (int) ($row['groups'] ?? 0),
            'unresolved' => (int) ($row['unresolved'] ?? 0),
            'resolved'   => (int) ($row['resolved'] ?? 0),
            'ignored'    => (int) ($row['ignored'] ?? 0),
            'fatal'      => (int) ($row['fatal'] ?? 0),
            'events'     => (int) ($row['events'] ?? 0),
            'events_24h' => (int) $recent->fetchColumn(),
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
    private function hydrateGroup(array $row): array
    {
        return [
            'id'            => (string) $row['id'],
            'fingerprint'   => (string) $row['fingerprint'],
            'title'         => (string) $row['title'],
            'culprit'       => $row['culprit'] !== null ? (string) $row['culprit'] : null,
            'level'         => (string) $row['level'],
            'status'        => (string) $row['status'],
            'occurrences'   => (int) $row['occurrences'],
            'first_seen_at' => Database::toIso($row['first_seen_at']),
            'last_seen_at'  => Database::toIso($row['last_seen_at']),
            'created_at'    => Database::toIso($row['created_at']),
            'updated_at'    => Database::toIso($row['updated_at']),
        ];
    }
}
