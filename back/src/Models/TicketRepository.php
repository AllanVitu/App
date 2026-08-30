<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Accès aux tickets (table tickets).
 *
 * Comme pour les autres dépôts, CHAQUE requête est filtrée sur user_id :
 * aucune méthode ne permet de lire ou d'écrire un ticket sans fournir son
 * propriétaire. C'est le point de contrôle du cloisonnement entre comptes.
 *
 * Le champ « labels » est un text[] PostgreSQL. Il transite en JSON dans les
 * deux sens plutôt qu'en littéral de tableau ({a,b}) : ce littéral exige un
 * échappement propre des virgules, guillemets et accolades présents dans les
 * valeurs, que la conversion par jsonb rend inutile. PDO ne sachant pas lier
 * un tableau, c'est la voie sûre.
 */
final class TicketRepository
{
    /** Tri autorisé — liste blanche, un ORDER BY ne pouvant pas être paramétré. */
    private const SORTABLE = ['created_at', 'updated_at', 'number', 'due_date', 'priority', 'title'];

    /** Colonnes exposées par l'API, réutilisées par toutes les requêtes. */
    private const COLUMNS = 'id, number, title, description, status, priority, project,
                             to_jsonb(labels) AS labels, due_date, completed_at,
                             created_at, updated_at';

