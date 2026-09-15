<?php

declare(strict_types=1);

/**
 * ===========================================================================
 *  Lanceur de migrations
 *
 *    php bin/migrate.php            applique ce qui est en attente
 *    php bin/migrate.php --status   liste l'état de chaque migration
 *
 *  Joué automatiquement au démarrage du conteneur PHP (cf. docker/php/
 *  entrypoint.sh), sauf si DB_AUTO_MIGRATE=false.
 *
 *  La logique vit dans App\Core\Migrator, que la suite de tests exerce à
 *  l'identique — ce fichier ne fait que le câblage et l'affichage.
 * ===========================================================================
 */

use App\Core\Migrator;

require __DIR__ . '/../src/autoload.php';

$sortie = static fn (string $ligne = '') => fwrite(STDOUT, $ligne . PHP_EOL);
$erreur = static fn (string $ligne) => fwrite(STDERR, $ligne . PHP_EOL);

try {
    $migrator = new Migrator();

    if (in_array('--status', $argv, true)) {
        $etat = $migrator->status();

        if ($etat === []) {
            $sortie('Aucune migration.');

            exit(0);
        }

        $sortie('');

        foreach ($etat as $ligne) {
            $marque = $ligne['applied_at'] === null ? '  ·  ' : '  ✓  ';
            $quand  = $ligne['applied_at'] === null
                ? 'en attente'
                : substr($ligne['applied_at'], 0, 19);

            $sortie($marque . str_pad($ligne['version'], 16) . str_pad($ligne['name'], 40) . $quand);
        }

        $sortie('');

        exit(0);
    }

    $faites = $migrator->run($sortie);

    if ($faites !== []) {
        $sortie(count($faites) . ' migration(s) appliquée(s).');
    }

    exit(0);
} catch (\Throwable $e) {
    $erreur('');
    $erreur('✗ ' . $e->getMessage());
    $erreur('');

    exit(1);
}
