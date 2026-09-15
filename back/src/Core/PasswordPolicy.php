<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Ce qu'un mot de passe doit valoir pour être accepté.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  MESURER, PLUTÔT QU'IMPOSER DES CASES À COCHER                          │
 * │                                                                         │
 * │  « 8 caractères, une lettre, un chiffre » acceptait « motdepasse1 » :   │
 * │  41 bits. La CNIL (délibération n° 2022-100) en demande 80 quand le     │
 * │  mot de passe est le seul facteur d'authentification, et les compte     │
 * │  ainsi : longueur × log2(taille de l'alphabet employé).                 │
 * │                                                                         │
 * │  Elle admet 50 bits si l'accès est temporisé après des échecs — c'est   │
 * │  le cas ici. Le seuil reste à 80, parce que la temporisation ne protège │
 * │  que le formulaire : le jour où une empreinte fuit, il ne reste que     │
 * │  l'entropie entre elle et le mot de passe.                              │
 * │                                                                         │
 * │  Mesurer laisse passer « cheval batterie agrafe », qu'on retient, et    │
 * │  arrête « Motdepasse1 », qu'on devine.                                  │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Le client en tient une copie (front/src/utils/password.js) pour guider la
 * saisie ; celle-ci seule fait foi.
 */
final class PasswordPolicy
{
    public const MIN_BITS   = 80;
    public const MAX_LENGTH = 200;

    /** bcrypt ignore tout ce qui dépasse. */
    private const BCRYPT_BYTES = 72;

    /** « Ab1!Ab1!Ab1!Ab1! » a l'alphabet et la longueur, pas la variété. */
    private const MIN_DISTINCT = 6;

    private const WEAK = 'Mot de passe trop prévisible : 12 caractères mêlant majuscules, minuscules, '
        . 'chiffres et symboles, ou une phrase de passe plus longue.';

    /**
     * Ce qui ne va pas, ou null si le mot de passe est acceptable.
     */
    public static function problem(string $password): ?string
    {
        // Un octet nul tronque le mot de passe pour bcrypt, et password_hash()
        // refuse de le hacher : sans ce contrôle, une erreur 500.
        if (!mb_check_encoding($password, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $password) === 1) {
            return 'Le mot de passe contient des caractères non autorisés.';
        }

        // Garde-fou : un très long mot de passe est un vecteur de déni de
        // service au hachage.
        if (mb_strlen($password) > self::MAX_LENGTH) {
            return 'Le mot de passe est trop long (200 caractères maximum).';
        }

        $read = self::readByBcrypt($password);

        // L'exemple que donne la CNIL elle-même — douze caractères des quatre
        // familles — tombe à 79 bits avec un alphabet de 95 signes : l'écarter
        // contredirait la recommandation qu'on applique.
        $strong = self::bits($password) >= self::MIN_BITS
            || (mb_strlen($read) >= 12 && count(self::families($read)) === 4);

        if (!$strong || count(array_unique(mb_str_split($read))) < self::MIN_DISTINCT) {
            return self::WEAK;
        }

        return null;
    }

    /**
     * Entropie au sens de la CNIL, sur les octets que bcrypt lit réellement.
     */
    public static function bits(string $password): float
    {
        $read = self::readByBcrypt($password);
        $pool = array_sum(self::families($read));

        return $pool === 0 ? 0.0 : mb_strlen($read) * log($pool, 2);
    }

    /**
     * Familles présentes, et le nombre de signes que chacune apporte.
     * Une lettre accentuée compte parmi les « autres » : elle n'est ni dans
     * [a-z] ni dans [A-Z].
     *
     * @return array<string, int>
     */
    private static function families(string $value): array
    {
        $sizes = [];

        if (preg_match('/[a-z]/', $value) === 1) {
            $sizes['minuscules'] = 26;
        }

        if (preg_match('/[A-Z]/', $value) === 1) {
            $sizes['majuscules'] = 26;
        }

        if (preg_match('/[0-9]/', $value) === 1) {
            $sizes['chiffres'] = 10;
        }

        if (preg_match('/[^a-zA-Z0-9]/u', $value) === 1) {
            $sizes['autres'] = 33;
        }

        return $sizes;
    }

    /**
     * Les 72 premiers octets, sans couper un caractère en deux.
     */
    private static function readByBcrypt(string $password): string
    {
        return mb_strcut($password, 0, self::BCRYPT_BYTES, 'UTF-8');
    }
}
