<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * La ligne de production et « ma journée » du tableau de bord.
 */
final class DashboardDayTest extends ApiTestCase
{
    /**
     * Une branche d'aperçu déployée vingt fois dans l'après-midi ne dit rien
     * de ce que subissent les utilisateurs ; une mise en production d'avant-
     * hier n'est plus « la journée ».
     */
    #[Test]
    public function la_ligne_ne_trace_que_la_production_des_dernieres_24_heures(): void
    {
        $session = $this->register('ligne@test.local');
        $entetes = $this->bearer($session['token']);

        $this->deployer($entetes, 'production', 'a41f9c2aa0001');
        $this->deployer($entetes, 'preview', 'b82d410bb0002');
        $this->deployer($entetes, 'production', 'c93e521cc0003');

        Database::connection()->exec(
            "UPDATE deployments SET created_at = NOW() - INTERVAL '25 hours' WHERE commit_sha = 'c93e521cc0003'",
        );

        $ligne = $this->tableau($entetes)['line'];

        $this->assertCount(1, $ligne['deployments']);
        $this->assertSame('a41f9c2', $ligne['deployments'][0]['sha']);
        $this->assertSame('main', $ligne['deployments'][0]['branch']);
        $this->assertCount(24, $ligne['hours']);

        // Des instants lisibles par n'importe quel navigateur.
        $iso = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';
        $this->assertMatchesRegularExpression($iso, $ligne['from']);
        $this->assertMatchesRegularExpression($iso, $ligne['to']);
        $this->assertMatchesRegularExpression($iso, $ligne['deployments'][0]['at']);
    }

    /**
     * La dernière barre compte les soixante dernières minutes : une erreur
     * reçue à l'instant y tombe, et une erreur d'hier soir n'y est pas.
     */
    #[Test]
    public function les_erreurs_tombent_dans_leur_heure_et_pas_au_dela_de_la_fenetre(): void
    {
        $entetes = $this->bearer($this->register('erreurs@test.local')['token']);

        $this->signaler($entetes);
        $this->signaler($entetes);

        Database::connection()->exec(
            "UPDATE error_events SET occurred_at = NOW() - INTERVAL '30 hours'
              WHERE id = (SELECT id FROM error_events ORDER BY occurred_at LIMIT 1)",
        );

        $heures = $this->tableau($entetes)['line']['hours'];

        $this->assertSame(1, $heures[23]);
        $this->assertSame(1, array_sum($heures));
    }

    #[Test]
    public function ma_journee_ne_montre_que_mes_tickets_ouverts_par_echeance(): void
    {
        $moi     = $this->register('moi@test.local');
        $entetes = $this->bearer($moi['token']);

        $this->ticket($entetes, 'Dans cinq jours', $moi['id'], date('Y-m-d', strtotime('+5 days')));
        $this->ticket($entetes, 'Pour demain', $moi['id'], date('Y-m-d', strtotime('+1 day')));
        $this->ticket($entetes, 'Terminé', $moi['id'], date('Y-m-d'));
        $this->ticket($entetes, 'À personne', null, date('Y-m-d'));
        $this->ticket($entetes, 'Sans date 1', $moi['id'], null);
        $this->ticket($entetes, 'Sans date 2', $moi['id'], null);
        $this->ticket($entetes, 'Sans date 3', $moi['id'], null);

        Database::connection()->exec("UPDATE tickets SET status = 'done' WHERE title = 'Terminé'");

        $journee = $this->tableau($entetes)['my_day'];

        // Cinq tickets ouverts me reviennent ; quatre sont détaillés.
        $this->assertSame(5, $journee['total']);
        $this->assertCount(4, $journee['tickets']);

        // L'échéance d'abord : demain avant dans cinq jours, les tickets sans
        // date ensuite.
        $this->assertSame('Pour demain', $journee['tickets'][0]['title']);
        $this->assertSame('Dans cinq jours', $journee['tickets'][1]['title']);
        $this->assertNull($journee['tickets'][2]['due_date']);

        $titres = array_column($journee['tickets'], 'title');
        $this->assertNotContains('Terminé', $titres);
        $this->assertNotContains('À personne', $titres);
    }

    /**
     * @param array<string, string> $entetes
     *
     * @return array<string, mixed>
     */
    private function tableau(array $entetes): array
    {
        $reponse = $this->call('GET', '/api/dashboard', headers: $entetes);

        $this->assertSame(200, $reponse['status']);

        return $reponse['body']['data'];
    }

    /**
     * @param array<string, string> $entetes
     */
    private function deployer(array $entetes, string $environnement, string $sha): void
    {
        $reponse = $this->call('POST', '/api/deployments', [
            'environment' => $environnement,
            'branch'      => 'main',
            'commit_sha'  => $sha,
        ], $entetes);

        $this->assertSame(201, $reponse['status'], json_encode($reponse['body']) ?: '');
    }

    /**
     * @param array<string, string> $entetes
     */
    private function signaler(array $entetes): void
    {
        $reponse = $this->call('POST', '/api/errors', [
            'fingerprint' => 'panier-total-indefini',
            'title'       => 'TypeError: impossible de lire « total »',
            'message'     => 'Cannot read properties of undefined (reading total)',
        ], $entetes);

        $this->assertContains($reponse['status'], [200, 201], json_encode($reponse['body']) ?: '');
    }

    /**
     * @param array<string, string> $entetes
     */
    private function ticket(array $entetes, string $titre, ?string $assigne, ?string $echeance): void
    {
        $reponse = $this->call('POST', '/api/tickets', array_filter([
            'title'       => $titre,
            'assigned_to' => $assigne,
            'due_date'    => $echeance,
        ], static fn ($valeur): bool => $valeur !== null), $entetes);

        $this->assertSame(201, $reponse['status'], json_encode($reponse['body']) ?: '');
    }
}
