<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Secrets;

/**
 * Adresses de fichier signées, à durée de vie courte.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  UNE BALISE <img> N'ENVOIE PAS D'EN-TÊTE D'AUTORISATION                 │
 * │                                                                         │
 * │  Le jeton d'accès vit en mémoire et part dans « Authorization » : aucun │
 * │  navigateur ne l'ajoute à une image. Restaient deux chemins, mauvais    │
 * │  tous les deux — mettre le jeton de session dans l'adresse, où il       │
 * │  finirait dans les journaux et l'historique ; ou télécharger chaque     │
 * │  image en JavaScript pour en faire un blob, sans cache, une requête par │
 * │  avatar à chaque écran.                                                 │
 * │                                                                         │
 * │  L'adresse signée est le troisième. L'API ne la remet qu'à quelqu'un    │
 * │  qui a le droit de voir le fichier, elle ne désigne que CE fichier, et  │
 * │  elle expire. Un lien qui fuit ouvre une image pendant une heure ou     │
 * │  deux — jamais un compte.                                               │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * L'ÉCHÉANCE EST ARRONDIE À L'HEURE. Une adresse recalculée à chaque réponse
 * ne serait jamais la même deux fois : le navigateur retéléchargerait chaque
 * avatar à chaque écran. Arrondie, elle reste identique pendant l'heure, et le
 * cache fait son travail.
 */
final class SignedUrl
{
    /** Durée de validité garantie au minimum ; l'arrondi en ajoute jusqu'à une heure. */
    private const LIFETIME = 3600;

    private const ROUNDING = 3600;

    public static function forFile(string $fileId, ?int $now = null): string
    {
        $expires = self::expiryFor($now ?? time());

        return sprintf(
            '/api/files/%s?expires=%d&signature=%s',
            $fileId,
            $expires,
            self::signature($fileId, $expires),
        );
    }

    /**
     * Toujours entre une et deux heures devant, et la même pour tout instant
     * d'une même heure.
     */
    public static function expiryFor(int $now): int
    {
        return (intdiv($now + self::LIFETIME, self::ROUNDING) + 1) * self::ROUNDING;
    }

    public static function isValid(string $fileId, string $expires, string $signature, ?int $now = null): bool
    {
        if (preg_match('/^\d{1,12}$/', $expires) !== 1 || (int) $expires <= ($now ?? time())) {
            return false;
        }

        return hash_equals(self::signature($fileId, (int) $expires), $signature);
    }

    private static function signature(string $fileId, int $expires): string
    {
        // Le séparateur NUL empêche deux couples différents de signer la même
        // chaîne.
        $mac = hash_hmac('sha256', "file\0{$fileId}\0{$expires}", Secrets::derive('signed-url'), true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }
}
