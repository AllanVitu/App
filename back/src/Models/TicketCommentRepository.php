<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Les commentaires d'un ticket.
 *
 * Chaque lecture et chaque écriture portent l'espace ET le ticket : un
 * identifiant de commentaire deviné ne suffit jamais à atteindre la
 * discussion d'une autre équipe.
 */
final class TicketCommentRepository
{
    /** Au-delà, le fil se lit dans l'historique du ticket, pas dans un panneau. */
    public const LIMITE = 200;

    private const COLUMNS = 'c.id, c.ticket_id, c.author_id, c.body, c.created_at,
                             (SELECT u.full_name FROM users u WHERE u.id = c.author_id) AS author_name';

    /**
     * Le fil, du plus ancien au plus récent : une discussion se lit dans
     * l'ordre où elle a eu lieu.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTicket(string $ticketId, string $organizationId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM (
                 SELECT ' . self::COLUMNS . '
                   FROM ticket_comments c
                  WHERE c.ticket_id = :ticket_id
                    AND c.organization_id = :organization_id
                    AND c.deleted_at IS NULL
                  ORDER BY c.created_at DESC
                  LIMIT ' . self::LIMITE . '
             ) AS recents
             ORDER BY created_at ASC',
        );

        $statement->execute(['ticket_id' => $ticketId, 'organization_id' => $organizationId]);

        return array_map($this->hydrate(...), $statement->fetchAll());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id, string $ticketId, string $organizationId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM ticket_comments c
              WHERE c.id = :id
                AND c.ticket_id = :ticket_id
                AND c.organization_id = :organization_id
                AND c.deleted_at IS NULL',
        );

        $statement->execute(['id' => $id, 'ticket_id' => $ticketId, 'organization_id' => $organizationId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $ticketId, string $organizationId, ?string $authorId, string $body): array
    {
        $statement = Database::connection()->prepare(
            'WITH cree AS (
                 INSERT INTO ticket_comments (organization_id, ticket_id, author_id, body)
                 VALUES (:organization_id, :ticket_id, :author_id, :body)
                 RETURNING *
             )
             SELECT ' . self::COLUMNS . ' FROM cree c',
        );

        $statement->execute([
            'organization_id' => $organizationId,
            'ticket_id'       => $ticketId,
            'author_id'       => $authorId,
            'body'            => $body,
        ]);

        /** @var array<string, mixed> $row */
        $row = $statement->fetch();

        return $this->hydrate($row);
    }

    public function softDelete(string $id, string $ticketId, string $organizationId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE ticket_comments
                SET deleted_at = NOW()
              WHERE id = :id
                AND ticket_id = :ticket_id
                AND organization_id = :organization_id
                AND deleted_at IS NULL',
        );

        $statement->execute(['id' => $id, 'ticket_id' => $ticketId, 'organization_id' => $organizationId]);

        return $statement->rowCount() === 1;
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id'          => (string) $row['id'],
            'ticket_id'   => (string) $row['ticket_id'],
            'author_id'   => $row['author_id'] !== null ? (string) $row['author_id'] : null,
            // NULL pour un compte supprimé : le commentaire reste, sans nom.
            'author_name' => $row['author_name'] !== null ? (string) $row['author_name'] : null,
            'body'        => (string) $row['body'],
            'created_at'  => Database::toIso($row['created_at']),
        ];
    }
}
