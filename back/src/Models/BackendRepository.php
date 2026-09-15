<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Module « Backend » : schémas de données et clés d'API.
 *
 * Comme tous les dépôts, CHAQUE requête est filtrée sur organization_id.
 *
 * Les clés d'API ne sont jamais stockées en clair : seuls leur empreinte
 * SHA-256 et leur préfixe le sont, comme les jetons de rafraîchissement de
 * l'authentification. La clé complète n'existe qu'une fois, dans la réponse
 * à sa création — une fuite de la base ne livre donc aucune clé utilisable.
 */
final class BackendRepository
{
    private const SORTABLE = ['created_at', 'updated_at', 'name', 'row_estimate'];

    /**
     * Sous-requête scalaire plutôt que jointure : la liste sert aussi bien à
     * des SELECT qu'à des RETURNING, et ces derniers n'acceptent pas de JOIN.
     * « created_by » y reste sans qualificatif pour valoir dans les deux cas.
     */
    private const TABLE_COLUMNS = 'id, name, description, columns, rls_enabled,
                                   row_estimate, created_at, updated_at, version,
                                   (SELECT u.full_name FROM users u WHERE u.id = created_by) AS author_name';

    // ---------------------------------------------------------------------
    //  Schémas de données
    // ---------------------------------------------------------------------

    /**
     * @param array{search?: string|null, sort?: string|null, direction?: string|null} $filters
     * @return array{tables: list<array<string, mixed>>, total: int}
     */
    public function searchTables(string $organizationId, array $filters, int $limit = 200): array
    {
        $conditions = ['organization_id = :organization_id', 'deleted_at IS NULL'];
        $params     = ['organization_id' => $organizationId];

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
    public function findTable(string $id, string $organizationId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::TABLE_COLUMNS . '
               FROM backend_tables
              WHERE id = :id AND organization_id = :organization_id AND deleted_at IS NULL',
        );

        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrateTable($row);
    }

    /**
     * Le nom d'une table est unique DANS L'ORGANISATION : l'unicité est
     * garantie par un index partiel, donc une collision remonte en violation
     * de contrainte plutôt qu'en écrasement silencieux. Deux coéquipiers ne
     * peuvent pas déclarer deux « clients » qui se croiraient distincts.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function createTable(string $organizationId, ?string $authorId, array $attributes): array
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO backend_tables (organization_id, created_by, name, description, columns, rls_enabled, row_estimate)
             VALUES (:organization_id, :created_by, :name, :description, :columns::jsonb, :rls_enabled, :row_estimate)
             RETURNING ' . self::TABLE_COLUMNS,
        );

        $statement->execute($this->tableBindings($attributes) + [
            'organization_id' => $organizationId,
            'created_by'      => $authorId,
        ]);

        /** @var array<string, mixed> $row */
        $row = $statement->fetch();

        return $this->hydrateTable($row);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>|null
     */
    public function updateTable(string $id, string $organizationId, array $attributes): ?array
    {
        $statement = Database::connection()->prepare(
            'UPDATE backend_tables
                SET name         = :name,
                    description  = :description,
                    columns      = :columns::jsonb,
                    rls_enabled  = :rls_enabled,
                    row_estimate = :row_estimate
              WHERE id = :id AND organization_id = :organization_id AND deleted_at IS NULL
          RETURNING ' . self::TABLE_COLUMNS,
        );

        $statement->execute(
            $this->tableBindings($attributes) + ['id' => $id, 'organization_id' => $organizationId],
        );

        $row = $statement->fetch();

        return $row === false ? null : $this->hydrateTable($row);
    }

