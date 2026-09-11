<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Accès aux enregistrements des modules (table module_items).
 *
 * Chaque requête est filtrée sur organization_id : c'est le point de contrôle
 * central du cloisonnement. Aucune méthode ne permet de lire un élément sans
 * fournir l'espace de travail auquel il appartient.
 *
 * « created_by » ne cloisonne RIEN. Il dit qui a écrit la ligne, et deux
 * coéquipiers voient les mêmes éléments quel que soit celui qui les a créés.
 */
final class ModuleItemRepository
{
    /** Tri autorisé — liste blanche, car un ORDER BY ne peut pas être paramétré. */
    private const SORTABLE = ['created_at', 'updated_at', 'title', 'due_date', 'position'];

    /**
     * Colonnes exposées par l'API.
     *
     * Cette liste était recopiée à quatre endroits, ce qui n'a tenu que tant
     * qu'elle ne bougeait pas : l'auteur devait être ajouté quatre fois, et
     * oublié une fois aurait suffi à ce qu'un élément le perde selon la route
     * qui l'a renvoyé.
     *
     * L'auteur arrive par SOUS-REQUÊTE SCALAIRE, et non par jointure, parce
     * que la liste sert aussi bien à des SELECT qu'à des RETURNING — lesquels
     * n'acceptent aucun JOIN. « created_by » y reste sans qualificatif : la
     * liste est employée tantôt sur « module_items », tantôt sur l'alias
     * « i », et la colonne se résout dans les deux cas.
     */
    private const COLUMNS = 'id, module_id, title, description, status, data,
                             position, due_date, created_at, updated_at, version,
                             (SELECT u.full_name FROM users u WHERE u.id = created_by) AS author_name,
                             (SELECT m.slug FROM modules m WHERE m.id = module_id) AS module_slug';

