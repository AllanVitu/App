<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;

/**
 * Gestion des jetons de rafraîchissement.
 *
 * Principes de sécurité :
 *  - le jeton est une valeur aléatoire de 32 octets, sans structure lisible ;
 *  - seul son SHA-256 est stocké : une fuite de la base ne permet pas de
 *    rejouer les sessions ;
 *  - il transite dans un cookie HttpOnly (inaccessible au JavaScript, donc
 *    hors de portée d'une injection XSS) ;
 *  - il est tourné à chaque rafraîchissement : un jeton déjà consommé est
 *    définitivement révoqué.
 */
final class RefreshTokenService
{
    public const COOKIE_NAME = 'refresh_token';

    /**
     * Crée un jeton et l'enregistre (haché) pour l'utilisateur.
     *
     * @return string Le jeton en clair — à transmettre au client, jamais à journaliser
     */
    public function issue(string $userId, Request $request): string
    {
        $token = bin2hex(random_bytes(32));
        $ttl   = Env::int('JWT_REFRESH_TTL', 1209600);

        $statement = Database::connection()->prepare(
            'INSERT INTO refresh_tokens (user_id, token_hash, expires_at, user_agent, ip_address)
             VALUES (:user_id, :token_hash, NOW() + (:ttl || \' seconds\')::interval, :user_agent, :ip)',
        );

        $statement->execute([
            'user_id'    => $userId,
            'token_hash' => $this->hash($token),
            'ttl'        => (string) $ttl,
            'user_agent' => $request->userAgent(),
            'ip'         => $request->ip(),
        ]);

        return $token;
    }

    /**
     * Valide un jeton et le remplace par un nouveau (rotation).
     *
     * @return array{user_id: string, token: string}
     * @throws HttpException 401 si le jeton est inconnu, révoqué ou expiré
     */
    public function rotate(string $token, Request $request): array
    {
        return Database::transaction(function () use ($token, $request): array {
            $pdo = Database::connection();

            // FOR UPDATE : deux rafraîchissements concurrents ne peuvent pas
            // consommer le même jeton.
            $statement = $pdo->prepare(
                'SELECT id, user_id
                   FROM refresh_tokens
                  WHERE token_hash = :hash
                    AND revoked_at IS NULL
                    AND expires_at > NOW()
                  FOR UPDATE',
            );
            $statement->execute(['hash' => $this->hash($token)]);
            $row = $statement->fetch();

            if ($row === false) {
                throw HttpException::unauthorized('Session expirée, veuillez vous reconnecter.');
            }

            $pdo->prepare('UPDATE refresh_tokens SET revoked_at = NOW() WHERE id = :id')
                ->execute(['id' => $row['id']]);

            return [
                'user_id' => (string) $row['user_id'],
                'token'   => $this->issue((string) $row['user_id'], $request),
            ];
        });
    }

    /**
     * Révoque un jeton précis (déconnexion de l'appareil courant).
     */
    public function revoke(string $token): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE refresh_tokens SET revoked_at = NOW()
              WHERE token_hash = :hash AND revoked_at IS NULL',
        );

        $statement->execute(['hash' => $this->hash($token)]);
    }

    /**
     * Révoque toutes les sessions d'un utilisateur.
     * Appelé après un changement de mot de passe : les appareils déjà
     * connectés doivent se réauthentifier.
     */
    public function revokeAllForUser(string $userId): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE refresh_tokens SET revoked_at = NOW()
              WHERE user_id = :user_id AND revoked_at IS NULL',
        );

        $statement->execute(['user_id' => $userId]);
    }

    /**
     * Dépose le cookie HttpOnly contenant le jeton.
     */
    public function sendCookie(string $token): void
    {
        setcookie(self::COOKIE_NAME, $token, $this->cookieOptions(Env::int('JWT_REFRESH_TTL', 1209600)));
    }

    /**
     * Supprime le cookie côté navigateur (déconnexion).
     */
    public function clearCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', $this->cookieOptions(-3600));
    }

    /**
     * @return array<string, mixed>
     */
    private function cookieOptions(int $lifetime): array
    {
        // En déploiement, front et API sont servis par le même Nginx, donc sur
        // la même origine : « Strict » est alors le réglage le plus sûr.
        // Si l'API est hébergée sur un domaine distinct du front, il faut
        // COOKIE_SAMESITE=None — ce qui impose obligatoirement HTTPS.
        $sameSite = Env::get('COOKIE_SAMESITE', Env::isProduction() ? 'Strict' : 'Lax') ?? 'Lax';
        $sameSite = in_array($sameSite, ['Strict', 'Lax', 'None'], true) ? $sameSite : 'Lax';

        return [
            'expires'  => time() + $lifetime,
            // Restreint l'envoi du cookie aux seules routes d'authentification.
            'path'     => '/api/auth',
            'domain'   => '',
            // Hors développement, le cookie ne doit jamais transiter en clair.
            // SameSite=None est de toute façon ignoré sans l'attribut Secure.
            'secure'   => Env::bool('COOKIE_SECURE', Env::isProduction()) || $sameSite === 'None',
            'httponly' => true,
            'samesite' => $sameSite,
        ];
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
