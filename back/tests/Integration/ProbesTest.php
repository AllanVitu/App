<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\Prober;
use App\Services\ProbeRunner;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Le module Disponibilité, par son API.
 */
final class ProbesTest extends ApiTestCase
{
    private const SONDE = [
        'name'             => 'Page de paiement',
        'url'              => 'https://boutique.relais-demo.fr/paiement',
        'interval_seconds' => 60,
        'timeout_ms'       => 4000,
        'slow_ms'          => 800,
    ];

    #[Test]
    public function un_administrateur_cree_une_sonde_et_tout_membre_la_lit(): void
    {
        $hote = $this->register('hote@test.local');

        $creee = $this->call('POST', '/api/probes', self::SONDE, $this->bearer($hote['token']));

        $this->assertSame(201, $creee['status'], json_encode($creee['body']) ?: '');
        $this->assertSame('GET', $creee['body']['data']['method']);
        $this->assertFalse($creee['body']['data']['is_paused']);
        $this->assertNull($creee['body']['data']['last_outcome'], 'jamais appelée : aucun état inventé');
        $this->assertSame([], $creee['body']['data']['recent']);

        $liste = $this->call('GET', '/api/probes', headers: $this->bearer($hote['token']));

        $this->assertSame(200, $liste['status']);
        $this->assertCount(1, $liste['body']['data']);
        $this->assertSame(25, $liste['body']['meta']['quota']);

        // Le journal nomme l'hôte, pas l'adresse entière : un chemin peut
        // porter un jeton.
        $journal = Database::connection()->query(
            "SELECT action, subject_ref FROM activity WHERE module = 'disponibilite'",
        )->fetch();

        $this->assertSame(['action' => 'created', 'subject_ref' => 'boutique.relais-demo.fr'], $journal);
    }

