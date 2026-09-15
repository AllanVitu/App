<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/**
 * Sous-clés dérivées, une par usage.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  UN SECRET PAR USAGE, JAMAIS LE MÊME DEUX FOIS                          │
 * │                                                                         │
 * │  Signer un jeton de session et pseudonymiser une adresse IP avec la     │
 * │  même clé brute lierait deux mécanismes qui n'ont rien en commun : une  │
 * │  faiblesse de l'un deviendrait celle de l'autre, et changer l'un        │
 * │  obligerait à changer l'autre.                                          │
 * │                                                                         │
 * │  HKDF (RFC 5869) tire de la clé maîtresse une sous-clé indépendante par │
 * │  usage. L'usage est nommé en clair à l'appel — « rate-limit »,          │
 * │  « signed-url » — ce qui rend chaque dérivation lisible là où elle sert. │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * La clé maîtresse est APP_KEY, et à défaut JWT_SECRET. Ce repli n'est sûr que
 * pour des usages dont la perte est sans conséquence — un compteur remis à
 * zéro, une URL signée qui expire plus tôt — et c'est à eux seuls qu'il sert :
 * rien de ce qui doit être DÉCHIFFRÉ plus tard ne dérive d'ici.
 */
final class Secrets
{
    public static function derive(string $purpose): string
    {
        return hash_hkdf('sha256', self::master(), 32, 'saas/' . $purpose);
    }

    private static function master(): string
    {
        $master = Env::get('APP_KEY') ?? Env::mustGet('JWT_SECRET');

        if (strlen($master) < 32) {
            throw new RuntimeException('La clé maîtresse doit faire au moins 32 caractères.');
        }

        return $master;
    }
}