    /**
     * Liste paginée des éléments d'un module.
     *
     * @param array{status?: string|null, search?: string|null, sort?: string, direction?: string} $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function paginate(string $organizationId, string $moduleId, array $filters, int $page, int $perPage): array
    {
        $conditions = ['i.organization_id = :organization_id', 'i.module_id = :module_id', 'i.deleted_at IS NULL'];
        $params     = ['organization_id' => $organizationId, 'module_id' => $moduleId];

        if (!empty($filters['status'])) {
            $conditions[]     = 'i.status = :status::item_status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            // ILIKE : recherche insensible à la casse sur le titre et la description.
            $conditions[]     = '(i.title ILIKE :search OR i.description ILIKE :search)';
            $params['search'] = '%' . $this->escapeLike($filters['search']) . '%';
        }

        $where = implode(' AND ', $conditions);

        // --- Total (pour la pagination) ---
        $countStatement = Database::connection()->prepare(
            "SELECT COUNT(*) FROM module_items i WHERE {$where}",
        );
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        // --- Page courante ---
        $sort      = in_array($filters['sort'] ?? '', self::SORTABLE, true) ? $filters['sort'] : 'created_at';
        $direction = strtoupper($filters['direction'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . "
               FROM module_items i
              WHERE {$where}
              ORDER BY i.{$sort} {$direction} NULLS LAST, i.id
              LIMIT :limit OFFSET :offset",
        );

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }

        // LIMIT/OFFSET sont des entiers : sans PARAM_INT, PostgreSQL reçoit
        // des chaînes et refuse la requête (EMULATE_PREPARES est désactivé).
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => array_map($this->hydrate(...), $statement->fetchAll()),
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id, string $organizationId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM module_items
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
    public function create(string $organizationId, ?string $authorId, string $moduleId, array $attributes): array
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO module_items (module_id, organization_id, created_by, title, description, status, data, due_date)
             VALUES (:module_id, :organization_id, :created_by, :title, :description, :status::item_status, :data::jsonb, :due_date)
             RETURNING ' . self::COLUMNS,
        );

        $statement->execute([
            'module_id'       => $moduleId,
            'organization_id' => $organizationId,
            'created_by'      => $authorId,
            'title'           => $attributes['title'],
            'description'     => $attributes['description'],
            'status'          => $attributes['status'],
            'data'            => json_encode($attributes['data'], JSON_UNESCAPED_UNICODE),
            'due_date'        => $attributes['due_date'],
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
            'UPDATE module_items
                SET title = :title,
                    description = :description,
                    status = :status::item_status,
                    data = :data::jsonb,
                    due_date = :due_date
              WHERE id = :id AND organization_id = :organization_id AND deleted_at IS NULL
          RETURNING ' . self::COLUMNS,
        );

        $statement->execute([
            'id'              => $id,
            'organization_id' => $organizationId,
            'title'           => $attributes['title'],
            'description'     => $attributes['description'],
            'status'          => $attributes['status'],
            'data'            => json_encode($attributes['data'], JSON_UNESCAPED_UNICODE),
            'due_date'        => $attributes['due_date'],
        ]);

        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Suppression logique : la ligne reste en base, invisible des listings.
     */
    public function softDelete(string $id, string $organizationId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE module_items
                SET deleted_at = NOW()
              WHERE id = :id AND organization_id = :organization_id AND deleted_at IS NULL',
        );

        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);

        return $statement->rowCount() > 0;
    }

    /**
     * Restauration d'un élément générique.
     *
     * Le compteur du module est ajusté par l'appelant, comme il l'est à la
     * suppression : le dépôt ne connaît que sa table.
     */
    public function restore(string $id, string $organizationId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE module_items
                SET deleted_at = NULL
              WHERE id = :id AND organization_id = :organization_id AND deleted_at IS NOT NULL',
        );

        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);

        return $statement->rowCount() > 0;
    }

    /**
     * Derniers éléments modifiés, tous modules confondus (fil d'activité).
     *
     * @return list<array<string, mixed>>
     */
    public function recentForOrganization(string $organizationId, int $limit = 5): array
    {
        $statement = Database::connection()->prepare(
            'SELECT i.id, i.title, i.status, i.updated_at, m.slug AS module_slug, m.name AS module_name
               FROM module_items i
               JOIN modules m ON m.id = i.module_id
              WHERE i.organization_id = :organization_id AND i.deleted_at IS NULL
              ORDER BY i.updated_at DESC
              LIMIT :limit',
        );

        $statement->bindValue('organization_id', $organizationId);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): array => [
                'id'          => (string) $row['id'],
                'title'       => (string) $row['title'],
                'status'      => (string) $row['status'],
                'updated_at'  => Database::toIso($row['updated_at']),
                'module_slug' => (string) $row['module_slug'],
                'module_name' => (string) $row['module_name'],
            ],
            $statement->fetchAll(),
        );
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
        return [
            'id'          => (string) $row['id'],
            'module_id'   => (string) $row['module_id'],
            'title'       => (string) $row['title'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'status'      => (string) $row['status'],
            'data'        => Database::toObject($row['data']),
            'position'    => (int) $row['position'],
            // due_date est une DATE (sans heure) : « AAAA-MM-JJ » est déjà le
            // format attendu par <input type="date">, on le laisse tel quel.
            'due_date'    => $row['due_date'],
            'created_at'  => Database::toIso($row['created_at']),
            'updated_at'  => Database::toIso($row['updated_at']),
            // Null si le compte a été supprimé : l'élément appartient à
            // l'organisation, il survit à son auteur.
            'author_name' => $row['author_name'] !== null ? (string) $row['author_name'] : null,
            // Jeton de concurrence, posé par un déclencheur et jamais par le
            // client : il ne dit pas QUAND la ligne a changé, mais COMBIEN DE
            // FOIS — la seule question qu'une écriture concurrente pose.
            'version'     => (int) $row['version'],
            // Le slug du module dont dépend cet élément. Les routes de détail
            // — « /api/items/{id} » — ne le portent pas, et le journal en a
            // besoin pour dire DANS QUEL module le fait s'est produit.
            'module_slug' => (string) $row['module_slug'],
        ];
    }
}
