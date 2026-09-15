<?php

declare(strict_types=1);

/**
 * ---------------------------------------------------------------------------
 * Autoloader PSR-4
 *
 * L'API ne dépend d'aucun paquet tiers : elle fonctionne sans `composer install`.
 * Si un vendor/ existe (ajout futur de dépendances), il prend le relais.
 * ---------------------------------------------------------------------------
 */

$composerAutoload = __DIR__ . '/../vendor/autoload.php';

if (is_file($composerAutoload)) {
    require $composerAutoload;

    return;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    // App\Core\Router  ->  src/Core/Router.php
    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = __DIR__ . DIRECTORY_SEPARATOR . $relativePath . '.php';

    if (is_file($file)) {
        require $file;
    }
});
