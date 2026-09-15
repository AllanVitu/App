<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\Prober;
use App\Services\ProbeRunner;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Le worker du module Disponibilité, avec une sonde de laboratoire qui répond
 * ce qu'on lui dicte : aucun appel réseau, et des pannes à la demande.
 */
final class ProbeRunnerTest extends ApiTestCase
{
    #[Test]
    public function une_sonde_echue_est_appelee_une_fois_et_son_echeance_reportee(): void
    {
        $org   = $this->register('runner@test.local')['org'];
        $sonde = $this->sonde($org, 'Accueil');

        $labo   = $this->labo(['up']);
        $runner = new ProbeRunner($labo);

        $this->assertSame(1, $runner->runDue());
        $this->assertSame(1, $labo->appels);

        // L'échéance est repoussée d'un intervalle : un second passage dans
        // la même minute n'appelle rien.
        $this->assertSame(0, $runner->runDue());
        $this->assertSame(1, $labo->appels);

        $etat = $this->etat($sonde);
        $this->assertSame('up', $etat['last_outcome']);
        $this->assertSame(42, (int) $etat['last_response_ms']);
        $this->assertSame(1, $this->compter('probe_checks', $sonde));
    }

    /**
     * Deux échecs de suite prolongent la même panne ; le journal n'en retient
     * que les deux transitions.
     */
    #[Test]
    public function une_panne_s_ouvre_une_fois_et_se_ferme_au_retour(): void
    {
        $org   = $this->register('panne@test.local')['org'];
        $sonde = $this->sonde($org, 'Paiement');

        $runner = new ProbeRunner($this->labo(['down', 'down', 'up']));

        foreach ([1, 2, 3] as $passage) {
            $runner->runDue();
            $this->rendreEchue($sonde);
        }

        $pannes = Database::connection()->query(
            "SELECT started_at, ended_at, cause FROM probe_incidents WHERE probe_id = '{$sonde}'",
        )->fetchAll();

        $this->assertCount(1, $pannes);
        $this->assertNotNull($pannes[0]['ended_at']);
        $this->assertSame('Connexion refusée par le serveur.', $pannes[0]['cause']);

        $actions = Database::connection()->query(
            "SELECT action, actor_id FROM activity WHERE subject_id = '{$sonde}' ORDER BY id",
        )->fetchAll();

        $this->assertSame(['down', 'recovered'], array_column($actions, 'action'));
        $this->assertNull($actions[0]['actor_id'], 'le worker constate, personne n\'a décidé');
        $this->assertSame(3, $this->compter('probe_checks', $sonde));
    }

    #[Test]
    public function une_sonde_en_pause_ou_supprimee_n_est_pas_appelee(): void
    {
        $org     = $this->register('pause@test.local')['org'];
        $pause   = $this->sonde($org, 'En pause');
        $efface  = $this->sonde($org, 'Supprimée');

        Database::connection()->exec("UPDATE probes SET is_paused = TRUE WHERE id = '{$pause}'");
        Database::connection()->exec("UPDATE probes SET deleted_at = NOW() WHERE id = '{$efface}'");

        $labo = $this->labo(['up']);

        $this->assertSame(0, (new ProbeRunner($labo))->runDue());
        $this->assertSame(0, $labo->appels);
    }

    /**
     * Une exception dans une sonde ne prive pas les autres de leur appel.
     */
    #[Test]
    public function une_sonde_qui_leve_n_arrete_pas_le_lot(): void
    {
        $org = $this->register('lot@test.local')['org'];
        $this->sonde($org, 'A');
        $this->sonde($org, 'B');

        $labo = new class () implements Prober {
            public int $appels = 0;

            public function call(string $url, string $method, int $timeoutMs, int $slowMs): array
            {
                $this->appels++;

                if ($this->appels === 1) {
                    throw new \RuntimeException('panne de laboratoire');
                }

                return ['outcome' => 'up', 'http_status' => 200, 'response_ms' => 12, 'error' => null];
            }
        };

        $this->assertSame(2, (new ProbeRunner($labo))->runDue());
        $this->assertSame(2, $labo->appels);

        $issues = Database::connection()->query(
            'SELECT last_outcome::text FROM probe_states ORDER BY 1',
        )->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertSame(['down', 'up'], $issues);
    }

    #[Test]
    public function la_purge_ne_retire_que_les_releves_de_plus_de_trente_jours(): void
    {
        $org   = $this->register('purge@test.local')['org'];
        $sonde = $this->sonde($org, 'Ancienne');

        $runner = new ProbeRunner($this->labo(['up', 'up']));
        $runner->runDue();
        $this->rendreEchue($sonde);
        $runner->runDue();

        Database::connection()->exec(
            "UPDATE probe_checks SET checked_at = NOW() - INTERVAL '31 days'
              WHERE id = (SELECT MIN(id) FROM probe_checks WHERE probe_id = '{$sonde}')",
        );

        $this->assertSame(1, $runner->purge(30));
        $this->assertSame(1, $this->compter('probe_checks', $sonde));
    }

    /**
     * @param list<'up'|'slow'|'down'> $script
     *
     * @return Prober&object{appels: int}
     */
    private function labo(array $script): Prober
    {
        return new class ($script) implements Prober {
            public int $appels = 0;

            /** @param list<'up'|'slow'|'down'> $script */
            public function __construct(private readonly array $script)
            {
            }

            public function call(string $url, string $method, int $timeoutMs, int $slowMs): array
            {
                $issue = $this->script[min($this->appels, count($this->script) - 1)];
                $this->appels++;

                return $issue === 'down'
                    ? ['outcome' => 'down', 'http_status' => null, 'response_ms' => null, 'error' => 'Connexion refusée par le serveur.']
                    : ['outcome' => $issue, 'http_status' => 200, 'response_ms' => 42, 'error' => null];
            }
        };
    }

    private function sonde(string $org, string $nom): string
    {
        $statement = Database::connection()->prepare(
            "INSERT INTO probes (organization_id, name, url) VALUES (:org, :nom, 'https://relais.example/sante') RETURNING id",
        );
        $statement->execute(['org' => $org, 'nom' => $nom]);

        return (string) $statement->fetchColumn();
    }

    private function rendreEchue(string $sonde): void
    {
        Database::connection()->exec("UPDATE probe_states SET next_check_at = NOW() WHERE probe_id = '{$sonde}'");
    }

    /**
     * @return array<string, mixed>
     */
    private function etat(string $sonde): array
    {
        return Database::connection()->query("SELECT * FROM probe_states WHERE probe_id = '{$sonde}'")->fetch();
    }

    private function compter(string $table, string $sonde): int
    {
        return (int) Database::connection()->query("SELECT COUNT(*) FROM {$table} WHERE probe_id = '{$sonde}'")->fetchColumn();
    }
}