    /**
     * Liste filtrée.
     *
     * Un suivi personnel tient en quelques centaines de lignes : elles sont
     * renvoyées d'un bloc, ce qui permet au front de filtrer et de regrouper
     * instantanément au clavier. La borne dure évite qu'un compte très chargé
     * ne rende la réponse ingérable.
     *
     * @param array{status?: string|null, priority?: string|null, project?: string|null,
     *              label?: string|null, search?: string|null, overdue?: bool,
     *              sort?: string|null, direction?: string|null} $filters
     * @return array{tickets: list<array<string, mixed>>, total: int}
     */
    public function search(string $userId, array $filters, int $limit = 500): array
    {
        $conditions = ['t.user_id = :user_id', 't.deleted_at IS NULL'];
        $params     = ['user_id' => $userId];

        if (!empty($filters['status'])) {
            $conditions[]     = 't.status = :status::ticket_status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['priority'])) {
            $conditions[]       = 't.priority = :priority::ticket_priority';
            $params['priority'] = $filters['priority'];
        }

        if (!empty($filters['project'])) {
            $conditions[]      = 't.project = :project';
            $params['project'] = $filters['project'];
        }

        if (!empty($filters['label'])) {
            // @> teste l'inclusion et sait utiliser l'index GIN, là où
            // « :label = ANY(labels) » forcerait un parcours complet.
            $conditions[]    = 't.labels @> ARRAY[:label]::text[]';
            $params['label'] = $filters['label'];
        }

        if (!empty($filters['search'])) {
            $conditions[]     = '(t.title ILIKE :search OR t.description ILIKE :search)';
            $params['search'] = '%' . $this->escapeLike($filters['search']) . '%';
        }

        if (!empty($filters['overdue'])) {
            // « En retard » n'a de sens que pour un ticket encore ouvert :
            // un ticket terminé après son échéance n'est plus une alerte.
            $conditions[] = "t.due_date < CURRENT_DATE AND t.status NOT IN ('done', 'canceled')";
        }

        $where = implode(' AND ', $conditions);

        $countStatement = Database::connection()->prepare(
            "SELECT COUNT(*) FROM tickets t WHERE {$where}",
        );
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $sort      = in_array($filters['sort'] ?? '', self::SORTABLE, true) ? $filters['sort'] : 'created_at';
        $direction = strtoupper($filters['direction'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . "
               FROM tickets t
              WHERE {$where}
              ORDER BY t.{$sort} {$direction} NULLS LAST, t.number DESC
              LIMIT :limit",
        );

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }

        // EMULATE_PREPARES étant désactivé, un entier passé en chaîne serait
        // refusé par PostgreSQL sur un LIMIT.
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return [
            'tickets' => array_map($this->hydrate(...), $statement->fetchAll()),
            'total'   => $total,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id, string $userId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM tickets t
              WHERE t.id = :id AND t.user_id = :user_id AND t.deleted_at IS NULL',
        );

        $statement->execute(['id' => $id, 'user_id' => $userId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function create(string $userId, array $attributes): array
    {
        // « number » et « completed_at » sont absents de l'INSERT : ils sont
        // posés par les triggers (cf. 06_tickets.sql). Les fournir ici
        // dupliquerait la règle et la ferait diverger tôt ou tard.
        $statement = Database::connection()->prepare(
            'INSERT INTO tickets (user_id, title, description, status, priority, project, labels, due_date)
             VALUES (
                 :user_id,
                 :title,
                 :description,
                 :status::ticket_status,
                 :priority::ticket_priority,
                 :project,
                 COALESCE((SELECT array_agg(value) FROM jsonb_array_elements_text(:labels::jsonb)), \'{}\'),
                 :due_date
             )
             RETURNING ' . self::COLUMNS,
        );

        $statement->execute($this->bindings($userId, $attributes));

        /** @var array<string, mixed> $row */
        $row = $statement->fetch();

        return $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>|null
     */
    public function update(string $id, string $userId, array $attributes): ?array
    {
        $statement = Database::connection()->prepare(
            'UPDATE tickets
                SET title       = :title,
                    description = :description,
                    status      = :status::ticket_status,
                    priority    = :priority::ticket_priority,
                    project     = :project,
                    labels      = COALESCE(
                                      (SELECT array_agg(value) FROM jsonb_array_elements_text(:labels::jsonb)),
                                      \'{}\'
                                  ),
                    due_date    = :due_date
              WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL
          RETURNING ' . self::COLUMNS,
        );

        $statement->execute($this->bindings($userId, $attributes) + ['id' => $id]);

        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Suppression logique : la ligne reste, le numéro n'est jamais réattribué.
     */
    public function softDelete(string $id, string $userId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE tickets
                SET deleted_at = NOW()
              WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL',
        );

        $statement->execute(['id' => $id, 'user_id' => $userId]);

        return $statement->rowCount() > 0;
    }

    /**
     * Indicateurs du module, en une seule requête — alimente la tuile d'état
     * du tableau de bord.
     *
     * @return array{total: int, open: int, backlog: int, todo: int, in_progress: int,
     *               done: int, canceled: int, urgent: int, overdue: int, closed_this_week: int}
     */
    public function statsForUser(string $userId): array
    {
        $statement = Database::connection()->prepare(
            "SELECT
                 COUNT(*)                                                             AS total,
                 COUNT(*) FILTER (WHERE status NOT IN ('done', 'canceled'))           AS open,
                 COUNT(*) FILTER (WHERE status = 'backlog')                           AS backlog,
                 COUNT(*) FILTER (WHERE status = 'todo')                              AS todo,
                 COUNT(*) FILTER (WHERE status = 'in_progress')                       AS in_progress,
                 COUNT(*) FILTER (WHERE status = 'done')                              AS done,
                 COUNT(*) FILTER (WHERE status = 'canceled')                          AS canceled,
                 COUNT(*) FILTER (WHERE priority = 'urgent'
                                    AND status NOT IN ('done', 'canceled'))           AS urgent,
                 COUNT(*) FILTER (WHERE due_date < CURRENT_DATE
                                    AND status NOT IN ('done', 'canceled'))           AS overdue,
                 COUNT(*) FILTER (WHERE completed_at > NOW() - INTERVAL '7 days')     AS closed_this_week
               FROM tickets
              WHERE user_id = :user_id AND deleted_at IS NULL",
        );

        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch() ?: [];

        $counts = [];

        foreach (['total', 'open', 'backlog', 'todo', 'in_progress', 'done',
            'canceled', 'urgent', 'overdue', 'closed_this_week'] as $key) {
            $counts[$key] = (int) ($row[$key] ?? 0);
        }

        /** @var array{total: int, open: int, backlog: int, todo: int, in_progress: int,
         *             done: int, canceled: int, urgent: int, overdue: int, closed_this_week: int} $counts */
        return $counts;
    }

    /**
     * Tickets qui demandent une action — alimente le tableau de bord.
     *
     * Deux motifs seulement : l'échéance dépassée et la priorité urgente. Un
     * tableau de bord qui signale tout ne signale rien ; on s'en tient donc à
     * ce qui justifie d'interrompre ce qu'on est en train de faire.
     *
     * Le motif est calculé PAR SQL et non déduit côté client : c'est la même
     * définition que celle des compteurs, elle ne peut donc pas en diverger.
     *
     * @return list<array<string, mixed>>
     */
    public function needsAttention(string $userId, int $limit = 5): array
    {
        $statement = Database::connection()->prepare(
            // « due_date < CURRENT_DATE » vaut NULL, et non FALSE, quand le
            // ticket n'a pas d'échéance — et ORDER BY … DESC place les NULL
            // en PREMIER. Sans le test explicite de non-nullité, un ticket
            // urgent sans échéance passait donc devant un ticket réellement
            // en retard. Le prédicat est nommé une fois et réutilisé.
            "SELECT id, number, title, status, priority, due_date,
                    (due_date IS NOT NULL AND due_date < CURRENT_DATE) AS is_overdue
               FROM tickets
              WHERE user_id = :user_id
                AND deleted_at IS NULL
                AND status NOT IN ('done', 'canceled')
                AND ((due_date IS NOT NULL AND due_date < CURRENT_DATE) OR priority = 'urgent')
              -- Le retard passe avant l'urgence : une échéance dépassée est un
              -- fait, une priorité n'est qu'une intention.
              ORDER BY (due_date IS NOT NULL AND due_date < CURRENT_DATE) DESC,
                       due_date ASC NULLS LAST,
                       priority DESC
              LIMIT :limit",
        );

        $statement->bindValue('user_id', $userId);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): array => [
                'id'       => (string) $row['id'],
                'number'   => (int) $row['number'],
                'title'    => (string) $row['title'],
                'status'   => (string) $row['status'],
                'priority' => (string) $row['priority'],
                'due_date' => $row['due_date'],
                'reason'   => Database::toBool($row['is_overdue']) ? 'overdue' : 'urgent',
            ],
            $statement->fetchAll(),
        );
    }

    /**
     * Projets déjà utilisés, pour l'autocomplétion à la saisie.
     *
     * @return list<string>
     */
    public function projectsForUser(string $userId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT DISTINCT project
               FROM tickets
              WHERE user_id = :user_id AND deleted_at IS NULL AND project IS NOT NULL
              ORDER BY project',
        );

        $statement->execute(['user_id' => $userId]);

        return array_map(static fn (array $row): string => (string) $row['project'], $statement->fetchAll());
    }

    /**
     * Étiquettes déjà utilisées, avec leur fréquence.
     *
     * unnest « déplie » le tableau : une ligne par étiquette et par ticket,
     * qu'un GROUP BY regroupe ensuite.
     *
     * @return list<array{label: string, count: int}>
     */
    public function labelsForUser(string $userId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT label, COUNT(*) AS count
               FROM tickets, unnest(labels) AS label
              WHERE user_id = :user_id AND deleted_at IS NULL
              GROUP BY label
              ORDER BY count DESC, label',
        );

        $statement->execute(['user_id' => $userId]);

        return array_map(
            static fn (array $row): array => [
                'label' => (string) $row['label'],
                'count' => (int) $row['count'],
            ],
            $statement->fetchAll(),
        );
    }

    /**
     * Paramètres communs à la création et à la mise à jour.
     *
     * @param  array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function bindings(string $userId, array $attributes): array
    {
        return [
            'user_id'     => $userId,
            'title'       => $attributes['title'],
            'description' => $attributes['description'],
            'status'      => $attributes['status'],
            'priority'    => $attributes['priority'],
            'project'     => $attributes['project'],
            // JSON_UNESCAPED_UNICODE : sans lui, une étiquette accentuée
            // serait stockée sous sa forme échappée (é).
            'labels'      => json_encode(array_values($attributes['labels']), JSON_UNESCAPED_UNICODE),
            'due_date'    => $attributes['due_date'],
        ];
    }

    /**
     * Neutralise les jokers LIKE saisis par l'utilisateur, pour qu'une
     * recherche « 100% » ne devienne pas un motif « tout correspond ».
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $labels = json_decode((string) $row['labels'], true);

        return [
            'id'           => (string) $row['id'],
            'number'       => (int) $row['number'],
            'title'        => (string) $row['title'],
            'description'  => $row['description'] !== null ? (string) $row['description'] : null,
            'status'       => (string) $row['status'],
            'priority'     => (string) $row['priority'],
            'project'      => $row['project'] !== null ? (string) $row['project'] : null,
            'labels'       => is_array($labels) ? array_values($labels) : [],
            // due_date est une DATE nue : « AAAA-MM-JJ » est déjà le format
            // attendu par <input type="date">, on le laisse tel quel.
            'due_date'     => $row['due_date'],
            'completed_at' => Database::toIso($row['completed_at']),
            'created_at'   => Database::toIso($row['created_at']),
            'updated_at'   => Database::toIso($row['updated_at']),
        ];
    }
}
