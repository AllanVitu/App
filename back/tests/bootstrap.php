<?php

declare(strict_types=1);

/**
 * ---------------------------------------------------------------------------
 * Amorçage de la suite de tests.
 *
 * Crée une base DÉDIÉE (« <base>_test ») et y applique le schéma
 * d'initialisation. Les tests d'intégration travaillent donc sur des données
 * réelles, dans PostgreSQL, sans jamais toucher la base de développement.
 *
 * La base est recréée à chaque exécution : aucun test ne peut hériter d'un
 * état laissé par une exécution précédente.
 * ---------------------------------------------------------------------------
 */

require __DIR__ . '/../vendor/autoload.php';

$host     = getenv('DB_HOST') ?: 'db';
$port     = getenv('DB_PORT') ?: '5432';
$user     = getenv('DB_USER') ?: 'saas_user';
$password = getenv('DB_PASSWORD') ?: '';
$source   = getenv('DB_NAME') ?: 'saas_db';
$database = $source . '_test';

// Ces valeurs doivent être posées AVANT le premier appel à App\Config\Env,
// qui met ses lectures en cache pour toute la durée du processus.
putenv("DB_NAME={$database}");
putenv('APP_ENV=testing');
putenv('APP_DEBUG=true');
putenv('JWT_ISSUER=saas-api-test');
putenv('JWT_ACCESS_TTL=900');
putenv('JWT_REFRESH_TTL=1209600');
putenv('CORS_ALLOWED_ORIGIN=http://localhost:5173');
putenv('APP_FRONTEND_URL=http://localhost:5173');

// Secret de test uniquement : le vrai secret ne doit jamais transiter ici.
putenv('JWT_SECRET=' . str_repeat('t3st-s3cr3t-', 6));

// Aucun envoi réel : le port pointe volontairement dans le vide, et
// AccountMailer absorbe puis journalise l'échec sans casser le parcours.
putenv('MAIL_HOST=127.0.0.1');
putenv('MAIL_PORT=1');
putenv('MAIL_FROM_ADDRESS=no-reply@test.local');
putenv('MAIL_FROM_NAME=Tests');

// Les journaux applicatifs (échecs SMTP attendus, traces d'exception) sont
// détournés vers un fichier : ils restent consultables sans noyer la sortie
// de PHPUnit, où seuls les résultats de test doivent apparaître.
ini_set('error_log', __DIR__ . '/../.phpunit.cache/tests.log');
@mkdir(__DIR__ . '/../.phpunit.cache', 0o777, true);

$dsn = "pgsql:host={$host};port={$port};dbname={$source}";

try {
    $admin = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    fwrite(STDERR, "\nPostgreSQL injoignable ({$host}:{$port}) : " . $e->getMessage() . "\n");
    fwrite(STDERR, "Lancez les tests depuis le conteneur : docker compose exec php composer test\n\n");

    exit(1);
}

// Une base ne peut être supprimée tant qu'une session y est connectée.
$admin->exec(
    'SELECT pg_terminate_backend(pid) FROM pg_stat_activity
      WHERE datname = ' . $admin->quote($database) . ' AND pid <> pg_backend_pid()',
);
$admin->exec('DROP DATABASE IF EXISTS "' . $database . '"');
$admin->exec('CREATE DATABASE "' . $database . '"');

$pdo = new PDO(
    "pgsql:host={$host};port={$port};dbname={$database}",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

// Le schéma appliqué est CELUI DE PRODUCTION : un test qui tournerait sur un
// schéma reconstruit à la main ne garantirait rien sur le schéma déployé.
// 03_seed.sh est ignoré : c'est un script shell, et son jeu de démonstration
// n'a pas sa place dans des tests qui créent leurs propres données.
foreach (['01_schema.sql', '02_seed.sql', '04_auth_tokens.sql'] as $file) {
    $sql = file_get_contents(__DIR__ . '/../database/init/' . $file);

    if ($sql === false) {
        fwrite(STDERR, "Fichier de schéma introuvable : {$file}\n");

        exit(1);
    }

    $pdo->exec($sql);
}
