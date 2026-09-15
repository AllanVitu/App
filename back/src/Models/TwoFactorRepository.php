<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * La double authentification, en base : secrets scellés, dernier pas accepté,
 * codes de secours en empreinte, défis de connexion.
 *
 * Aucune méthode ne renvoie un secret en clair : le chiffrement vit dans
 * App\Services\TwoFactor, la base ne voit que des scellés.
 */
final class TwoFactorRepository
{
    /**
     * @return array{secret: ?string, pending: ?string, enabled_at: ?string, last_step: ?int}
     */
    public function state(string $userId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT two_factor_secret, two_factor_pending_secret, two_factor_enabled_at, two_factor_last_step
               FROM users WHERE id = :id',
        );
        $statement->execute(['id' => $userId]);

        $row = $statement->fetch() ?: [];

        return [
            'secret'     => isset($row['two_factor_secret']) ? (string) $row['two_factor_secret'] : null,
            'pending'    => isset($row['two_factor_pending_secret']) ? (string) $row['two_factor_pending_secret'] : null,
            'enabled_at' => Database::toIso($row['two_factor_enabled_at'] ?? null),
            'last_step'  => isset($row['two_factor_last_step']) ? (int) $row['two_factor_last_step'] : null,
        ];
    }

    public function setPending(string $userId, string $scelle): void
    {
        Database::connection()
            ->prepare('UPDATE users SET two_factor_pending_secret = :scelle WHERE id = :id')
            ->execute(['id' => $userId, 'scelle' => $scelle]);
    }

    /**
     * Le secret en attente devient le secret actif, le premier code accepté
     * devient le dernier pas, et les codes de secours sont posés — ensemble.
     *
     * @param list<string> $empreintes
     */
    public function enable(string $userId, int $pas, array $empreintes): void
    {
        Database::transaction(function (\PDO $pdo) use ($userId, $pas, $empreintes): void {
            $pdo->prepare(
                'UPDATE users
                    SET two_factor_secret         = two_factor_pending_secret,
                        two_factor_pending_secret = NULL,
                        two_factor_enabled_at     = NOW(),
                        two_factor_last_step      = :pas
                  WHERE id = :id AND two_factor_pending_secret IS NOT NULL',
            )->execute(['id' => $userId, 'pas' => $pas]);

            $this->replaceRecoveryCodes($userId, $empreintes);
        });
    }

    public function disable(string $userId): void
    {
        Database::transaction(function (\PDO $pdo) use ($userId): void {
            $pdo->prepare(
                'UPDATE users
                    SET two_factor_secret = NULL, two_factor_pending_secret = NULL,
                        two_factor_enabled_at = NULL, two_factor_last_step = NULL
                  WHERE id = :id',
            )->execute(['id' => $userId]);

            $pdo->prepare('DELETE FROM two_factor_recovery_codes WHERE user_id = :id')->execute(['id' => $userId]);
            $pdo->prepare('DELETE FROM two_factor_challenges WHERE user_id = :id')->execute(['id' => $userId]);
        });
    }

    /**
     * Réserve un pas de temps, ATOMIQUEMENT : n'aboutit que si aucun pas égal
     * ou postérieur n'a déjà été accepté. Deux requêtes simultanées avec le
     * même code : une seule gagne.
     */
    public function acceptStep(string $userId, int $pas): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE users SET two_factor_last_step = :pas
              WHERE id = :id
                AND two_factor_enabled_at IS NOT NULL
                AND (two_factor_last_step IS NULL OR two_factor_last_step < :pas)',
        );
        $statement->execute(['id' => $userId, 'pas' => $pas]);

        return $statement->rowCount() === 1;
    }

    /**
     * @param list<string> $empreintes
     */
    public function replaceRecoveryCodes(string $userId, array $empreintes): void
    {
        Database::transaction(function (\PDO $pdo) use ($userId, $empreintes): void {
            $pdo->prepare('DELETE FROM two_factor_recovery_codes WHERE user_id = :id')->execute(['id' => $userId]);

            $insertion = $pdo->prepare('INSERT INTO two_factor_recovery_codes (user_id, code_hash) VALUES (:id, :empreinte)');

            foreach ($empreintes as $empreinte) {
                $insertion->execute(['id' => $userId, 'empreinte' => $empreinte]);
            }
        });
    }

    /** Consomme un code de secours : n'aboutit qu'une fois par code. */
    public function consumeRecoveryCode(string $userId, string $empreinte): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE two_factor_recovery_codes SET used_at = NOW()
              WHERE user_id = :id AND code_hash = :empreinte AND used_at IS NULL',
        );
        $statement->execute(['id' => $userId, 'empreinte' => $empreinte]);

        return $statement->rowCount() === 1;
    }

    public function remainingRecoveryCodes(string $userId): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM two_factor_recovery_codes WHERE user_id = :id AND used_at IS NULL',
        );
        $statement->execute(['id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    public function createChallenge(string $userId, string $empreinte, int $secondes): void
    {
        Database::connection()
            ->prepare(
                'INSERT INTO two_factor_challenges (user_id, token_hash, expires_at)
                 VALUES (:id, :empreinte, NOW() + make_interval(secs => :secondes))',
            )
            ->execute(['id' => $userId, 'empreinte' => $empreinte, 'secondes' => $secondes]);
    }

    /**
     * Un défi encore valable, ou null — inconnu ou expiré, c'est la même réponse.
     *
     * @return array{id: string, user_id: string}|null
     */
    public function findChallenge(string $empreinte): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, user_id FROM two_factor_challenges WHERE token_hash = :empreinte AND expires_at > NOW()',
        );
        $statement->execute(['empreinte' => $empreinte]);

        $row = $statement->fetch();

        return $row === false ? null : ['id' => (string) $row['id'], 'user_id' => (string) $row['user_id']];
    }

    /** Compte un essai manqué ; renvoie le nombre d'essais manqués du défi. */
    public function countFailure(string $challengeId): int
    {
        $statement = Database::connection()->prepare(
            'UPDATE two_factor_challenges SET attempts = attempts + 1 WHERE id = :id RETURNING attempts',
        );
        $statement->execute(['id' => $challengeId]);

        return (int) $statement->fetchColumn();
    }

    public function deleteChallenge(string $challengeId): void
    {
        Database::connection()
            ->prepare('DELETE FROM two_factor_challenges WHERE id = :id')
            ->execute(['id' => $challengeId]);
    }
}
