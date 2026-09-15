<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Secrets;
use RuntimeException;

/**
 * Chiffre ce que la base ne doit pas livrer seule.
 *
 * libsodium (XSalsa20-Poly1305) : chiffré ET authentifié — un contenu altéré
 * est refusé, pas déchiffré en charabia. La clé est dérivée de la clé maîtresse
 * du serveur pour un USAGE donné (Secrets::derive) : deux usages n'ont jamais
 * la même clé, et aucune n'est stockée en base.
 *
 * ⚠ Changer la clé maîtresse (APP_KEY, à défaut JWT_SECRET) rend illisible ce
 * qui a été scellé avec l'ancienne.
 */
final class SecretBox
{
    public static function seal(string $clair, string $usage): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($clair, $nonce, self::cle($usage)));
    }

    public static function open(string $scelle, string $usage): string
    {
        $brut = base64_decode($scelle, true);

        if ($brut === false || strlen($brut) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Secret scellé illisible.');
        }

        $clair = sodium_crypto_secretbox_open(
            substr($brut, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($brut, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            self::cle($usage),
        );

        if ($clair === false) {
            throw new RuntimeException('Secret scellé illisible : contenu altéré, ou clé différente.');
        }

        return $clair;
    }

    /**
     * @return non-empty-string
     */
    private static function cle(string $usage): string
    {
        /** @var non-empty-string $cle */
        $cle = Secrets::derive('secretbox/' . $usage);

        return $cle;
    }
}
