<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\ActivityRepository;
use PDO;
use Throwable;

/**
 * Le travail du worker pour le module Disponibilité : appeler ce qui est échu,
 * consigner, ouvrir et fermer les pannes.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  UNE PANNE EST UNE TRANSITION, PAS UN ÉTAT                              │
 * │                                                                         │
 * │  Chaque appel est consigné, mais le journal ne reçoit que les           │
 * │  CHANGEMENTS : « Paiement est tombée », puis « Paiement répond de       │
 * │  nouveau ». Soixante lignes « toujours en panne » par heure noieraient  │
 * │  l'historique de l'équipe sous le bruit du worker.                      │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * La réservation des sondes échues et le report de leur échéance tiennent en
 * une instruction, sous « FOR UPDATE SKIP LOCKED » : deux workers lancés en
 * même temps se partagent le lot au lieu d'appeler deux fois la même adresse.
 */
final class ProbeRunner
{
    public function __construct(
        private readonly Prober $prober = new HttpProbe(),
        private readonly ActivityRepository $activity = new ActivityRepository(),
    ) {
    }

    /**
     * @return int nombre de sondes appelées
     */
    public function runDue(int $batch = 20): int
    {
        $claim = Database::connection()->prepare(
            'WITH due AS (
                 SELECT s.probe_id
                   FROM probe_states s
                   JOIN probes p ON p.id = s.probe_id
                  WHERE p.deleted_at IS NULL
                    AND NOT p.is_paused
                    AND s.next_check_at <= NOW()
                  ORDER BY s.next_check_at
                  LIMIT :batch
                    FOR UPDATE OF s SKIP LOCKED
             )
             UPDATE probe_states s
                SET next_check_at = NOW() + make_interval(secs => p.interval_seconds)
               FROM probes p, due
              WHERE s.probe_id = due.probe_id
                AND p.id = s.probe_id
          RETURNING p.id, p.organization_id, p.name, p.url, p.method, p.timeout_ms, p.slow_ms,
                    s.last_outcome::text AS last_outcome',
        );
        $claim->bindValue('batch', $batch, PDO::PARAM_INT);
        $claim->execute();

        /** @var list<array{id: string, organization_id: string, name: string, url: string, method: string, timeout_ms: int|string, slow_ms: int|string, last_outcome: ?string}> $probes */
        $probes = $claim->fetchAll();

        foreach ($probes as $probe) {
            try {
                $result = $this->prober->call(
                    (string) $probe['url'],
                    (string) $probe['method'],
                    (int) $probe['timeout_ms'],
                    (int) $probe['slow_ms'],
                );
            } catch (Throwable) {
                // Une sonde qui lève ne doit pas priver les autres de leur
                // appel ; le message reste générique, la trace n'a rien à
                // faire devant l'équipe.
                $result = ['outcome' => 'down', 'http_status' => null, 'response_ms' => null, 'error' => 'La sonde a échoué avant de pouvoir appeler.'];
            }

            $this->record($probe, $result);
        }

        return count($probes);
    }

    /**
     * Les relevés au-delà de la durée de conservation.
     *
     * @return int nombre de relevés supprimés
     */
    public function purge(int $retentionDays = 30): int
    {
        $statement = Database::connection()->prepare(
            'DELETE FROM probe_checks WHERE checked_at < NOW() - make_interval(days => :days)',
        );
        $statement->bindValue('days', $retentionDays, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }

    /**
     * @param array{id: string, organization_id: string, name: string, last_outcome: ?string} $probe
     * @param array{outcome: string, http_status: ?int, response_ms: ?int, error: ?string}     $result
     */
    private function record(array $probe, array $result): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $pdo->prepare(
                'INSERT INTO probe_checks (probe_id, organization_id, outcome, http_status, response_ms, error)
                 VALUES (:probe, :org, :outcome::probe_outcome, :status, :ms, :error)',
            )->execute([
                'probe'   => $probe['id'],
                'org'     => $probe['organization_id'],
                'outcome' => $result['outcome'],
                'status'  => $result['http_status'],
                'ms'      => $result['response_ms'],
                'error'   => $result['error'],
            ]);

            $pdo->prepare(
                'UPDATE probe_states
                    SET last_outcome     = :outcome::probe_outcome,
                        last_checked_at  = NOW(),
                        last_response_ms = :ms,
                        last_http_status = :status,
                        last_error       = :error
                  WHERE probe_id = :probe',
            )->execute([
                'probe'   => $probe['id'],
                'outcome' => $result['outcome'],
                'status'  => $result['http_status'],
                'ms'      => $result['response_ms'],
                'error'   => $result['error'],
            ]);

            $avant = $probe['last_outcome'];
            $apres = $result['outcome'];

            if ($apres === 'down' && $avant !== 'down') {
                // ON CONFLICT sur l'index partiel : si un autre worker a ouvert
                // la panne à l'instant, celle-ci n'en ouvre pas une seconde.
                $pdo->prepare(
                    'INSERT INTO probe_incidents (probe_id, organization_id, cause)
                     VALUES (:probe, :org, :cause)
                     ON CONFLICT (probe_id) WHERE ended_at IS NULL DO NOTHING',
                )->execute(['probe' => $probe['id'], 'org' => $probe['organization_id'], 'cause' => $result['error']]);

                $this->journal($probe, 'down', $result['error']);
            }

            if ($apres !== 'down' && $avant === 'down') {
                $pdo->prepare(
                    'UPDATE probe_incidents SET ended_at = NOW() WHERE probe_id = :probe AND ended_at IS NULL',
                )->execute(['probe' => $probe['id']]);

                $this->journal($probe, 'recovered', null);
            }

            $pdo->commit();
        } catch (Throwable $erreur) {
            $pdo->rollBack();

            throw $erreur;
        }
    }

    /**
     * Sans auteur : c'est le worker qui constate, personne ne l'a décidé.
     *
     * @param array{id: string, organization_id: string, name: string} $probe
     */
    private function journal(array $probe, string $action, ?string $cause): void
    {
        $this->activity->record(
            (string) $probe['organization_id'],
            null,
            null,
            'disponibilite',
            $action,
            (string) $probe['id'],
            null,
            (string) $probe['name'],
            $cause === null ? [] : ['cause' => [null, $cause]],
        );
    }
}
