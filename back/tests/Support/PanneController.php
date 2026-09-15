<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Database;
use App\Core\Request;

/**
 * Des pannes VRAIES, pour éprouver le chemin qui les range.
 *
 * Aucune route de l'application ne doit échouer à la demande : celles-ci
 * n'existent que dans la table de routage des tests (routes-pannes.php).
 * Simuler le noyau à la place ne prouverait rien du chemin réellement
 * emprunté par une exception que personne n'attrape.
 */
final class PanneController
{
    public function exception(Request $request): void
    {
        // Le message recopie ce qu'une vraie exception recopie parfois — une
        // adresse, un jeton. C'est exactement ce qui doit disparaître avant
        // d'atteindre la base.
        throw new \RuntimeException(sprintf(
            'Échec pour alice@exemple.fr avec le jeton %s',
            str_repeat('ab', 20),
        ));
    }

    public function transactionAvortee(Request $request): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $pdo->exec('SELECT * FROM table_qui_n_existe_pas');
        } catch (\PDOException) {
            // La transaction est maintenant AVORTÉE : PostgreSQL refusera toute
            // instruction jusqu'au ROLLBACK. C'est l'état que le signalement
            // doit savoir surmonter.
        }

        throw new \LogicException('Panne au milieu d\'une transaction');
    }

    public function defautDeCode(Request $request): void
    {
        throw new \TypeError('Argument #1 ($id) doit être de type string, null donné');
    }
}
