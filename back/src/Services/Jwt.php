<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Core\HttpException;

/**
 * Jetons JWT signés en HS256.
 *
 * Implémentation volontairement autonome (aucune dépendance externe) mais
 * conforme à la RFC 7519 sur les points qui comptent :
 *  - l'algorithme attendu est imposé côté serveur, jamais lu depuis le jeton
 *    (parade à l'attaque « alg: none » et à la confusion d'algorithme) ;
 *  - la signature est comparée avec hash_equals() (temps constant) ;
 *  - exp, nbf et iss sont vérifiés systématiquement.
 */
final class Jwt
{
    private const ALGORITHM = 'HS256';

    /**
     * Petite tolérance d'horloge entre le conteneur PHP et le client.
     */
    private const LEEWAY_SECONDS = 30;

    /**
     * Génère un jeton d'accès pour un utilisateur.
     *
     * @param array<string, mixed> $claims Revendications additionnelles
     */
    public static function issue(string $userId, string $role, array $claims = []): string
    {
        $issuedAt = time();
        $ttl      = Env::int('JWT_ACCESS_TTL', 900);

        $payload = [
            'iss' => Env::get('JWT_ISSUER', 'saas-api'),
            'sub' => $userId,
            'rol' => $role,
            'iat' => $issuedAt,
            'nbf' => $issuedAt,
            'exp' => $issuedAt + $ttl,
            // Identifiant unique : permettrait une liste de révocation ciblée.
            'jti' => bin2hex(random_bytes(16)),
            ...$claims,
        ];

        $segments = [
            self::base64UrlEncode((string) json_encode(['alg' => self::ALGORITHM, 'typ' => 'JWT'])),
            self::base64UrlEncode((string) json_encode($payload)),
        ];

        $signingInput = implode('.', $segments);
        $segments[]   = self::base64UrlEncode(self::sign($signingInput));

        return implode('.', $segments);
    }

    /**
     * Vérifie et décode un jeton.
     *
     * @return array<string, mixed> Les revendications
     * @throws HttpException 401 si le jeton est absent, altéré ou expiré
     */
    public static function verify(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw HttpException::unauthorized('Jeton malformé.');
        }

        [$header64, $payload64, $signature64] = $parts;

        // La signature est vérifiée AVANT toute exploitation du contenu.
        $expected = self::sign($header64 . '.' . $payload64);
        $provided = self::base64UrlDecode($signature64);

        if ($provided === null || !hash_equals($expected, $provided)) {
            throw HttpException::unauthorized('Signature du jeton invalide.');
        }

        $header  = self::decodeSegment($header64);
        $payload = self::decodeSegment($payload64);

        // L'algorithme annoncé doit correspondre à celui que l'on impose.
        if (($header['alg'] ?? null) !== self::ALGORITHM) {
            throw HttpException::unauthorized('Algorithme de jeton non supporté.');
        }

        $now = time();

        if (isset($payload['nbf']) && $now + self::LEEWAY_SECONDS < (int) $payload['nbf']) {
            throw HttpException::unauthorized("Jeton pas encore valide.");
        }

        if (!isset($payload['exp']) || $now - self::LEEWAY_SECONDS >= (int) $payload['exp']) {
            throw HttpException::unauthorized('Jeton expiré.');
        }

        if (($payload['iss'] ?? null) !== Env::get('JWT_ISSUER', 'saas-api')) {
            throw HttpException::unauthorized('Émetteur du jeton inattendu.');
        }

        if (!isset($payload['sub']) || !is_string($payload['sub'])) {
            throw HttpException::unauthorized('Jeton incomplet.');
        }

        return $payload;
    }

    /**
     * Durée de vie du jeton d'accès, exposée au client pour anticiper
     * le rafraîchissement.
     */
    public static function ttl(): int
    {
        return Env::int('JWT_ACCESS_TTL', 900);
    }

    private static function sign(string $input): string
    {
        return hash_hmac('sha256', $input, self::secret(), true);
    }

    private static function secret(): string
    {
        $secret = Env::mustGet('JWT_SECRET');

        // Un secret trop court rend la signature attaquable par force brute.
        if (strlen($secret) < 32) {
            throw new \RuntimeException('JWT_SECRET doit faire au moins 32 caractères.');
        }

        return $secret;
    }

    /** @return array<string, mixed> */
    private static function decodeSegment(string $segment): array
    {
        $json = self::base64UrlDecode($segment);

        if ($json === null) {
            throw HttpException::unauthorized('Jeton illisible.');
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw HttpException::unauthorized('Jeton illisible.');
        }

        return $decoded;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): ?string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
