<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Module « Backend » : schémas de données et clés d'API.
 *
 * Comme tous les dépôts, CHAQUE requête est filtrée sur user_id.
 *
 * Les clés d'API ne sont jamais stockées en clair : seuls leur empreinte
 * SHA-256 et leur préfixe le sont, comme les jetons de rafraîchissement de
 * l'authentification. La clé complète n'existe qu'une fois, dans la réponse
 * à sa création — une fuite de la base ne livre donc aucune clé utilisable.
 */
final class BackendRepository
{
    private const SORTABLE = ['created_at', 'updated_at', 'name', 'row_estimate'];

    private const TABLE_COLUMNS = 'id, name, description, columns, rls_enabled,
                                   row_estimate, created_at, updated_at';

    // ---------------------------------------------------------------------
    //  Schémas de données
    // ---------------------------------------------------------------------

    /**
     * @param array{search?: string|null, sort?: string|null, direction?: string|null} $filters
     * @return array{tables: list<array<string, mixed>>, total: int}
     */
    public function searchTables(string $userId, array $filters, int $limit = 200): array
    {
        $conditions = ['user_id = :user_id', 'deleted_at IS NULL'];
        $params     = ['user_id' => $userId];

        if (!empty($filters['search'])) {
            $conditions[]     = '(name ILIKE :search OR description ILIKE :search)';
            $params['search'] = '%' . $this->escapeLike($filters['search']) . '%';
        }

        $where = implode(' AND ', $conditions);

        $countStatement = Database::connection()->prepare(
            "SELECT COUNT(*) FROM backend_tables WHERE {$where}",
        );
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $sort      = in_array($filters['sort'] ?? '', self::SORTABLE, true) ? $filters['sort'] : 'created_at';
        $direction = strtoupper($filters['direction'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

        $statement = Database::connection()->prepare(
            'SELECT ' . self::TABLE_COLUMNS . "
               FROM backend_tables
              WHERE {$where}
              ORDER BY {$sort} {$direction} NULLS LAST, name
              LIMIT :limit",
        );

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }

        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return [
            'tables' => array_map($this->hydrateTable(...), $statement->fetchAll()),
            'total'  => $total,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTable(string $id, string $userId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::TABLE_COLUMNS . '
               FROM backend_tables
              WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL',
        );

        $statement->execute(['id' => $id, 'user_id' => $userId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrateTable($row);
    }

    /**
     * Le nom d'une table est unique par compte : l'unicité est garantie par
     * un index partiel, donc une collision remonte en violation de contrainte
     * plutôt qu'en écrasement silencieux.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function createTable(string $userId, array $attributes): array
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO backend_tables (user_id, name, description, columns, rls_enabled, row_estimate)
             VALUES (:user_id, :name, :description, :columns::jsonb, :rls_enabled, :row_estimate)
             RETURNING ' . self::TABLE_COLUMNS,
        );

        $statement->execute($this->tableBindings($attributes) + ['user_id' => $userId]);

        /** @var array<string, mixed> $row */
        $row = $statement->fetch();

        return $this->hydrateTable($row);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>|null
     */
    public function updateTable(string $id, string $userId, array $attributes): ?array
    {
        $statement = Database::connection()->prepare(
            'UPDATE backend_tables
                SET name         = :name,
                    description  = :description,
                    columns      = :columns::jsonb,
                    rls_enabled  = :rls_enabled,
                    row_estimate = :row_estimate
              WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL
          RETURNING ' . self::TABLE_COLUMNS,
        );

        $statement->execute(
            $this->tableBindings($attributes) + ['id' => $id, 'user_id' => $userId],
        );

        $row = $statement->fetch();

        return $row === false ? null : $this->hydrateTable($row);
    }

    public function deleteTable(string $id, string $userId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE backend_tables
                SET deleted_at = NOW()
              WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL',
        );

        $statement->execute(['id' => $id, 'user_id' => $userId]);

        return $statement->rowCount() > 0;
    }

    // ---------------------------------------------------------------------
    //  Clés d'API
    // ---------------------------------------------------------------------

    /**
     * Les clés révoquées RESTENT dans la liste : la trace de l'existence
     * d'une clé fait partie de ce qu'un journal de sécurité doit montrer.
     *
     * @return list<array<string, mixed>>
     */
    public function keysForUser(string $userId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, label, scope, token_prefix, last_used_at, revoked_at, created_at
               FROM backend_api_keys
              WHERE user_id = :user_id
              ORDER BY revoked_at IS NOT NULL, created_at DESC',
        );

        $statement->execute(['user_id' => $userId]);

        return array_map($this->hydrateKey(...), $statement->fetchAll());
    }

    /**
     * Crée une clé et renvoie sa valeur EN CLAIR, une seule et unique fois.
     *
     * L'appelant doit la transmettre immédiatement : elle n'est pas
     * récupérable ensuite, puisque seule son empreinte est conservée.
     *
     * @return array{key: array<string, mixed>, token: string}
     */
    public function createKey(string $userId, string $label, string $scope): array
    {
        // 32 octets d'aléa cryptographique, comme les jetons de session. Le
        // préfixe lisible sert à reconnaître la clé dans la liste ; il fait
        // partie du jeton, il n'est pas un secret.
        $secret = bin2hex(random_bytes(32));
        $prefix = ($scope === 'service' ? 'sk_' : 'pk_') . substr($secret, 0, 8);
        $token  = $prefix . '_' . substr($secret, 8);

        $statement = Database::connection()->prepare(
            'INSERT INTO backend_api_keys (user_id, label, scope, token_prefix, token_hash)
             VALUES (:user_id, :label, :scope::api_key_scope, :prefix, :hash)
             RETURNING id, label, scope, token_prefix, last_used_at, revoked_at, created_at',
        );

        $statement->execute([
            'user_id' => $userId,
            'label'   => $label,
            'scope'   => $scope,
            'prefix'  => $prefix,
            'hash'    => hash('sha256', $token),
        ]);

        /** @var array<string, mixed> $row */
        $row = $statement->fetch();

        return ['key' => $this->hydrateKey($row), 'token' => $token];
    }

    /**
     * Révocation : la ligne demeure, la clé cesse d'être utilisable.
     * Idempotente — révoquer deux fois n'est pas une erreur, mais la seconde
     * ne doit pas repousser la date de révocation.
     */
    public function revokeKey(string $id, string $userId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE backend_api_keys
                SET revoked_at = NOW()
              WHERE id = :id AND user_id = :user_id AND revoked_at IS NULL',
        );

        $statement->execute(['id' => $id, 'user_id' => $userId]);

        return $statement->rowCount() > 0;
    }

    // ---------------------------------------------------------------------
    //  Indicateurs
    // ---------------------------------------------------------------------

    /**
     * @return array{tables: int, columns: int, unprotected: int, keys: int, active_keys: int}
     */
    public function statsForUser(string $userId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT
                 (SELECT COUNT(*) FROM backend_tables
                   WHERE user_id = :user_id AND deleted_at IS NULL)                     AS tables,
                 -- jsonb_array_length par ligne, sommé : le nombre total de
                 -- colonnes conçues, tous schémas confondus.
                 (SELECT COALESCE(SUM(jsonb_array_length(columns)), 0) FROM backend_tables
                   WHERE user_id = :user_id AND deleted_at IS NULL)                     AS columns,
                 -- Sans sécurité au niveau ligne : le seul chiffre de ce
                 -- module qui mérite une alerte.
                 (SELECT COUNT(*) FROM backend_tables
                   WHERE user_id = :user_id AND deleted_at IS NULL AND NOT rls_enabled) AS unprotected,
                 (SELECT COUNT(*) FROM backend_api_keys WHERE user_id = :user_id)       AS keys,
                 (SELECT COUNT(*) FROM backend_api_keys
                   WHERE user_id = :user_id AND revoked_at IS NULL)                     AS active_keys',
        );

        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch() ?: [];

        return [
            'tables'      => (int) ($row['tables'] ?? 0),
            'columns'     => (int) ($row['columns'] ?? 0),
            'unprotected' => (int) ($row['unprotected'] ?? 0),
            'keys'        => (int) ($row['keys'] ?? 0),
            'active_keys' => (int) ($row['active_keys'] ?? 0),
        ];
    }

    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function tableBindings(array $attributes): array
    {
        return [
            'name'         => $attributes['name'],
            'description'  => $attributes['description'],
            'columns'      => json_encode(array_values($attributes['columns']), JSON_UNESCAPED_UNICODE),
            'rls_enabled'  => $attributes['rls_enabled'] ? 'true' : 'false',
            'row_estimate' => $attributes['row_estimate'],
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
    private function hydrateTable(array $row): array
    {
        $columns = json_decode((string) $row['columns'], true);

        return [
            'id'           => (string) $row['id'],
            'name'         => (string) $row['name'],
            'description'  => $row['description'] !== null ? (string) $row['description'] : null,
            'columns'      => is_array($columns) ? array_values($columns) : [],
            'rls_enabled'  => Database::toBool($row['rls_enabled']),
            'row_estimate' => (int) $row['row_estimate'],
            'created_at'   => Database::toIso($row['created_at']),
            'updated_at'   => Database::toIso($row['updated_at']),
        ];
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateKey(array $row): array
    {
        return [
            'id'           => (string) $row['id'],
            'label'        => (string) $row['label'],
            'scope'        => (string) $row['scope'],
            'token_prefix' => (string) $row['token_prefix'],
            'last_used_at' => Database::toIso($row['last_used_at']),
            'revoked_at'   => Database::toIso($row['revoked_at']),
            'created_at'   => Database::toIso($row['created_at']),
        ];
    }
}
