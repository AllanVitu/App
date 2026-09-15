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

// Plafonds d'invitations abaissés (20 par heure et 50 par jour en production) :
// le même comportement, vérifié sans cinquante requêtes par test.
putenv('INVITATIONS_PER_HOUR=3');
putenv('INVITATIONS_PER_DAY=5');

// Les fichiers téléversés vont dans un dossier jetable, vidé à chaque
// exécution : un test n'hérite pas des fichiers d'un autre, et rien n'est
// jamais écrit dans le volume de développement.
$storage = sys_get_temp_dir() . '/saas-tests-storage';
putenv('STORAGE_PATH=' . $storage);

if (is_dir($storage)) {
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    /** @var SplFileInfo $entry */
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
}

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
//
// Les fichiers sont DÉCOUVERTS, pas énumérés : une liste codée en dur oublie
// silencieusement la migration suivante, et les tests échouent alors sur une
// colonne inconnue plutôt que sur ce qu'ils vérifient. (C'est exactement ce
// qui s'est produit lors de l'ajout de 05_terms.sql.)
//
// Seuls les .sql sont pris : 03_seed.sh est un script shell, et son jeu de
// démonstration n'a pas sa place dans des tests qui créent leurs données.
$schemaFiles = glob(__DIR__ . '/../database/init/*.sql');

if ($schemaFiles === false || $schemaFiles === []) {
    fwrite(STDERR, "Aucun fichier de schéma trouvé dans database/init/\n");

    exit(1);
}

// L'ordre alphabétique est celui qu'applique aussi l'entrypoint PostgreSQL :
// c'est le préfixe numérique des fichiers qui porte la séquence.
sort($schemaFiles);

foreach ($schemaFiles as $file) {
    $pdo->exec((string) file_get_contents($file));
}

/**
 * ---------------------------------------------------------------------------
 * PUIS LES MIGRATIONS, exactement comme au démarrage du conteneur PHP.
 *
 * « init/ » est la ligne de base ; « migrations/ » porte tout ce qui vient
 * après. Reconstruire la base de test à partir de la seule ligne de base
 * revenait donc à tester sur un schéma d'avant — et c'est ce qui s'est
 * produit : la première migration écrite pour ce projet a fait échouer
 * quatorze tests sur « relation "jobs" does not exist ».
 *
 * Le migrateur est appelé ICI, et pas seulement dans l'entrypoint Docker,
 * pour que la base de test suive le même chemin que la production : ligne de
 * base, puis migrations, dans cet ordre.
 * ---------------------------------------------------------------------------
 */
try {
    (new App\Core\Migrator())->run();
} catch (Throwable $e) {
    fwrite(STDERR, "\nMigrations impossibles sur la base de test : " . $e->getMessage() . "\n\n");

    exit(1);
}
