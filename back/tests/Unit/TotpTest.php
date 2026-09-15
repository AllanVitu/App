<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Totp;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TOTP contre la RFC 6238 elle-même.
 *
 * Les applications d'authentification suivent la norme à la lettre : un code
 * « presque juste » serait refusé par toutes. Les vecteurs de l'annexe B de la
 * RFC (SHA-1, secret « 12345678901234567890 ») sont donnés sur huit chiffres ;
 * les six derniers sont ce qu'affiche une application.
 */
final class TotpTest extends TestCase
{
    private const SECRET_RFC = '12345678901234567890';

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function vecteurs(): iterable
    {
        yield '59'          => [59, '287082'];
        yield '1111111109'  => [1111111109, '081804'];
        yield '1111111111'  => [1111111111, '050471'];
        yield '1234567890'  => [1234567890, '005924'];
        yield '2000000000'  => [2000000000, '279037'];
        yield '20000000000' => [20000000000, '353130'];
    }

    #[Test]
    #[DataProvider('vecteurs')]
    public function les_codes_sont_ceux_de_la_rfc(int $instant, string $attendu): void
    {
        $this->assertSame($attendu, Totp::code(Totp::base32(self::SECRET_RFC), Totp::pas($instant)));
    }

    #[Test]
    public function le_base32_fait_l_aller_retour(): void
    {
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', Totp::base32(self::SECRET_RFC));
        $this->assertSame(self::SECRET_RFC, Totp::debase32('gezd gnbv gy3t qojq gezd gnbv gy3t qojq'));

        $octets = random_bytes(20);
        $this->assertSame($octets, Totp::debase32(Totp::base32($octets)));
    }

    #[Test]
    public function un_secret_neuf_fait_160_bits(): void
    {
        $secret = Totp::nouveauSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertSame(20, strlen(Totp::debase32($secret)));
        $this->assertNotSame($secret, Totp::nouveauSecret());
    }

    #[Test]
    public function un_pas_d_horloge_de_chaque_cote_est_tolere_pas_davantage(): void
    {
        $secret  = Totp::nouveauSecret();
        $instant = 1_800_000_000;
        $pas     = Totp::pas($instant);

        $this->assertSame($pas - 1, Totp::verifier($secret, Totp::code($secret, $pas - 1), null, $instant));
        $this->assertSame($pas + 1, Totp::verifier($secret, Totp::code($secret, $pas + 1), null, $instant));
        $this->assertNull(Totp::verifier($secret, Totp::code($secret, $pas + 2), null, $instant));
        $this->assertNull(Totp::verifier($secret, Totp::code($secret, $pas - 2), null, $instant));
    }

    #[Test]
    public function un_code_deja_accepte_ne_sert_pas_deux_fois(): void
    {
        $secret  = Totp::nouveauSecret();
        $instant = 1_800_000_000;
        $code    = Totp::code($secret, Totp::pas($instant));

        $pas = Totp::verifier($secret, $code, null, $instant);

        $this->assertNotNull($pas);
        $this->assertNull(Totp::verifier($secret, $code, $pas, $instant), 'le même pas, rejoué');
    }

    #[Test]
    public function une_saisie_qui_n_est_pas_six_chiffres_est_refusee(): void
    {
        $secret = Totp::nouveauSecret();
        $code   = Totp::code($secret, Totp::pas());

        $this->assertNotNull(Totp::verifier($secret, substr($code, 0, 3) . ' ' . substr($code, 3)), 'un espace ne compte pas');

        foreach (['', '12345', '1234567', 'abcdef', '12 34 5'] as $saisie) {
            $this->assertNull(Totp::verifier($secret, $saisie));
        }
    }

    #[Test]
    public function l_adresse_du_qr_code_suit_le_format_des_applications(): void
    {
        $uri = Totp::uri('JBSWY3DPEHPK3PXP', 'camille@exemple.fr');

        $this->assertStringStartsWith('otpauth://totp/Relais:camille%40exemple.fr?', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=Relais', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }

    #[Test]
    public function un_secret_illisible_est_refuse(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Totp::debase32('pas du base32 !');
    }
}
