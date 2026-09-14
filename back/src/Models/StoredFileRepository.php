<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Descriptions des fichiers téléversés.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  find() NE FILTRE PAS SUR L'ESPACE, ET C'EST VOULU                      │
 * │                                                                         │
 * │  Un fichier est lu par une balise <img>, qui n'envoie pas de session.   │
 * │  Le droit de le voir a donc été vérifié AVANT, au moment où l'API a     │
 * │  remis son adresse signée — dans la liste des versions d'un espace, ou  │
 * │  dans l'équipe d'un compte. La signature est ce qui autorise la         │
 * │  lecture (cf. FileController), et elle ne se forge pas.                 │
 * │                                                                         │
 * │  Toute AUTRE lecture passe par le dépôt du module concerné, filtré sur  │
 * │  l'espace comme partout ailleurs.                                       │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
final class StoredFileRepository
{
    private const COLUMNS = 'id, purpose, organization_id, owner_user_id, storage_key, media_type,
                             byte_size, sha256, width, height, original_name, created_by, created_at';

    /**
     * @param array{purpose: string, organization_id: ?string, owner_user_id: ?string, storage_key: string,
     *              media_type: string, byte_size: int, sha256: string, width: ?int, height: ?int,
     *              original_name: ?string, created_by: ?string} $attributes
     * @return array<string, mixed>
     */
    public function create(array $attributes): array
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO stored_files (purpose, organization_id, owner_user_id, storage_key, media_type,
                                       byte_size, sha256, width, height, original_name, created_by)
             VALUES (:purpose, :organization_id, :owner_user_id, :storage_key, :media_type,
                     :byte_size, :sha256, :width, :height, :original_name, :created_by)
             RETURNING ' . self::COLUMNS,
        );

        foreach ($attributes as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $statement->execute();

        /** @var array<string, mixed> $row */
        $row = $statement->fetch();

        return $this->hydrate($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM stored_files WHERE id = :id',
        );

        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Supprime la description. Le déclencheur consigne la clé ; les octets
     * partent au passage suivant du worker.
     */
    public function delete(string $id): bool
    {
        $statement = Database::connection()->prepare('DELETE FROM stored_files WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }

    /** Octets occupés par un espace — ce que le quota compare. */
    public function organizationUsage(string $organizationId): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COALESCE(SUM(byte_size), 0) FROM stored_files WHERE organization_id = :organization_id',
        );

        $statement->execute(['organization_id' => $organizationId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Les clés dont les octets restent à effacer, les plus anciennes d'abord.
     *
     * @return list<string>
     */
    public function tombstones(int $limit): array
    {
        $statement = Database::connection()->prepare(
            'SELECT storage_key FROM stored_file_tombstones ORDER BY buried_at LIMIT :limit',
        );

        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn (mixed $key): string => (string) $key, $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<string> $keys
     */
    public function forget(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        // Littéral de tableau PostgreSQL : les clés sont de l'hexadécimal et
        // des barres obliques, vérifiés par contrainte — rien à y échapper.
        Database::connection()
            ->prepare('DELETE FROM stored_file_tombstones WHERE storage_key = ANY (:keys::varchar[])')
            ->execute(['keys' => '{' . implode(',', $keys) . '}']);
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id'              => (string) $row['id'],
            'purpose'         => (string) $row['purpose'],
            'organization_id' => $row['organization_id'] !== null ? (string) $row['organization_id'] : null,
            'owner_user_id'   => $row['owner_user_id'] !== null ? (string) $row['owner_user_id'] : null,
            'storage_key'     => (string) $row['storage_key'],
            'media_type'      => (string) $row['media_type'],
            'byte_size'       => (int) $row['byte_size'],
            'sha256'          => (string) $row['sha256'],
            'width'           => $row['width'] !== null ? (int) $row['width'] : null,
            'height'          => $row['height'] !== null ? (int) $row['height'] : null,
            'original_name'   => $row['original_name'] !== null ? (string) $row['original_name'] : null,
            'created_by'      => $row['created_by'] !== null ? (string) $row['created_by'] : null,
            'created_at'      => Database::toIso($row['created_at']),
        ];
    }
}
