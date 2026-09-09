<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * File de tâches exécutées hors de la requête HTTP.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  POURQUOI PAS DE BIBLIOTHÈQUE, ET POURQUOI PAS REDIS                    │
 * │                                                                         │
 * │  L'API n'a AUCUNE dépendance tierce — autoload maison, JWT signé à la   │
 * │  main, client SMTP écrit sur le protocole. Ajouter un courtier de       │
 * │  messages ajouterait un service à déployer, à surveiller et à           │
 * │  sauvegarder, pour une charge que PostgreSQL absorbe sans y penser.     │
 * │                                                                         │
 * │  « SELECT … FOR UPDATE SKIP LOCKED » est fait pour ça : plusieurs       │
 * │  workers puisent dans la même table sans jamais se prendre la même      │
 * │  tâche, et sans verrou d'attente. C'est la construction que les files   │
 * │  sur PostgreSQL emploient toutes.                                       │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * LE CONTRAT EST « AU MOINS UNE FOIS », jamais « exactement une fois ». Un
 * worker tué entre l'exécution et l'acquittement laisse une tâche réservée,
 * qui sera reprise après expiration de la réservation. Les gestionnaires
 * doivent donc supporter d'être rejoués — envoyer deux fois un e-mail est
 * ennuyeux, ne jamais l'envoyer est pire.
 */
final class Queue
{
    /**
     * Au-delà, une tâche réservée est considérée abandonnée.
     *
     * Un worker tué en plein travail ne rend pas sa réservation. Sans reprise,
     * la tâche resterait bloquée pour toujours — et c'est exactement le genre
     * de panne qu'on ne remarque qu'en cherchant autre chose.
     */
    private const RESERVATION_TIMEOUT = 300;

    /** Recul entre deux essais : 1 min, 5 min, 25 min. */
    private const BACKOFF_BASE = 60;

    /**
     * Met une tâche en file.
     *
     * @param  array<string, mixed> $payload
     * @param  int                  $delay   secondes avant de la rendre exécutable
     * @return string               identifiant de la tâche
     */
    public static function push(string $type, array $payload = [], int $delay = 0): string
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO jobs (type, payload, available_at, max_attempts)
             VALUES (:type, :payload, NOW() + (:delay || \' seconds\')::interval, :max)
             RETURNING id',
        );

        $statement->execute([
            'type'    => $type,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'delay'   => (string) max(0, $delay),
            'max'     => 3,
        ]);

        return (string) $statement->fetchColumn();
    }

    /**
     * Réserve la prochaine tâche exécutable, ou null si la file est vide.
     *
     * « SKIP LOCKED » est le cœur du mécanisme : deux workers qui interrogent
     * la table au même instant ne s'attendent pas — le second saute la ligne
     * que le premier tient et prend la suivante.
     *
     * @return array{id: string, type: string, payload: array<string, mixed>, attempts: int, max_attempts: int}|null
     */
    public static function reserve(): ?array
    {
        return Database::transaction(static function (): ?array {
            $pdo = Database::connection();

            $statement = $pdo->prepare(
                'SELECT id, type, payload, attempts, max_attempts
                   FROM jobs
                  WHERE failed_at IS NULL
                    AND available_at <= NOW()
                    AND (
                          reserved_at IS NULL
                          OR reserved_at < NOW() - (:timeout || \' seconds\')::interval
                        )
                  ORDER BY available_at
                  LIMIT 1
                    FOR UPDATE SKIP LOCKED',
            );

            $statement->execute(['timeout' => (string) self::RESERVATION_TIMEOUT]);
            $row = $statement->fetch();

            if ($row === false) {
                return null;
            }

            // Le compteur d'essais monte À LA RÉSERVATION, pas à l'échec : un
            // worker tué avant d'avoir pu signaler quoi que ce soit doit tout
            // de même consommer un essai, sinon une tâche qui fait tomber le
            // worker le fait tomber indéfiniment.
            $pdo->prepare(
                'UPDATE jobs SET reserved_at = NOW(), attempts = attempts + 1 WHERE id = :id',
            )->execute(['id' => $row['id']]);

            return [
                'id'           => (string) $row['id'],
                'type'         => (string) $row['type'],
                'payload'      => Database::toArray($row['payload']),
                'attempts'     => (int) $row['attempts'] + 1,
                'max_attempts' => (int) $row['max_attempts'],
            ];
        });
    }

    /** La tâche a abouti : elle quitte la table. */
    public static function complete(string $id): void
    {
        Database::connection()
            ->prepare('DELETE FROM jobs WHERE id = :id')
            ->execute(['id' => $id]);
    }

    /**
     * La tâche a échoué : on la reprogramme, ou on l'abandonne.
     *
     * Une tâche abandonnée RESTE en table, avec sa dernière erreur. L'effacer
     * ferait disparaître la panne avec elle.
     */
    public static function fail(string $id, int $attempts, int $maxAttempts, string $error): void
    {
        // Tronquée : une trace d'exception peut peser des kilo-octets, et
        // c'est la première ligne qui dit ce qui s'est passé.
        $error = mb_substr($error, 0, 2000);

        if ($attempts >= $maxAttempts) {
            Database::connection()->prepare(
                'UPDATE jobs SET failed_at = NOW(), reserved_at = NULL, last_error = :error
                  WHERE id = :id',
            )->execute(['id' => $id, 'error' => $error]);

            return;
        }

        // Recul exponentiel : une panne passagère se résout souvent seule, et
        // réessayer immédiatement ne ferait que la constater plus vite.
        $delay = self::BACKOFF_BASE * (5 ** ($attempts - 1));

        Database::connection()->prepare(
            'UPDATE jobs
                SET reserved_at = NULL,
                    last_error  = :error,
                    available_at = NOW() + (:delay || \' seconds\')::interval
              WHERE id = :id',
        )->execute(['id' => $id, 'error' => $error, 'delay' => (string) $delay]);
    }

    /**
     * Met en file les travaux périodiques arrivés à échéance.
     *
     * La mise à jour de « last_run_at » est CONDITIONNÉE À L'ÉCHÉANCE dans la
     * même requête : deux planificateurs qui tournent en parallèle ne peuvent
     * pas déclencher deux fois le même travail, puisque le second ne trouvera
     * plus de ligne à mettre à jour.
     *
     * @return list<string> noms des travaux déclenchés
     */
    public static function schedule(): array
    {
        $statement = Database::connection()->prepare(
            'UPDATE scheduled_tasks
                SET last_run_at = NOW()
              WHERE last_run_at IS NULL
                 OR last_run_at < NOW() - (interval_seconds || \' seconds\')::interval
          RETURNING name, type, payload',
        );

        $statement->execute();
        $declenches = [];

        foreach ($statement->fetchAll() as $tache) {
            self::push((string) $tache['type'], Database::toArray($tache['payload']));
            $declenches[] = (string) $tache['name'];
        }

        return $declenches;
    }

    /**
     * @return array{en_attente: int, reservees: int, echouees: int}
     */
    public static function stats(): array
    {
        $row = Database::connection()->query(
            'SELECT
                 count(*) FILTER (WHERE failed_at IS NULL AND reserved_at IS NULL) AS en_attente,
                 count(*) FILTER (WHERE failed_at IS NULL AND reserved_at IS NOT NULL) AS reservees,
                 count(*) FILTER (WHERE failed_at IS NOT NULL) AS echouees
               FROM jobs',
        )->fetch();

        return [
            'en_attente' => (int) $row['en_attente'],
            'reservees'  => (int) $row['reservees'],
            'echouees'   => (int) $row['echouees'],
        ];
    }
}
