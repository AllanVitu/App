<?php

declare(strict_types=1);

/**
 * ===========================================================================
 *  Installation et mise à jour de la base locale
 *
 *    php installer.php <racine de l'API>
 *
 *  Lancé par l'application à chaque démarrage, avant que l'API ne serve quoi
 *  que ce soit.
 *
 *  ---------------------------------------------------------------------------
 *  LA PREMIÈRE FOIS : UNE BASE COMPLÈTE, OU AUCUNE
 *
 *  La base est construite sous un nom provisoire — ligne de base
 *  (database/init/*.sql), puis migrations, dans l'ordre que suivent le
 *  conteneur PostgreSQL et la suite de tests — et ne prend son nom définitif
 *  qu'une fois complète. Une installation interrompue (coupure, fermeture de
 *  la fenêtre) ne laisse donc jamais, sous le nom que l'API ouvrira, une base à
 *  moitié construite : le lancement suivant efface la base provisoire et
 *  reprend de zéro.
 *
 *  ---------------------------------------------------------------------------
 *  ENSUITE : LES MIGRATIONS EN ATTENTE
 *
 *  Exactement ce que fait bin/migrate.php au démarrage du conteneur PHP. Une
 *  mise à jour de l'application apporte ses migrations ; elles passent ici,
 *  au premier lancement qui suit.
 *
 *  Chaque étape s'annonce sur une ligne JSON, que l'écran de démarrage
 *  affiche. Les identifiants viennent de l'environnement, posé par
 *  l'application depuis son coffre.
 * ===========================================================================
 */

$racine = $argv[1] ?? '';

if (!is_file($racine . '/src/autoload.php')) {
    fwrite(STDERR, "Racine de l'API introuvable.\n");

    exit(2);
}

$annoncer = static function (string $etape, string $message): void {
    fwrite(STDOUT, json_encode(['etape' => $etape, 'message' => $message], JSON_UNESCAPED_UNICODE) . PHP_EOL);
};

// Les noms de bases sont écrits dans des requêtes DDL, où aucun paramètre lié
// n'est possible : ils sont donc contraints, avant tout usage.
$nom = (string) getenv('DB_NAME');

if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', $nom) !== 1) {
    fwrite(STDERR, "Nom de base invalide.\n");

    exit(2);
}

$provisoire = $nom . '_installation';

$connecter = static fn (string $base): PDO => new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', (string) getenv('DB_HOST'), (string) getenv('DB_PORT'), $base),
    (string) getenv('DB_USER'),
    (string) getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

try {
    $admin = $connecter('postgres');

    $existe = static function (string $base) use ($admin): bool {
        $requete = $admin->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $requete->execute([$base]);

        return $requete->fetchColumn() !== false;
    };

    if ($existe($nom)) {
        require $racine . '/src/autoload.php';

        $faites = (new App\Core\Migrator())->run();

        $annoncer('pret', $faites === []
            ? 'Base de données à jour'
            : count($faites) . ' mise(s) à jour de la base appliquée(s)');

        exit(0);
    }

    if ($existe($provisoire)) {
        $annoncer('base', 'Reprise d’une installation interrompue');
        $admin->exec('DROP DATABASE "' . $provisoire . '" WITH (FORCE)');
    }

    $annoncer('base', 'Création de la base de données');
    $admin->exec('CREATE DATABASE "' . $provisoire . '" TEMPLATE template0');

    $fichiers = glob($racine . '/database/init/*.sql') ?: [];
    sort($fichiers);

    if ($fichiers === []) {
        throw new RuntimeException('Aucun fichier de schéma dans database/init/.');
    }

    $base = $connecter($provisoire);

    foreach ($fichiers as $fichier) {
        $annoncer('schema', 'Schéma : ' . preg_replace('/^\d+_/', '', basename($fichier, '.sql')));
        $base->exec((string) file_get_contents($fichier));
    }

    $base = null;

    // Le migrateur ouvre sa propre connexion, d'après l'environnement : il
    // doit viser la base provisoire. Posé AVANT le premier appel à Env, qui
    // met ses lectures en cache.
    putenv('DB_NAME=' . $provisoire);

    require $racine . '/src/autoload.php';

    $annoncer('migrations', 'Mises à jour du schéma');
    (new App\Core\Migrator())->run();

    // Un renommage exige la base libre : la connexion du migrateur y est
    // encore ouverte, elle est close depuis la connexion d'administration.
    $admin->exec(
        'SELECT pg_terminate_backend(pid) FROM pg_stat_activity
          WHERE datname = ' . $admin->quote($provisoire) . ' AND pid <> pg_backend_pid()',
    );
    $admin->exec('ALTER DATABASE "' . $provisoire . '" RENAME TO "' . $nom . '"');

    $annoncer('pret', 'Base de données prête');
} catch (Throwable $e) {
    // Jamais le DSN ni le mot de passe : le message de PDO suffit, et le
    // journal de PostgreSQL dit le reste.
    fwrite(STDERR, $e::class . ' : ' . $e->getMessage() . PHP_EOL);

    exit(1);
}
