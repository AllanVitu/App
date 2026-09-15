<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Codes à usage unique fondés sur le temps — TOTP, RFC 6238.
 *
 * Ce qu'attendent toutes les applications d'authentification (Aegis,
 * 1Password, Google Authenticator…) : HMAC-SHA1, six chiffres, trente
 * secondes. Vérifié contre les vecteurs de test de la RFC
 * (tests/Unit/TotpTest.php).
 */
final class Totp
{
    public const PERIODE  = 30;
    public const CHIFFRES = 6;

    /**
     * Un pas de décalage de chaque côté : une horloge de téléphone approximative
     * est tolérée, pas davantage — chaque pas en plus multiplie les codes
     * valables à un instant donné.
     */
    private const TOLERANCE = 1;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Un secret neuf, en base32 : 160 bits, la taille recommandée pour
     * HMAC-SHA1 (RFC 4226, § 4).
     */
    public static function nouveauSecret(): string
    {
        return self::base32(random_bytes(20));
    }

    /**
     * L'adresse « otpauth:// » que le QR code transporte.
     */
    public static function uri(string $secret, string $compte, string $emetteur = 'Relais'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?%s',
            rawurlencode($emetteur),
            rawurlencode($compte),
            http_build_query([
                'secret'    => $secret,
                'issuer'    => $emetteur,
                'algorithm' => 'SHA1',
                'digits'    => self::CHIFFRES,
                'period'    => self::PERIODE,
            ], '', '&', PHP_QUERY_RFC3986),
        );
    }

    public static function pas(?int $instant = null): int
    {
        return intdiv($instant ?? time(), self::PERIODE);
    }

    /**
     * Le code d'un pas de temps (HOTP, RFC 4226, § 5.3).
     */
    public static function code(string $secret, int $pas): string
    {
        $empreinte = hash_hmac('sha1', pack('J', $pas), self::debase32($secret), true);
        $decalage  = ord($empreinte[19]) & 0x0F;

        $binaire = ((ord($empreinte[$decalage]) & 0x7F) << 24)
            | (ord($empreinte[$decalage + 1]) << 16)
            | (ord($empreinte[$decalage + 2]) << 8)
            | ord($empreinte[$decalage + 3]);

        return str_pad((string) ($binaire % (10 ** self::CHIFFRES)), self::CHIFFRES, '0', STR_PAD_LEFT);
    }

    /**
     * Le pas de temps du code saisi s'il est valable, null sinon.
     *
     * Un pas inférieur ou égal à $dernierPas est refusé : un code déjà accepté
     * ne sert pas une seconde fois, même dans sa fenêtre de validité.
     */
    public static function verifier(string $secret, string $saisie, ?int $dernierPas = null, ?int $instant = null): ?int
    {
        $saisie = (string) preg_replace('/\s+/', '', $saisie);

        if (preg_match('/^[0-9]{' . self::CHIFFRES . '}$/', $saisie) !== 1) {
            return null;
        }

        $courant = self::pas($instant);

        for ($ecart = -self::TOLERANCE; $ecart <= self::TOLERANCE; $ecart++) {
            $pas = $courant + $ecart;

            if ($dernierPas !== null && $pas <= $dernierPas) {
                continue;
            }

            // hash_equals : la comparaison prend le même temps, quel que soit
            // le nombre de chiffres justes.
            if (hash_equals(self::code($secret, $pas), $saisie)) {
                return $pas;
            }
        }

        return null;
    }

    public static function base32(string $octets): string
    {
        $bits = '';

        foreach (str_split($octets) as $octet) {
            $bits .= str_pad(decbin(ord($octet)), 8, '0', STR_PAD_LEFT);
        }

        $texte = '';

        foreach (str_split($bits, 5) as $bloc) {
            $texte .= self::BASE32[(int) bindec(str_pad($bloc, 5, '0'))];
        }

        return $texte;
    }

    public static function debase32(string $texte): string
    {
        $texte = strtoupper((string) preg_replace('/[\s=]+/', '', $texte));

        if ($texte === '' || strspn($texte, self::BASE32) !== strlen($texte)) {
            throw new InvalidArgumentException('Secret base32 invalide.');
        }

        $bits = '';

        foreach (str_split($texte) as $caractere) {
            $bits .= str_pad(decbin((int) strpos(self::BASE32, $caractere)), 5, '0', STR_PAD_LEFT);
        }

        $octets = '';

        foreach (str_split($bits, 8) as $bloc) {
            if (strlen($bloc) === 8) {
                $octets .= chr((int) bindec($bloc));
            }
        }

        return $octets;
    }
}