    /**
     * Une sonde fait émettre des requêtes par le serveur : la régler est un
     * pouvoir d'administrateur, pas une préférence de membre.
     */
    #[Test]
    public function un_membre_lit_les_sondes_mais_ne_peut_rien_regler(): void
    {
        $hote   = $this->register('hote@test.local');
        $sonde  = $this->creer($hote);
        $membre = $this->membre($hote, 'membre@test.local');
        $entete = $this->bearer($membre['token']);

        $this->assertSame(200, $this->call('GET', '/api/probes', headers: $entete)['status']);
        $this->assertSame(200, $this->call('GET', "/api/probes/{$sonde}", headers: $entete)['status']);

        $this->assertSame(403, $this->call('POST', '/api/probes', self::SONDE, $entete)['status']);
        $this->assertSame(403, $this->call('PUT', "/api/probes/{$sonde}", ['name' => 'Autre'], $entete)['status']);
        $this->assertSame(403, $this->call('DELETE', "/api/probes/{$sonde}", headers: $entete)['status']);
        $this->assertSame(403, $this->call('POST', "/api/probes/{$sonde}/check", headers: $entete)['status']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function adressesInterdites(): iterable
    {
        yield 'métadonnées du cloud' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'boucle locale'        => ['http://127.0.0.1:9000/'];
        yield 'localhost'            => ['http://localhost:8080/api/health'];
        yield 'fichier local'        => ['file:///etc/passwd'];
        yield 'identifiants'         => ['https://admin:secret@boutique.relais-demo.fr/'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('adressesInterdites')]
    public function une_adresse_interne_est_refusee_des_l_enregistrement(string $url): void
    {
        $hote = $this->register('ssrf@test.local');

        $refus = $this->call('POST', '/api/probes', ['url' => $url] + self::SONDE, $this->bearer($hote['token']));

        $this->assertSame(422, $refus['status']);
        $this->assertArrayHasKey('url', $refus['body']['errors']);
        $this->assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM probes')->fetchColumn());
    }

    #[Test]
    public function les_reglages_sont_bornes(): void
    {
        $entete = $this->bearer($this->register('bornes@test.local')['token']);

        $lent = $this->call('POST', '/api/probes', ['slow_ms' => 5000, 'timeout_ms' => 4000] + self::SONDE, $entete);
        $this->assertSame(422, $lent['status']);
        $this->assertArrayHasKey('slow_ms', $lent['body']['errors']);

        $rythme = $this->call('POST', '/api/probes', ['interval_seconds' => 120] + self::SONDE, $entete);
        $this->assertSame(422, $rythme['status']);
        $this->assertArrayHasKey('interval_seconds', $rythme['body']['errors']);

        $methode = $this->call('POST', '/api/probes', ['method' => 'DELETE'] + self::SONDE, $entete);
        $this->assertSame(422, $methode['status']);
        $this->assertArrayHasKey('method', $methode['body']['errors']);
    }

    /**
     * L'arbitrage commun aux modules (cf. Journal::assertNoConflict) : une
     * version périmée n'est un conflit que si QUELQU'UN D'AUTRE a touché le
     * même champ. Deux onglets à soi ne s'opposent pas.
     */
    #[Test]
    public function une_modification_concurrente_est_arbitree_champ_par_champ(): void
    {
        $hote  = $this->register('conflit@test.local');
        $creee = $this->call('POST', '/api/probes', self::SONDE, $this->bearer($hote['token']))['body']['data'];

        $admin = $this->membre($hote, 'admin@test.local');
        Database::connection()->prepare(
            "UPDATE memberships SET role = 'admin'
              WHERE organization_id = :org
                AND user_id = (SELECT id FROM users WHERE email = 'admin@test.local')",
        )->execute(['org' => $hote['org']]);

        $renommee = $this->call(
            'PUT',
            "/api/probes/{$creee['id']}",
            ['name' => 'Paiement', 'version' => $creee['version']],
            $this->bearer($hote['token']),
        );
        $this->assertSame(200, $renommee['status']);
        $this->assertSame($creee['version'] + 1, $renommee['body']['data']['version']);

        // Même champ, base périmée, autre personne : refus, avec l'état courant.
        $dispute = $this->call(
            'PUT',
            "/api/probes/{$creee['id']}",
            ['name' => 'Autre nom', 'version' => $creee['version']],
            $this->bearer($admin['token']),
        );
        $this->assertSame(409, $dispute['status']);
        $this->assertArrayHasKey('name', $dispute['body']['errors']);

        // Autre champ, même base périmée : rien à arbitrer, l'écriture passe.
        $voisine = $this->call(
            'PUT',
            "/api/probes/{$creee['id']}",
            ['slow_ms' => 600, 'version' => $creee['version']],
            $this->bearer($admin['token']),
        );
        $this->assertSame(200, $voisine['status']);
        $this->assertSame('Paiement', $voisine['body']['data']['name']);
    }

    /**
     * Le worker écrit l'état de la sonde dans une table à part : la version,
     * elle, ne bouge pas à chaque appel — sinon chaque minute ouvrirait un
     * conflit avec quiconque règle la sonde.
     */
    #[Test]
    public function un_appel_du_worker_ne_change_pas_la_version(): void
    {
        $hote   = $this->register('version@test.local');
        $entete = $this->bearer($hote['token']);
        $creee  = $this->call('POST', '/api/probes', self::SONDE, $entete)['body']['data'];

        (new ProbeRunner($this->labo(['up'])))->runDue();

        $apres = $this->call('GET', "/api/probes/{$creee['id']}", headers: $entete)['body']['data'];

        $this->assertSame('up', $apres['last_outcome']);
        $this->assertSame($creee['version'], $apres['version']);
    }

    #[Test]
    public function supprimer_puis_restaurer_une_sonde(): void
    {
        $hote   = $this->register('corbeille@test.local');
        $entete = $this->bearer($hote['token']);
        $sonde  = $this->creer($hote);

        $this->assertSame(204, $this->call('DELETE', "/api/probes/{$sonde}", headers: $entete)['status']);
        $this->assertCount(0, $this->call('GET', '/api/probes', headers: $entete)['body']['data']);
        $this->assertSame(404, $this->call('GET', "/api/probes/{$sonde}", headers: $entete)['status']);

        $this->assertSame(200, $this->call('POST', "/api/probes/{$sonde}/restore", headers: $entete)['status']);
        $this->assertCount(1, $this->call('GET', '/api/probes', headers: $entete)['body']['data']);
    }

    #[Test]
    public function verifier_maintenant_avance_l_echeance_une_fois_par_trente_secondes(): void
    {
        $hote   = $this->register('maintenant@test.local');
        $entete = $this->bearer($hote['token']);
        $sonde  = $this->creer($hote);

        Database::connection()->exec(
            "UPDATE probe_states SET next_check_at = NOW() + INTERVAL '1 hour' WHERE probe_id = '{$sonde}'",
        );

        $this->assertSame(202, $this->call('POST', "/api/probes/{$sonde}/check", headers: $entete)['status']);

        $echue = (bool) Database::connection()->query(
            "SELECT next_check_at <= NOW() FROM probe_states WHERE probe_id = '{$sonde}'",
        )->fetchColumn();

        $this->assertTrue($echue);
        $this->assertSame(429, $this->call('POST', "/api/probes/{$sonde}/check", headers: $entete)['status']);
    }

    #[Test]
    public function le_quota_d_un_espace_est_tenu(): void
    {
        $hote = $this->register('quota@test.local');

        Database::connection()->prepare(
            "INSERT INTO probes (organization_id, name, url)
             SELECT :org, 'Sonde ' || n, 'https://relais-demo.fr/' || n FROM generate_series(1, 25) AS n",
        )->execute(['org' => $hote['org']]);

        $refus = $this->call('POST', '/api/probes', self::SONDE, $this->bearer($hote['token']));

        $this->assertSame(422, $refus['status']);
        $this->assertStringContainsString('25', (string) $refus['body']['errors']['name']);
    }

    #[Test]
    public function une_sonde_d_un_autre_espace_est_introuvable(): void
    {
        $alice = $this->register('alice@test.local');
        $bob   = $this->register('bob@test.local');
        $sonde = $this->creer($alice);

        $entete = $this->bearer($bob['token']);

        $this->assertCount(0, $this->call('GET', '/api/probes', headers: $entete)['body']['data']);
        $this->assertSame(404, $this->call('GET', "/api/probes/{$sonde}", headers: $entete)['status']);
        $this->assertSame(404, $this->call('PUT', "/api/probes/{$sonde}", ['name' => 'Volée'], $entete)['status']);
        $this->assertSame(404, $this->call('POST', "/api/probes/{$sonde}/check", headers: $entete)['status']);
    }

    #[Test]
    public function le_detail_porte_les_releves_les_pannes_et_la_disponibilite(): void
    {
        $hote   = $this->register('detail@test.local');
        $entete = $this->bearer($hote['token']);
        $sonde  = $this->creer($hote);

        $runner = new ProbeRunner($this->labo(['down', 'up']));
        $runner->runDue();
        Database::connection()->exec("UPDATE probe_states SET next_check_at = NOW() WHERE probe_id = '{$sonde}'");
        $runner->runDue();

        $detail = $this->call('GET', "/api/probes/{$sonde}", headers: $entete)['body']['data'];

        $this->assertCount(2, $detail['checks']);
        $this->assertCount(1, $detail['incidents']);
        $this->assertNotNull($detail['incidents'][0]['ended_at']);
        $this->assertSame(50.0, (float) $detail['uptime_30d']);
        $this->assertCount(2, $detail['recent']);
        $this->assertNull($detail['down_since'], 'la panne est close');

        // En ISO 8601, comme tous les horodatages de l'API : le format natif de
        // PostgreSQL ne se lit pas côté client, et l'écran affichait « — ».
        $this->assertSame('T', substr((string) $detail['last_checked_at'], 10, 1));
        $this->assertSame('T', substr((string) $detail['incidents'][0]['started_at'], 10, 1));
        $this->assertSame('T', substr((string) $detail['checks'][0]['checked_at'], 10, 1));
    }

    /**
     * @param array{token: string} $hote
     */
    private function creer(array $hote): string
    {
        $reponse = $this->call('POST', '/api/probes', self::SONDE, $this->bearer($hote['token']));

        $this->assertSame(201, $reponse['status'], json_encode($reponse['body']) ?: '');

        return (string) $reponse['body']['data']['id'];
    }

    /**
     * @param list<'up'|'slow'|'down'> $script
     */
    private function labo(array $script): Prober
    {
        return new class ($script) implements Prober {
            private int $appels = 0;

            /** @param list<'up'|'slow'|'down'> $script */
            public function __construct(private readonly array $script)
            {
            }

            public function call(string $url, string $method, int $timeoutMs, int $slowMs): array
            {
                $issue = $this->script[min($this->appels++, count($this->script) - 1)];

                return $issue === 'down'
                    ? ['outcome' => 'down', 'http_status' => 503, 'response_ms' => 120, 'error' => 'Réponse HTTP 503.']
                    : ['outcome' => $issue, 'http_status' => 200, 'response_ms' => 90, 'error' => null];
            }
        };
    }
}
