<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;

/**
 * Jetons à usage unique : vérification d'e-mail et réinitialisation de mot
 * de passe.
 *
 * Mêmes règles que pour les jetons de rafraîchissement :
 *  - valeur aléatoire de 32 octets, sans structure devinable ;
 *  - seul le SHA-256 est stocké — un accès en lecture à la base ne permet pas
 *    de fabriquer un lien valide ;
 *  - usage unique et durée de vie courte ;
 *  - une nouvelle demande invalide les précédentes, pour qu'un lien envoyé
 *    par erreur cesse immédiatement de fonctionner.
 */
final class UserTokenService
{
    public const TYPE_EMAIL_VERIFICATION = 'email_verification';
    public const TYPE_PASSWORD_RESET     = 'password_reset';

    /** Durées de vie, en secondes. */
    private const TTL = [
        self::TYPE_EMAIL_VERIFICATION => 86400,  // 24 h
        self::TYPE_PASSWORD_RESET     => 3600,   // 1 h — fenêtre courte
    ];

    /**
     * Crée un jeton et renvoie sa valeur en clair.
     * Cette valeur ne doit apparaître QUE dans le lien envoyé par e-mail :
     * ni en base, ni dans les journaux, ni dans une réponse d'API.
     */
    public function issue(string $userId, string $type, Request $request): string
    {
        $token = bin2hex(random_bytes(32));

        Database::transaction(function () use ($userId, $type, $token, $request): void {
            $pdo = Database::connection();

            // Les demandes précédentes encore valides sont neutralisées.
            $pdo->prepare(
                'UPDATE user_tokens
                    SET used_at = NOW()
                  WHERE user_id = :user_id AND type = :type::user_token_type AND used_at IS NULL',
            )->execute(['user_id' => $userId, 'type' => $type]);

            $pdo->prepare(
                'INSERT INTO user_tokens (user_id, type, token_hash, expires_at, ip_address)
                 VALUES (:user_id, :type::user_token_type, :hash,
                         NOW() + (:ttl || \' seconds\')::interval, :ip)',
            )->execute([
                'user_id' => $userId,
                'type'    => $type,
                'hash'    => $this->hash($token),
                'ttl'     => (string) (self::TTL[$type] ?? 3600),
                'ip'      => $request->ip(),
            ]);
        });

        return $token;
    }

    /**
     * Valide un jeton et le consomme.
     *
     * @return string Identifiant de l'utilisateur concerné
     * @throws HttpException 400 si le jeton est inconnu, expiré ou déjà utilisé
     */
    public function consume(string $token, string $type): string
    {
        return Database::transaction(function () use ($token, $type): string {
            $pdo = Database::connection();

            // FOR UPDATE : deux clics successifs sur le même lien ne peuvent
            // pas le consommer deux fois.
            $statement = $pdo->prepare(
                'SELECT id, user_id
                   FROM user_tokens
                  WHERE token_hash = :hash
                    AND type = :type::user_token_type
                    AND used_at IS NULL
                    AND expires_at > NOW()
                  FOR UPDATE',
            );
            $statement->execute(['hash' => $this->hash($token), 'type' => $type]);
            $row = $statement->fetch();

            if ($row === false) {
                throw HttpException::badRequest(
                    'Ce lien est invalide ou a expiré. Demandez-en un nouveau.',
                );
            }

            $pdo->prepare('UPDATE user_tokens SET used_at = NOW() WHERE id = :id')
                ->execute(['id' => $row['id']]);

            return (string) $row['user_id'];
        });
    }

    /**
     * Purge les jetons expirés ou consommés depuis plus d'un mois.
     * Appelée opportunément pour éviter que la table ne croisse sans fin.
     */
    public function purgeExpired(): void
    {
        Database::connection()->exec(
            "DELETE FROM user_tokens
              WHERE expires_at < NOW() - INTERVAL '30 days'
                 OR (used_at IS NOT NULL AND used_at < NOW() - INTERVAL '30 days')",
        );
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
