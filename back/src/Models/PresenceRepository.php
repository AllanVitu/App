<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Qui regarde quoi, en ce moment.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  UN INSTANTANÉ, JAMAIS UN HISTORIQUE                                    │
 * │                                                                         │
 * │  Une ligne par compte, ÉCRASÉE à chaque battement. Conserver les        │
 * │  positions passées de chacun donnerait un registre de qui a regardé     │
 * │  quoi et quand : de la surveillance, pas de l'entraide.                 │
 * │                                                                         │
 * │  Ce que la présence sert à éviter est précis — ouvrir un ticket que     │
 * │  quelqu'un est en train d'écrire. Elle n'a donc pas besoin de mémoire.  │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
final class PresenceRepository
{
    /**
     * Au-delà, on considère la personne partie.
     *
     * Trois battements de sondage : un onglet qui reprend après une seconde de
     * gel ne doit pas faire clignoter son marqueur chez les autres.
     */
    private const FRAICHEUR_SECONDES = 15;

    /**
     * Signale sa position, et repart avec celle des autres.
     *
     * Les deux en un seul aller-retour, parce que le sondage du flux les porte
     * déjà : une présence qui coûterait une requête de plus serait la première
     * chose à couper le jour où l'on cherche à alléger.
     *
     * @return list<array<string, mixed>>
     */
    public function heartbeat(
        string $organizationId,
        string $userId,
        string $screen,
        ?string $subjectId,
    ): array {
        Database::connection()->prepare(
            'INSERT INTO presence (organization_id, user_id, screen, subject_id, seen_at)
             VALUES (:org, :user_id, :screen, :subject_id, NOW())
             ON CONFLICT (organization_id, user_id) DO UPDATE
                    SET screen     = EXCLUDED.screen,
                        subject_id = EXCLUDED.subject_id,
                        seen_at    = NOW()',
        )->execute([
            'org'        => $organizationId,
            'user_id'    => $userId,
            'screen'     => mb_substr($screen, 0, 40),
            'subject_id' => $subjectId,
        ]);

        return $this->others($organizationId, $userId);
    }

    /**
     * Les autres, et eux seuls.
     *
     * S'y voir soi-même n'apprendrait rien et ferait un marqueur de plus sur
     * le ticket qu'on a justement sous les yeux.
     *
     * @return list<array<string, mixed>>
     */
    public function others(string $organizationId, string $userId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT p.user_id, p.screen, p.subject_id, u.full_name
               FROM presence p
               JOIN users u ON u.id = p.user_id
              WHERE p.organization_id = :org
                AND p.user_id <> :user_id
                AND p.seen_at > NOW() - (:fraicheur || \' seconds\')::interval
           ORDER BY u.full_name',
        );

        $statement->execute([
            'org'       => $organizationId,
            'user_id'   => $userId,
            'fraicheur' => (string) self::FRAICHEUR_SECONDES,
        ]);

        return array_map(
            static fn (array $row): array => [
                'user_id'    => (string) $row['user_id'],
                'full_name'  => (string) $row['full_name'],
                'screen'     => (string) $row['screen'],
                'subject_id' => $row['subject_id'] !== null ? (string) $row['subject_id'] : null,
            ],
            $statement->fetchAll(),
        );
    }

    /**
     * Retire sa présence — à la fermeture de l'onglet.
     *
     * Le repli du temps existe et suffirait ; ce départ explicite fait
     * simplement disparaître le marqueur tout de suite, plutôt que quinze
     * secondes plus tard.
     */
    public function leave(string $organizationId, string $userId): void
    {
        Database::connection()
            ->prepare('DELETE FROM presence WHERE organization_id = :org AND user_id = :user_id')
            ->execute(['org' => $organizationId, 'user_id' => $userId]);
    }
}
