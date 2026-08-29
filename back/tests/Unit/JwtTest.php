<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\HttpException;
use App\Services\Jwt;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Le jeton d'accès est la clé de toute l'API : ces tests couvrent les
 * attaques classiques contre une implémentation JWT, pas seulement le
 * chemin nominal.
 */
final class JwtTest extends TestCase
{
    #[Test]
    public function un_jeton_emis_est_relu_avec_ses_revendications(): void
    {
        $token = Jwt::issue('11111111-1111-1111-1111-111111111111', 'admin');

        $claims = Jwt::verify($token);

        $this->assertSame('11111111-1111-1111-1111-111111111111', $claims['sub']);
        $this->assertSame('admin', $claims['rol']);
        $this->assertSame('saas-api-test', $claims['iss']);
    }

    #[Test]
    public function deux_jetons_successifs_different(): void
    {
        // La revendication « jti » rend chaque jeton unique : sans elle, deux
        // connexions à la même seconde produiraient le même jeton.
        $this->assertNotSame(Jwt::issue('u', 'user'), Jwt::issue('u', 'user'));
    }

    #[Test]
    public function une_charge_utile_modifiee_est_rejetee(): void
    {
        [$header, $payload, $signature] = explode('.', Jwt::issue('u', 'user'));

        $decoded = json_decode(self::base64UrlDecode($payload), true);
        $decoded['rol'] = 'admin'; // élévation de privilège
        $forged = self::base64UrlEncode((string) json_encode($decoded));

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Signature du jeton invalide.');

        Jwt::verify("{$header}.{$forged}.{$signature}");
    }

    #[Test]
    public function un_jeton_sans_signature_est_rejete(): void
    {
        // Attaque « alg: none » : l'algorithme est imposé côté serveur, il
        // n'est jamais lu depuis l'en-tête du jeton.
        $header = self::base64UrlEncode((string) json_encode(['alg' => 'none', 'typ' => 'JWT']));
        $payload = self::base64UrlEncode((string) json_encode([
            'iss' => 'saas-api-test',
            'sub' => 'u',
            'exp' => time() + 900,
        ]));

        $this->expectException(HttpException::class);

        Jwt::verify("{$header}.{$payload}.");
    }

    #[Test]
    public function un_jeton_expire_est_rejete(): void
    {
        putenv('JWT_ACCESS_TTL=-10');

        // Env met ses lectures en cache : la durée de vie est relue à chaque
        // émission via Env::int, mais le cache la fige. On contourne en
        // fabriquant le jeton à la main plutôt qu'en luttant contre le cache.
        $expired = self::forge(['iss' => 'saas-api-test', 'sub' => 'u', 'exp' => time() - 120]);

        putenv('JWT_ACCESS_TTL=900');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Jeton expiré.');

        Jwt::verify($expired);
    }

    #[Test]
    public function un_emetteur_inattendu_est_rejete(): void
    {
        $token = self::forge(['iss' => 'un-autre-service', 'sub' => 'u', 'exp' => time() + 900]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Émetteur du jeton inattendu.');

        Jwt::verify($token);
    }

    #[Test]
    public function une_chaine_quelconque_est_rejetee(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Jeton malformé.');

        Jwt::verify('pas-du-tout-un-jeton');
    }

    /**
     * Fabrique un jeton correctement signé mais aux revendications choisies,
     * pour tester les vérifications qui suivent la signature.
     *
     * @param array<string, mixed> $claims
     */
    private static function forge(array $claims): string
    {
        $header = self::base64UrlEncode((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::base64UrlEncode((string) json_encode($claims));
        $secret = (string) getenv('JWT_SECRET');
        $signature = self::base64UrlEncode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));

        return "{$header}.{$payload}.{$signature}";
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