    public function deleteTable(string $id, string $organizationId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE backend_tables
                SET deleted_at = NOW()
              WHERE id = :id AND organization_id = :organization_id AND deleted_at IS NULL',
        );

        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);

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
    public function keysForOrganization(string $organizationId): array
    {
        $statement = Database::connection()->prepare(
            // LEFT JOIN, pas INNER : une clé émise par quelqu'un qui a depuis
            // quitté l'équipe reste vivante — c'est même la raison pour
            // laquelle elle appartient à l'organisation et non à lui.
            'SELECT k.id, k.label, k.scope, k.token_prefix, k.last_used_at, k.revoked_at,
                    k.created_at, u.full_name AS author_name
               FROM backend_api_keys k
          LEFT JOIN users u ON u.id = k.created_by
              WHERE k.organization_id = :organization_id
              ORDER BY k.revoked_at IS NOT NULL, k.created_at DESC',
        );

        $statement->execute(['organization_id' => $organizationId]);

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
    public function createKey(string $organizationId, ?string $authorId, string $label, string $scope): array
    {
        // 32 octets d'aléa cryptographique, comme les jetons de session. Le
        // préfixe lisible sert à reconnaître la clé dans la liste ; il fait
        // partie du jeton, il n'est pas un secret.
        $secret = bin2hex(random_bytes(32));
        $prefix = ($scope === 'service' ? 'sk_' : 'pk_') . substr($secret, 0, 8);
        $token  = $prefix . '_' . substr($secret, 8);

        $statement = Database::connection()->prepare(
            'INSERT INTO backend_api_keys (organization_id, created_by, label, scope, token_prefix, token_hash)
             VALUES (:organization_id, :created_by, :label, :scope::api_key_scope, :prefix, :hash)
             RETURNING id, label, scope, token_prefix, last_used_at, revoked_at, created_at,
                       (SELECT u.full_name FROM users u WHERE u.id = created_by) AS author_name',
        );

        $statement->execute([
            'organization_id' => $organizationId,
            'created_by'      => $authorId,
            'label'           => $label,
            'scope'           => $scope,
            'prefix'          => $prefix,
            'hash'            => hash('sha256', $token),
        ]);

        /** @var array<string, mixed> $row */
        $row = $statement->fetch();

        return ['key' => $this->hydrateKey($row), 'token' => $token];
    }


    /**
     * Résout l'ORGANISATION destinataire d'une clé d'API, à partir du jeton
     * EN CLAIR.
     *
     * C'est ce qui donne enfin une utilité aux clés : jusqu'ici elles étaient
     * créées, affichées une fois, révoquées — et n'ouvraient rien. Elles
     * authentifient désormais l'ingestion d'erreurs (cf. IngestMiddleware).
     *
     * ELLE RÉSOUT UN ESPACE, PAS UNE PERSONNE, et ce n'est pas un détail : une
     * intégration de production doit continuer de fonctionner le jour où celui
     * qui a émis la clé quitte l'équipe. C'est aussi pourquoi « created_by »
     * n'est pas lu ici — il ne sert qu'à l'affichage.
     *
     * TROIS RÈGLES DE SÉCURITÉ, toutes appliquées ici et pas ailleurs.
     *
     * 1. LA COMPARAISON PORTE SUR L'EMPREINTE, jamais sur le jeton : la base
     *    ne contient que le SHA-256. Une fuite de la table ne livre aucune clé
     *    utilisable.
     *
     * 2. LES CLÉS RÉVOQUÉES SONT REJETÉES ICI, dans la clause WHERE, et non
     *    par un test après lecture. Une révocation qui dépendrait d'un « if »
     *    côté PHP finirait par être oubliée dans un autre appelant.
     *
     * 3. SEULES LES CLÉS « service » SONT ACCEPTÉES. Une clé « anon » est
     *    publique par destination — elle vit dans du code livré au navigateur.
     *    Lui laisser écrire en base ouvrirait l'ingestion à quiconque lit la
     *    source de la page. Un rapporteur d'erreurs côté navigateur doit
     *    passer par le serveur de son application, qui détient la clé service.
     *
     * La date de dernier usage est mise à jour dans le même aller-retour : sans
     * elle, on ne peut pas distinguer une clé vivante d'une clé oubliée, et
     * c'est précisément ce qu'on regarde avant d'en révoquer une.
     *
     * @return array{organization_id: string, key_id: string, label: string}|null
     */
    public function findOrganizationByKey(string $token): ?array
    {
        $statement = Database::connection()->prepare(
            "UPDATE backend_api_keys
                SET last_used_at = NOW()
              WHERE token_hash = :hash
                AND revoked_at IS NULL
                AND scope = 'service'
          RETURNING organization_id::text AS organization_id, id::text AS key_id, label",
        );

        $statement->execute(['hash' => hash('sha256', $token)]);

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'organization_id' => (string) $row['organization_id'],
            'key_id'          => (string) $row['key_id'],
            'label'           => (string) $row['label'],
        ];
    }

    /**
     * Révocation : la ligne demeure, la clé cesse d'être utilisable.
     * Idempotente — révoquer deux fois n'est pas une erreur, mais la seconde
     * ne doit pas repousser la date de révocation.
     */
    public function revokeKey(string $id, string $organizationId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE backend_api_keys
                SET revoked_at = NOW()
              WHERE id = :id AND organization_id = :organization_id AND revoked_at IS NULL',
        );

        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);

        return $statement->rowCount() > 0;
    }

    // ---------------------------------------------------------------------
    //  Indicateurs
    // ---------------------------------------------------------------------

    /**
     * @return array{tables: int, columns: int, unprotected: int, keys: int, active_keys: int}
     */
    public function statsForOrganization(string $organizationId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT
                 (SELECT COUNT(*) FROM backend_tables
                   WHERE organization_id = :organization_id AND deleted_at IS NULL)                     AS tables,
                 -- jsonb_array_length par ligne, sommé : le nombre total de
                 -- colonnes conçues, tous schémas confondus.
                 (SELECT COALESCE(SUM(jsonb_array_length(columns)), 0) FROM backend_tables
                   WHERE organization_id = :organization_id AND deleted_at IS NULL)                     AS columns,
                 -- Sans sécurité au niveau ligne : le seul chiffre de ce
                 -- module qui mérite une alerte.
                 (SELECT COUNT(*) FROM backend_tables
                   WHERE organization_id = :organization_id AND deleted_at IS NULL AND NOT rls_enabled) AS unprotected,
                 (SELECT COUNT(*) FROM backend_api_keys WHERE organization_id = :organization_id)       AS keys,
                 (SELECT COUNT(*) FROM backend_api_keys
                   WHERE organization_id = :organization_id AND revoked_at IS NULL)                     AS active_keys',
        );

        $statement->execute(['organization_id' => $organizationId]);
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
            // Jeton de concurrence, posé par un déclencheur et jamais par le
            // client : il ne dit pas QUAND la ligne a changé, mais COMBIEN DE
            // FOIS — la seule question qu'une écriture concurrente pose.
            'version'      => (int) $row['version'],
            // Null si le compte a été supprimé : le schéma appartient à
            // l'organisation, il survit à son auteur.
            'author_name'  => $row['author_name'] !== null ? (string) $row['author_name'] : null,
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
            // Null si le compte a été supprimé : la clé appartient à
            // l'organisation, elle survit à celui qui l'a émise.
            'author_name'  => $row['author_name'] !== null ? (string) $row['author_name'] : null,
        ];
    }
}
