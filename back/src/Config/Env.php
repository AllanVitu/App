<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/**
 * Accès typé aux variables d'environnement.
 *
 * Les valeurs sont injectées par docker-compose : aucun secret n'est écrit
 * dans le code source. Les lectures sont mises en cache car getenv() est
 * appelé à chaque requête pour la connexion PDO et la vérification JWT.
 */
final class Env
{
    /** @var array<string, string|false> */
    private static array $cache = [];

    /**
     * Retourne la valeur brute, ou $default si la variable est absente/vide.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        if (!array_key_exists($key, self::$cache)) {
            self::$cache[$key] = getenv($key);
        }

        $value = self::$cache[$key];

        return ($value === false || $value === '') ? $default : $value;
    }

    /**
     * Idem, mais lève une exception si la variable n'est pas définie.
     * À utiliser pour les secrets dont l'absence doit interrompre le démarrage.
     */
    public static function mustGet(string $key): string
    {
        $value = self::get($key);

        if ($value === null) {
            throw new RuntimeException("Variable d'environnement manquante : {$key}");
        }

        return $value;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);

        return ($value !== null && is_numeric($value)) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    public static function isDebug(): bool
    {
        return self::bool('APP_DEBUG', false);
    }

    public static function isProduction(): bool
    {
        return self::get('APP_ENV', 'development') === 'production';
    }
}
