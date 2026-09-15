<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\SignedUrl;
use PDO;

/**
 * Module « Design » : fichiers et historique de versions.
 *
 * Comme tous les dépôts, CHAQUE requête est filtrée sur organization_id — y compris
 * pour les versions, dont la table porte une copie de organization_id pour que le
 * cloisonnement ne dépende jamais d'une jointure correctement écrite.
 *
 * Le numéro de version est attribué PAR FICHIER par un trigger : il n'est
 * jamais fourni ici.
 */
final class DesignRepository
{
    private const SORTABLE = ['updated_at', 'created_at', 'name'];

    private const COLUMNS = 'f.id, f.name, f.kind, f.description, f.accent,
                             f.created_at, f.updated_at, f.version';

    /**
     * L'aperçu d'un fichier : l'image de la version la plus récente QUI EN A UNE.
     *
     * « Qui en a une », et pas « la dernière » : une version qui ne consigne
     * qu'une note ne doit pas faire disparaître l'image de la précédente.
     */
    private const PREVIEW_JOIN = 'LEFT JOIN LATERAL (
                   SELECT dv.asset_id AS preview_id
                     FROM design_versions dv
                    WHERE dv.file_id = f.id AND dv.asset_id IS NOT NULL
                    ORDER BY dv.number DESC
                    LIMIT 1
               ) p ON TRUE';

    /**
     * Une version, son auteur et son image, lus ensemble partout où l'on
     * montre un historique.
     *
     * La jointure sur l'image exige le MÊME espace que la version : une ligne
     * mal écrite à la main ne doit pas faire afficher le fichier d'un autre.
     */
    private const VERSION_SELECT = 'SELECT v.id, v.number, v.label, v.notes, v.created_at, v.created_by,
                                           u.full_name AS author_name,
                                           s.id AS asset_id, s.media_type AS asset_type,
                                           s.byte_size AS asset_size, s.width AS asset_width,
                                           s.height AS asset_height, s.original_name AS asset_name
                                      FROM design_versions v
                                 LEFT JOIN users u ON u.id = v.created_by
                                 LEFT JOIN stored_files s
                                        ON s.id = v.asset_id AND s.organization_id = v.organization_id';

    /**
     * Fichiers, avec le nombre de versions et la date de la dernière.
     *
     * L'agrégat est calculé en sous-requête latérale plutôt qu'en JOIN +
     * GROUP BY : la liste reste lisible, et l'agrégat ne force pas à
     * regrouper toutes les colonnes du fichier.
     *
     * @param array{kind?: string|null, search?: string|null,
     *              sort?: string|null, direction?: string|null} $filters
     * @return array{files: list<array<string, mixed>>, total: int}
     */
    public function search(
        string $organizationId,
        array $filters,
        int $limit = 200,
        int $offset = 0,
    ): array {
        $conditions = ['f.organization_id = :organization_id', 'f.deleted_at IS NULL'];
        $params     = ['organization_id' => $organizationId];

        if (!empty($filters['kind'])) {
            $conditions[]   = 'f.kind = :kind::design_kind';
            $params['kind'] = $filters['kind'];
        }

        if (!empty($filters['search'])) {
            $conditions[]     = '(f.name ILIKE :search OR f.description ILIKE :search)';
            $params['search'] = '%' . $this->escapeLike($filters['search']) . '%';
        }

        $where = implode(' AND ', $conditions);

        $countStatement = Database::connection()->prepare(
            "SELECT COUNT(*) FROM design_files f WHERE {$where}",
        );
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $sort      = in_array($filters['sort'] ?? '', self::SORTABLE, true) ? $filters['sort'] : 'updated_at';
        $direction = strtoupper($filters['direction'] ?? '') === 'ASC' ? 'ASC' : 'DESC';

        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . ', v.versions, v.last_version_at, p.preview_id
               FROM design_files f
               LEFT JOIN LATERAL (
                   SELECT COUNT(*) AS versions, MAX(created_at) AS last_version_at
                     FROM design_versions
                    WHERE file_id = f.id
               ) v ON TRUE
               ' . self::PREVIEW_JOIN . "
              WHERE {$where}
              ORDER BY f.{$sort} {$direction} NULLS LAST, f.name
              LIMIT :limit OFFSET :offset",
        );

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }

        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return [
            'files' => array_map($this->hydrateFile(...), $statement->fetchAll()),
            'total' => $total,
        ];
    }

    /**
     * Un fichier et tout son historique.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id, string $organizationId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . ', v.versions, v.last_version_at, p.preview_id
               FROM design_files f
               LEFT JOIN LATERAL (
                   SELECT COUNT(*) AS versions, MAX(created_at) AS last_version_at
                     FROM design_versions
                    WHERE file_id = f.id
               ) v ON TRUE
               ' . self::PREVIEW_JOIN . '
              WHERE f.id = :id AND f.organization_id = :organization_id AND f.deleted_at IS NULL',
        );

        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        $file = $this->hydrateFile($row);
        $file['history'] = $this->versionsForFile($id, $organizationId);

        return $file;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function versionsForFile(string $fileId, string $organizationId, int $limit = 50): array
    {
        $statement = Database::connection()->prepare(
            self::VERSION_SELECT . '
              WHERE v.file_id = :file_id AND v.organization_id = :organization_id
              ORDER BY v.number DESC
              LIMIT :limit',
        );

        $statement->bindValue('file_id', $fileId);
        $statement->bindValue('organization_id', $organizationId);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        // LEFT JOIN, pas INNER : une version publiée par quelqu'un qui a
        // depuis supprimé son compte reste dans l'historique. La faire
        // disparaître réécrirait le passé.
        return array_map($this->hydrateVersion(...), $statement->fetchAll());
    }

    /**
     * Crée un fichier ET sa première version, dans la même transaction.
     *
     * Un fichier de design sans aucune version serait un objet vide : il ne
     * documenterait rien. La v1 naît donc avec le fichier.
     *
     * @param  array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function createFile(string $organizationId, ?string $authorId, array $attributes): array
    {
        return Database::transaction(function () use ($organizationId, $authorId, $attributes): array {
            $statement = Database::connection()->prepare(
                'INSERT INTO design_files (organization_id, created_by, name, kind, description, accent)
                 VALUES (:organization_id, :created_by, :name, :kind::design_kind, :description, :accent)
                 RETURNING id',
            );

            $statement->execute($this->fileBindings($attributes) + [
                'organization_id' => $organizationId,
                'created_by'      => $authorId,
            ]);
            $fileId = (string) $statement->fetchColumn();

            $this->addVersion($fileId, $organizationId, $authorId, ['label' => 'Version initiale', 'notes' => null]);

            /** @var array<string, mixed> $created */
            $created = $this->find($fileId, $organizationId);

            return $created;
        });
    }

    /**
     * @param  array<string, mixed> $attributes
     * @return array<string, mixed>|null
     */
    public function updateFile(string $id, string $organizationId, array $attributes): ?array
    {
        $statement = Database::connection()->prepare(
            'UPDATE design_files
                SET name        = :name,
                    kind        = :kind::design_kind,
                    description = :description,
                    accent      = :accent
              WHERE id = :id AND organization_id = :organization_id AND deleted_at IS NULL',
        );

        $statement->execute($this->fileBindings($attributes) + ['id' => $id, 'organization_id' => $organizationId]);

        return $statement->rowCount() > 0 ? $this->find($id, $organizationId) : null;
    }

    public function deleteFile(string $id, string $organizationId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE design_files
                SET deleted_at = NOW()
              WHERE id = :id AND organization_id = :organization_id AND deleted_at IS NULL',
        );

        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);

        return $statement->rowCount() > 0;
    }

    /**
     * Restauration d'un fichier.
     *
     * Les VERSIONS reviennent avec lui, sans rien avoir à faire : elles ne
     * sont jamais supprimées, et les requêtes qui les lisent les filtrent par
     * « f.deleted_at IS NULL » — c'est-à-dire par l'état du fichier. Un
     * historique de douze versions restauré est le même qu'avant.
     */
    public function restoreFile(string $id, string $organizationId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE design_files
                SET deleted_at = NULL
              WHERE id = :id AND organization_id = :organization_id AND deleted_at IS NOT NULL',
        );

        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);

        return $statement->rowCount() > 0;
    }

    /**
     * Ajoute une version. Le numéro est posé par le trigger, pas ici.
     *
     * @param  array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function addVersion(string $fileId, string $organizationId, ?string $authorId, array $attributes): array
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO design_versions (file_id, organization_id, created_by, label, notes, asset_id)
             VALUES (:file_id, :organization_id, :created_by, :label, :notes, :asset_id)
             RETURNING id',
        );

        $statement->execute([
            'file_id'         => $fileId,
            'organization_id' => $organizationId,
            'created_by'      => $authorId,
            'label'           => $attributes['label'],
            'notes'           => $attributes['notes'],
            'asset_id'        => $attributes['asset_id'] ?? null,
        ]);

        // Relue par la même requête que l'historique : la version renvoyée
        // après un ajout a exactement la forme de celles qu'on liste.
        /** @var array<string, mixed> $version */
        $version = $this->findVersion((string) $statement->fetchColumn(), $organizationId);

        return $version;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findVersion(string $id, string $organizationId): ?array
    {
        $statement = Database::connection()->prepare(
            self::VERSION_SELECT . ' WHERE v.id = :id AND v.organization_id = :organization_id',
        );

        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrateVersion($row);
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateVersion(array $row): array
    {
        return [
            'id'          => (string) $row['id'],
            'number'      => (int) $row['number'],
            'label'       => $row['label'] !== null ? (string) $row['label'] : null,
            'notes'       => $row['notes'] !== null ? (string) $row['notes'] : null,
            'created_at'  => Database::toIso($row['created_at']),
            // « Qui a publié cette version » : à plusieurs, un historique
            // anonyme ne dit qu'une moitié de ce qu'on lui demande.
            'created_by'  => $row['created_by'] !== null ? (string) $row['created_by'] : null,
            'author_name' => $row['author_name'] !== null ? (string) $row['author_name'] : null,
            'asset'       => $row['asset_id'] !== null ? [
                'url'        => SignedUrl::forFile((string) $row['asset_id']),
                'media_type' => (string) $row['asset_type'],
                'byte_size'  => (int) $row['asset_size'],
                'width'      => $row['asset_width'] !== null ? (int) $row['asset_width'] : null,
                'height'     => $row['asset_height'] !== null ? (int) $row['asset_height'] : null,
                'name'       => $row['asset_name'] !== null ? (string) $row['asset_name'] : null,
            ] : null,
        ];
    }

    /**
     * @return array{files: int, versions: int, maquette: int, prototype: int,
     *               systeme: int, updated_this_week: int}
     */
    public function statsForOrganization(string $organizationId): array
    {
        $statement = Database::connection()->prepare(
            "SELECT
                 COUNT(*)                                                        AS files,
                 COUNT(*) FILTER (WHERE kind = 'maquette')                        AS maquette,
                 COUNT(*) FILTER (WHERE kind = 'prototype')                       AS prototype,
                 COUNT(*) FILTER (WHERE kind = 'systeme')                         AS systeme,
                 COUNT(*) FILTER (WHERE updated_at > NOW() - INTERVAL '7 days')   AS updated_this_week
               FROM design_files
              WHERE organization_id = :organization_id AND deleted_at IS NULL",
        );

        $statement->execute(['organization_id' => $organizationId]);
        $row = $statement->fetch() ?: [];

        // Les versions des fichiers supprimés ne comptent pas : la suppression
        // est logique, mais l'utilisateur ne doit plus les voir nulle part.
        $versions = Database::connection()->prepare(
            'SELECT COUNT(*)
               FROM design_versions v
               JOIN design_files f ON f.id = v.file_id AND f.deleted_at IS NULL
              WHERE v.organization_id = :organization_id',
        );
        $versions->execute(['organization_id' => $organizationId]);

        return [
            'files'             => (int) ($row['files'] ?? 0),
            'versions'          => (int) $versions->fetchColumn(),
            'maquette'          => (int) ($row['maquette'] ?? 0),
            'prototype'         => (int) ($row['prototype'] ?? 0),
            'systeme'           => (int) ($row['systeme'] ?? 0),
            'updated_this_week' => (int) ($row['updated_this_week'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function fileBindings(array $attributes): array
    {
        return [
            'name'        => $attributes['name'],
            'kind'        => $attributes['kind'],
            'description' => $attributes['description'],
            'accent'      => $attributes['accent'],
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
    private function hydrateFile(array $row): array
    {
        return [
            'id'              => (string) $row['id'],
            'name'            => (string) $row['name'],
            'kind'            => (string) $row['kind'],
            'description'     => $row['description'] !== null ? (string) $row['description'] : null,
            'accent'          => (string) $row['accent'],
            'versions'        => (int) ($row['versions'] ?? 0),
            'last_version_at' => Database::toIso($row['last_version_at'] ?? null),
            'preview_url'     => ($row['preview_id'] ?? null) !== null
                ? SignedUrl::forFile((string) $row['preview_id'])
                : null,
            'created_at'      => Database::toIso($row['created_at']),
            'updated_at'      => Database::toIso($row['updated_at']),
            // Jeton de concurrence, posé par un déclencheur et jamais par le
            // client : il ne dit pas QUAND la ligne a changé, mais COMBIEN DE
            // FOIS — la seule question qu'une écriture concurrente pose.
            'version'         => (int) $row['version'],
        ];
    }
}
