<?php

declare(strict_types=1);

/**
 * ===========================================================================
 *  Worker : exécute les tâches de fond, et déclenche les travaux périodiques
 *
 *    php bin/worker.php              tourne indéfiniment
 *    php bin/worker.php --once       vide la file puis s'arrête
 *
 *  ---------------------------------------------------------------------------
 *  UN SEUL PROCESSUS POUR LES DEUX RÔLES
 *
 *  Le planificateur (« quels travaux périodiques sont dus ? ») et le worker
 *  (« quelle tâche exécuter ? ») vivent ensemble. Séparer les deux
 *  demanderait un second conteneur, une seconde image, un second jeu de
 *  variables — pour une boucle de dix lignes.
 *
 *  Plusieurs workers peuvent tourner en parallèle sans se marcher dessus :
 *  la réservation passe par « FOR UPDATE SKIP LOCKED », et le déclenchement
 *  périodique par une mise à jour conditionnée à l'échéance.
 *
 *  ---------------------------------------------------------------------------
 *  ARRÊT PROPRE
 *
 *  Docker envoie SIGTERM puis attend. Le worker termine la tâche en cours
 *  avant de sortir : l'interrompre en plein travail la laisserait réservée,
 *  donc invisible pendant cinq minutes.
 * ===========================================================================
 */

use App\Config\Env;
use App\Services\JobHandlers;
use App\Services\Queue;

require __DIR__ . '/../src/autoload.php';

$sortie = static fn (string $ligne) => fwrite(
    STDOUT,
    '[' . date('H:i:s') . '] ' . $ligne . PHP_EOL,
);

$unique = in_array('--once', $argv, true);
$arret  = false;

// Sous Windows, aucun signal à intercepter : l'application de bureau demande
// l'arrêt en déposant ce fichier. Même effet que SIGTERM — la tâche en cours
// se termine, puis le worker sort.
$fichierArret = Env::get('WORKER_FICHIER_ARRET');

// pcntl n'est pas toujours compilé : sans lui, l'arrêt reste brutal, ce qui
// est acceptable — la tâche interrompue sera reprise après réservation
// expirée. On ne se prive pas de l'arrêt propre là où il est disponible.
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);

    foreach ([SIGTERM, SIGINT] as $signal) {
        pcntl_signal($signal, static function () use (&$arret, $sortie): void {
            $sortie('Arrêt demandé — la tâche en cours va être terminée.');
            $arret = true;
        });
    }
}

$sortie($unique ? 'Worker : passage unique.' : 'Worker démarré.');

/** Intervalle de sommeil quand la file est vide, en microsecondes. */
$repos = 1_000_000;

/** Dernier passage du planificateur. */
$dernierPlan = 0;

while (!$arret) {
    if ($fichierArret !== null && is_file($fichierArret)) {
        $sortie('Arrêt demandé.');

        break;
    }

    // Le planificateur ne tourne qu'une fois par seconde au plus : c'est une
    // écriture, inutile de la répéter à chaque tour de boucle.
    if (time() - $dernierPlan >= 1) {
        $dernierPlan = time();

        try {
            foreach (Queue::schedule() as $nom) {
                $sortie("Travail périodique mis en file : {$nom}");
            }
        } catch (\Throwable $e) {
            $sortie('Planificateur en échec : ' . $e->getMessage());
        }
    }

    try {
        $tache = Queue::reserve();
    } catch (\Throwable $e) {
        // Base injoignable : on patiente plutôt que de tourner à vide en
        // boucle serrée, ce qui noierait les journaux et la base à son retour.
        $sortie('File injoignable : ' . $e->getMessage());
        usleep($repos * 5);

        continue;
    }

    if ($tache === null) {
        if ($unique) {
            break;
        }

        usleep($repos);

        continue;
    }

    $sortie("→ {$tache['type']} ({$tache['attempts']}/{$tache['max_attempts']})");

    try {
        JobHandlers::handle($tache['type'], $tache['payload']);
        Queue::complete($tache['id']);

        $sortie("   terminée");
    } catch (\Throwable $e) {
        // Le type part avec l'échec : c'est lui qui nomme la panne dans la
        // supervision de l'instance, si cet essai était le dernier.
        Queue::fail($tache['id'], $tache['attempts'], $tache['max_attempts'], $e->getMessage(), $tache['type']);

        $abandonnee = $tache['attempts'] >= $tache['max_attempts'];
        $sortie('   ' . ($abandonnee ? 'ABANDONNÉE' : 'reprogrammée') . ' : ' . $e->getMessage());
    }
}

$sortie('Worker arrêté.');
