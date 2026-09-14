<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Les pages de documentation d'un espace.
 *
 * Chaque requête filtre par organization_id : l'identifiant d'une page d'un
 * autre espace ne répond jamais que « introuvable », y compris quand il est
 * proposé comme PARENT d'une page d'ici.
 */
final class DocPageRepository
{
    private const COLUMNS = 'p.id, p.parent_id, p.title, p.body, p.position, p.version, p.created_at, p.updated_at,
                             (SELECT u.full_name FROM users u WHERE u.id = p.updated_by) AS updated_by_name';

    /**
     * L'arbre, sans les corps : la liste de gauche n'a besoin que des titres,
     * et deux cents pages de procédures pèseraient des mégaoctets.
     *
     * @return list<array<string, mixed>>
     */
    public function treeForOrganization(string $organizationId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT p.id, p.parent_id, p.title, p.position, p.version, p.updated_at, LEFT(p.body, 400) AS head,
                    (SELECT u.full_name FROM users u WHERE u.id = p.updated_by) AS updated_by_name
               FROM doc_pages p
              WHERE p.organization_id = :organization_id AND p.deleted_at IS NULL
              ORDER BY p.position, p.title',
        );
        $statement->execute(['organization_id' => $organizationId]);

        return array_map($this->hydrateSummary(...), $statement->fetchAll());
    }

    /**
     * Titre ou corps. Les jokers tapés — « % », « _ » — restent des
     * caractères : ils sont échappés par « ! », déclaré comme caractère
     * d'échappement, plutôt que par une barre oblique inverse que PHP, SQL et
     * JSON interprètent chacun à leur tour.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $organizationId, string $term, int $limit = 30): array
    {
        $motif = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';

        $statement = Database::connection()->prepare(
            "SELECT p.id, p.parent_id, p.title, p.position, p.version, p.updated_at, LEFT(p.body, 400) AS head,
                    (SELECT u.full_name FROM users u WHERE u.id = p.updated_by) AS updated_by_name
               FROM doc_pages p
              WHERE p.organization_id = :organization_id
                AND p.deleted_at IS NULL
                AND (p.title ILIKE :motif ESCAPE '!' OR p.body ILIKE :motif ESCAPE '!')
              ORDER BY (p.title ILIKE :motif ESCAPE '!') DESC, p.updated_at DESC
              LIMIT :limit",
        );
        $statement->bindValue('organization_id', $organizationId);
        $statement->bindValue('motif', $motif);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map($this->hydrateSummary(...), $statement->fetchAll());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id, string $organizationId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM doc_pages p
              WHERE p.id = :id AND p.organization_id = :organization_id AND p.deleted_at IS NULL',
        );
        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);

        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Les ancêtres d'une page, de la racine à son parent : le fil d'Ariane.
     *
     * @return list<array{id: string, title: string}>
     */
    public function ancestors(string $id, string $organizationId): array
    {
        $statement = Database::connection()->prepare(
            'WITH RECURSIVE chaine AS (
                 SELECT p.parent_id, 0 AS profondeur
                   FROM doc_pages p
                  WHERE p.id = :id AND p.organization_id = :organization_id
                 UNION ALL
                 SELECT parent.parent_id, chaine.profondeur + 1
                   FROM doc_pages parent
                   JOIN chaine ON parent.id = chaine.parent_id
                  WHERE parent.organization_id = :organization_id
                    AND chaine.profondeur < 32
             )
             SELECT a.id, a.title
               FROM chaine
               JOIN doc_pages a ON a.id = chaine.parent_id
              WHERE a.deleted_at IS NULL
              ORDER BY chaine.profondeur DESC',
        );
        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);

        return array_map(
            static fn (array $row): array => ['id' => (string) $row['id'], 'title' => (string) $row['title']],
            $statement->fetchAll(),
        );
    }

    /**
     * Vrai si $candidate est la page elle-même ou l'une de ses descendantes :
     * la ranger dessous fermerait une boucle.
     *
     * La profondeur est bornée à 32 : une boucle déjà présente en base (écrite
     * à la main) ne ferait pas tourner la requête indéfiniment.
     */
    public function isInSubtree(string $candidate, string $id, string $organizationId): bool
    {
        if ($candidate === $id) {
            return true;
        }

        $statement = Database::connection()->prepare(
            'WITH RECURSIVE sous_arbre AS (
                 SELECT p.id, 0 AS profondeur
                   FROM doc_pages p
                  WHERE p.parent_id = :id AND p.organization_id = :organization_id
                 UNION ALL
                 SELECT enfant.id, sous_arbre.profondeur + 1
                   FROM doc_pages enfant
                   JOIN sous_arbre ON enfant.parent_id = sous_arbre.id
                  WHERE enfant.organization_id = :organization_id
                    AND sous_arbre.profondeur < 32
             )
             SELECT EXISTS (SELECT 1 FROM sous_arbre WHERE id = :candidate)',
        );
        $statement->execute(['id' => $id, 'organization_id' => $organizationId, 'candidate' => $candidate]);

        return (bool) $statement->fetchColumn();
    }

    public function hasChildren(string $id, string $organizationId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT EXISTS (
                 SELECT 1 FROM doc_pages
                  WHERE parent_id = :id AND organization_id = :organization_id AND deleted_at IS NULL
             )',
        );
        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);

        return (bool) $statement->fetchColumn();
    }

    /**
     * @param array{title: string, body: string, parent_id: ?string, position: int} $attributes
     *
     * @return array<string, mixed>
     */
    public function create(string $organizationId, ?string $authorId, array $attributes): array
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO doc_pages (organization_id, parent_id, title, body, position, created_by, updated_by)
             VALUES (:organization_id, :parent_id, :title, :body, :position, :author, :author)
             RETURNING id',
        );
        $statement->execute($attributes + ['organization_id' => $organizationId, 'author' => $authorId]);

        /** @var array<string, mixed> $page */
        $page = $this->find((string) $statement->fetchColumn(), $organizationId);

        return $page;
    }

    /**
     * @param array{title: string, body: string, parent_id: ?string, position: int} $attributes
     *
     * @return array<string, mixed>|null
     */
    public function update(string $id, string $organizationId, ?string $authorId, array $attributes): ?array
    {
        $statement = Database::connection()->prepare(
            'UPDATE doc_pages
                SET title      = :title,
                    body       = :body,
                    parent_id  = :parent_id,
                    position   = :position,
                    updated_by = :author
              WHERE id = :id AND organization_id = :organization_id AND deleted_at IS NULL
              RETURNING id',
        );
        $statement->execute($attributes + ['id' => $id, 'organization_id' => $organizationId, 'author' => $authorId]);

        return $statement->fetchColumn() === false ? null : $this->find($id, $organizationId);
    }

    public function softDelete(string $id, string $organizationId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE doc_pages SET deleted_at = NOW()
              WHERE id = :id AND organization_id = :organization_id AND deleted_at IS NULL',
        );
        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);

        return $statement->rowCount() === 1;
    }

    /**
     * Une page restaurée dont le parent a été supprimé entre-temps revient à
     * la racine : elle ne se cache pas sous une page que plus personne ne voit.
     */
    public function restore(string $id, string $organizationId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE doc_pages p
                SET deleted_at = NULL,
                    parent_id  = CASE
                        WHEN p.parent_id IS NULL THEN NULL
                        WHEN EXISTS (SELECT 1 FROM doc_pages parent
                                      WHERE parent.id = p.parent_id AND parent.deleted_at IS NULL) THEN p.parent_id
                        ELSE NULL
                    END
              WHERE p.id = :id AND p.organization_id = :organization_id AND p.deleted_at IS NOT NULL',
        );
        $statement->execute(['id' => $id, 'organization_id' => $organizationId]);

        return $statement->rowCount() === 1;
    }

    /**
     * @return array{pages: int, updated_7d: int}
     */
    public function statsForOrganization(string $organizationId): array
    {
        $statement = Database::connection()->prepare(
            "SELECT COUNT(*) AS pages,
                    COUNT(*) FILTER (WHERE updated_at >= NOW() - INTERVAL '7 days') AS updated_7d
               FROM doc_pages
              WHERE organization_id = :organization_id AND deleted_at IS NULL",
        );
        $statement->execute(['organization_id' => $organizationId]);

        $row = $statement->fetch() ?: [];

        return ['pages' => (int) ($row['pages'] ?? 0), 'updated_7d' => (int) ($row['updated_7d'] ?? 0)];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id'              => (string) $row['id'],
            'parent_id'       => $row['parent_id'] === null ? null : (string) $row['parent_id'],
            'title'           => (string) $row['title'],
            'body'            => (string) $row['body'],
            'position'        => (int) $row['position'],
            'version'         => (int) $row['version'],
            'created_at'      => Database::toIso($row['created_at']),
            'updated_at'      => Database::toIso($row['updated_at']),
            'updated_by_name' => $row['updated_by_name'] === null ? null : (string) $row['updated_by_name'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrateSummary(array $row): array
    {
        return [
            'id'              => (string) $row['id'],
            'parent_id'       => $row['parent_id'] === null ? null : (string) $row['parent_id'],
            'title'           => (string) $row['title'],
            'position'        => (int) $row['position'],
            'version'         => (int) $row['version'],
            'updated_at'      => Database::toIso($row['updated_at']),
            'updated_by_name' => $row['updated_by_name'] === null ? null : (string) $row['updated_by_name'],
            'head'            => (string) $row['head'],
        ];
    }
}
