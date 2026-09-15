<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * L'application range ses propres pannes.
 *
 * Trois exigences, et la troisième est la moins visible :
 *
 *   — une panne de l'API, du navigateur ou du worker arrive dans l'espace de
 *     l'instance, avec de quoi la retrouver et la lire ;
 *   — seuls les administrateurs de l'instance y entrent, et par leur rôle ;
 *   — RIEN de personnel n'y est rangé. Une supervision qui recopie adresses
 *     et jetons devient la plus grande fuite de l'application, et elle est
 *     lue par des gens qui n'ont aucune raison de les voir.
 */
final class SelfMonitorTest extends ApiTestCase
{
    private const UUID = '550e8400-e29b-41d4-a716-446655440000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->routesFile = __DIR__ . '/../Support/routes-pannes.php';
    }

    // -----------------------------------------------------------------------
    //  Pannes de l'API
    // -----------------------------------------------------------------------

    #[Test]
    public function une_panne_de_l_api_est_rangee_avec_sa_reference_et_sans_rien_de_personnel(): void
    {
        $reponse = $this->call('GET', '/api/test/pannes/' . self::UUID);

        $this->assertSame(500, $reponse['status']);

        $reference = $reponse['body']['meta']['reference'] ?? null;
        $this->assertIsString($reference, 'la réponse doit porter une référence');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{8}$/', $reference);

        $groupes = $this->groupesDeLInstance();
        $this->assertCount(1, $groupes);
        $this->assertStringStartsWith('RuntimeException : ', (string) $groupes[0]['title']);
        $this->assertStringContainsString('PanneController.php', (string) $groupes[0]['culprit']);

        $occurrence = $this->derniereOccurrence((string) $groupes[0]['id']);

        // La référence remise à l'utilisateur est celle qu'on retrouve : c'est
        // tout ce qui permet de relier « j'ai eu une erreur » à une ligne.
        $this->assertSame($reference, $occurrence['context']['reference']);
        $this->assertSame('/api/test/pannes/{id}', $occurrence['context']['route']);
        $this->assertSame('GET', $occurrence['context']['method']);

        $this->assertStringNotContainsString('alice@exemple.fr', $occurrence['message']);
        $this->assertStringNotContainsString(str_repeat('ab', 20), $occurrence['message']);
        $this->assertStringContainsString('[courriel]', $occurrence['message']);

        // La trace situe l'appel sans rien recopier de ce qui transitait.
        $this->assertStringContainsString('PanneController->exception()', $occurrence['stack']);
        $this->assertStringNotContainsString(self::UUID, $occurrence['stack']);

        // La première occurrence fait un fait dans le fil de l'instance.
        $this->assertSame([['module' => 'supervision', 'action' => 'created']], $this->filDeLInstance());
    }

    #[Test]
    public function la_meme_panne_repetee_fait_un_seul_groupe_qui_compte(): void
    {
        $this->call('GET', '/api/test/pannes/' . self::UUID);
        $this->call('GET', '/api/test/pannes/7c9e6679-7425-40de-944b-e07fc1f90ae7');

        $groupes = $this->groupesDeLInstance();

        $this->assertCount(1, $groupes, 'deux identifiants, une seule route : une seule panne');
        $this->assertSame(2, (int) $groupes[0]['occurrences']);

        // Et un seul fait dans le fil : la répétition ne s'y écrit pas.
        $this->assertCount(1, $this->filDeLInstance());
    }

    #[Test]
    public function une_transaction_avortee_n_empeche_pas_le_signalement(): void
    {
        $reponse = $this->call('GET', '/api/test/transaction-avortee');

        $this->assertSame(500, $reponse['status']);

        $groupes = $this->groupesDeLInstance();
        $this->assertCount(1, $groupes);
        $this->assertStringStartsWith('LogicException : ', (string) $groupes[0]['title']);

        $this->assertFalse(Database::connection()->inTransaction());
    }

    #[Test]
    public function un_defaut_de_code_est_range_comme_fatal(): void
    {
        $this->call('GET', '/api/test/defaut-de-code');

        $groupes = $this->groupesDeLInstance();

        $this->assertCount(1, $groupes);
        $this->assertSame('fatal', $groupes[0]['level']);
    }

    #[Test]
    public function une_panne_en_rafale_est_comptee_sans_etre_detaillee_au_dela_de_vingt_par_minute(): void
    {
        for ($i = 0; $i < 23; $i++) {
            $this->call('GET', '/api/test/pannes/' . self::UUID);
        }

        $groupes = $this->groupesDeLInstance();
        $this->assertSame(23, (int) $groupes[0]['occurrences'], 'aucune occurrence ne doit être perdue');

        $detaillees = Database::connection()->prepare('SELECT count(*) FROM error_events WHERE group_id = :id');
        $detaillees->execute(['id' => $groupes[0]['id']]);

        $this->assertSame(20, (int) $detaillees->fetchColumn());
    }

    // -----------------------------------------------------------------------
    //  Qui entre dans l'espace de l'instance
    // -----------------------------------------------------------------------

    #[Test]
    public function les_administrateurs_de_l_instance_y_entrent_par_leur_role_et_en_sortent_avec_lui(): void
    {
        $alice = $this->register('alice@test.local');

        $this->changerRole($alice['id'], 'admin');

        $espaces = $this->call('GET', '/api/organizations', [], $this->bearer($alice['token']))['body']['data'];
        $instance = array_values(array_filter($espaces, static fn (array $e): bool => $e['kind'] === 'instance'));

        $this->assertCount(1, $instance);
        $this->assertSame('owner', $instance[0]['role']);
        // En dernier : on y va quand quelque chose a cassé, pas pour travailler.
        $this->assertSame('instance', $espaces[array_key_last($espaces)]['kind']);

        $this->changerRole($alice['id'], 'user');

        $espaces = $this->call('GET', '/api/organizations', [], $this->bearer($alice['token']))['body']['data'];

        // Rétrogradée, elle perd l'instance et garde son équipe.
        $this->assertSame(['team'], array_column($espaces, 'kind'));
    }

    #[Test]
    public function un_compte_ordinaire_ne_voit_ni_n_ouvre_les_pannes_de_l_instance(): void
    {
        $bob = $this->register('bob@test.local');

        $this->call('GET', '/api/test/pannes/' . self::UUID);

        $espaces = $this->call('GET', '/api/organizations', [], $this->bearer($bob['token']))['body']['data'];
        $this->assertNotContains('instance', array_column($espaces, 'kind'));

        $bascule = $this->call(
            'POST',
            '/api/organizations/' . $this->instanceId() . '/activate',
            [],
            $this->bearer($bob['token']),
        );
        $this->assertSame(404, $bascule['status']);

        $erreurs = $this->call('GET', '/api/errors', [], $this->bearer($bob['token']));
        $this->assertSame([], $erreurs['body']['data']);
    }

    #[Test]
    public function l_espace_de_l_instance_ne_se_gere_pas_comme_une_equipe(): void
    {
        $alice = $this->register('alice@test.local');
        $this->changerRole($alice['id'], 'admin');

        $instance = $this->instanceId();
        $entetes  = $this->bearer($alice['token']);

        $this->assertSame(200, $this->call('POST', "/api/organizations/{$instance}/activate", [], $entetes)['status']);

        $refus = [
            $this->call('POST', '/api/organizations/invitations', ['email' => 'curieux@test.local'], $entetes),
            $this->call('POST', '/api/organizations/leave', [], $entetes),
            $this->call('PUT', "/api/organizations/{$instance}", ['name' => 'Autre nom'], $entetes),
            $this->call('DELETE', "/api/organizations/{$instance}", [], $entetes),
        ];

        foreach ($refus as $reponse) {
            $this->assertSame(409, $reponse['status']);
            // Refusé pour CETTE raison, et non par un autre garde-fou qui
            // passerait par là.
            $this->assertStringContainsString('instance', $reponse['body']['message']);
        }
    }

    #[Test]
    public function l_espace_de_l_instance_ne_compte_pas_comme_un_espace_de_repli(): void
    {
        $alice = $this->register('alice@test.local');
        $this->changerRole($alice['id'], 'admin');

        $reponse = $this->call(
            'DELETE',
            '/api/organizations/' . $alice['org'],
            [],
            $this->bearer($alice['token']),
        );

        // Deux appartenances, mais une seule équipe : supprimer celle-ci la
        // laisserait sans aucun espace le jour de sa rétrogradation.
        $this->assertSame(409, $reponse['status']);
    }

    // -----------------------------------------------------------------------
    //  Pannes du navigateur
    // -----------------------------------------------------------------------

    #[Test]
    public function le_navigateur_signale_ses_erreurs_a_l_instance_sans_rien_divulguer(): void
    {
        $bob = $this->register('bob@test.local');

        $reponse = $this->call('POST', '/api/client-errors', $this->erreurNavigateur(
            'TicketsView-Bx3kLm9a.js:1:2345',
            'TypeError: impossible de lire « total » pour bob@exemple.fr',
        ), $this->bearer($bob['token']));

        $this->assertSame(202, $reponse['status']);

        $groupes = $this->groupesDeLInstance();
        $this->assertCount(1, $groupes);
        $this->assertSame('module-tickets · TicketPanel', $groupes[0]['culprit']);

        $occurrence = $this->derniereOccurrence((string) $groupes[0]['id']);

        $this->assertSame('web', $occurrence['context']['source']);
        $this->assertStringNotContainsString('bob@exemple.fr', $occurrence['message']);

        // Rien qui désigne Bob : ni compte, ni espace. Triées, parce que JSONB
        // range ses clés à sa façon et que seul leur ENSEMBLE compte ici.
        $cles = array_keys($occurrence['context']);
        sort($cles);

        $this->assertSame(['component', 'kind', 'release', 'route', 'source'], $cles);

        // Et rien dans son propre espace : sa panne n'est pas une donnée de
        // son équipe.
        $erreurs = $this->call('GET', '/api/errors', [], $this->bearer($bob['token']));
        $this->assertSame([], $erreurs['body']['data']);
    }

    #[Test]
    public function deux_compilations_de_la_meme_erreur_font_un_seul_groupe(): void
    {
        $bob = $this->register('bob@test.local');

        $this->call('POST', '/api/client-errors', $this->erreurNavigateur('TicketsView-Bx3kLm9a.js:1:2345'), $this->bearer($bob['token']));
        $this->call('POST', '/api/client-errors', $this->erreurNavigateur('TicketsView-Qw8rTy2u.js:1:2398'), $this->bearer($bob['token']));

        $groupes = $this->groupesDeLInstance();

        $this->assertCount(1, $groupes, 'un déploiement ne doit pas ouvrir un nouveau groupe');
        $this->assertSame(2, (int) $groupes[0]['occurrences']);
    }

    #[Test]
    public function le_signalement_du_navigateur_est_plafonne_par_compte(): void
    {
        $bob   = $this->register('bob@test.local');
        $carla = $this->register('carla@test.local');

        for ($i = 0; $i < 20; $i++) {
            $reponse = $this->call('POST', '/api/client-errors', $this->erreurNavigateur(), $this->bearer($bob['token']));
            $this->assertSame(202, $reponse['status']);
        }

        $refus = $this->call('POST', '/api/client-errors', $this->erreurNavigateur(), $this->bearer($bob['token']));
        $this->assertSame(429, $refus['status']);

        // Le plafond est celui de Bob, pas celui de l'instance.
        $autre = $this->call('POST', '/api/client-errors', $this->erreurNavigateur(), $this->bearer($carla['token']));
        $this->assertSame(202, $autre['status']);
    }

    #[Test]
    public function sans_session_le_navigateur_ne_peut_rien_signaler(): void
    {
        $reponse = $this->call('POST', '/api/client-errors', $this->erreurNavigateur());

        $this->assertSame(401, $reponse['status']);
        $this->assertSame([], $this->groupesDeLInstance());
    }

    // -----------------------------------------------------------------------
    //  Tâches de fond
    // -----------------------------------------------------------------------

    #[Test]
    public function une_tache_abandonnee_est_signalee_une_fois_et_sans_son_contenu(): void
    {
        $id = Queue::push('mail.send', ['to' => 'carla@exemple.fr']);

        for ($essai = 1; $essai <= 3; $essai++) {
            // Le recul entre deux essais repousse la tâche : on la rend
            // disponible pour jouer les trois essais sans attendre.
            Database::connection()
                ->prepare('UPDATE jobs SET available_at = NOW() WHERE id = :id')
                ->execute(['id' => $id]);

            $tache = Queue::reserve();
            $this->assertNotNull($tache);

            Queue::fail(
                $tache['id'],
                $tache['attempts'],
                $tache['max_attempts'],
                'Réponse SMTP inattendue : 550 carla@exemple.fr inconnue',
                $tache['type'],
            );
        }

        $groupes = $this->groupesDeLInstance();

        // Les deux essais reprogrammés ne sont pas des pannes : seul
        // l'abandon en est une.
        $this->assertCount(1, $groupes);
        $this->assertSame('Tâche abandonnée : mail.send', $groupes[0]['title']);
        $this->assertSame(1, (int) $groupes[0]['occurrences']);

        $occurrence = $this->derniereOccurrence((string) $groupes[0]['id']);
        $this->assertStringNotContainsString('carla@exemple.fr', $occurrence['message']);
    }

    // -----------------------------------------------------------------------

    private function instanceId(): string
    {
        return (string) Database::connection()->query('SELECT instance_organization()')->fetchColumn();
    }

    private function changerRole(string $userId, string $role): void
    {
        Database::connection()
            ->prepare('UPDATE users SET role = :role::user_role WHERE id = :id')
            ->execute(['id' => $userId, 'role' => $role]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function groupesDeLInstance(): array
    {
        // Lu sans créer : un test qui vérifie qu'il n'y a RIEN ne doit pas
        // faire naître l'espace qu'il inspecte.
        $statement = Database::connection()->query(
            "SELECT g.id, g.title, g.culprit, g.level, g.status, g.occurrences
               FROM error_groups g
               JOIN organizations o ON o.id = g.organization_id AND o.kind = 'instance'
              ORDER BY g.created_at",
        );

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll();
    }

    /**
     * @return array{message: string, stack: string, context: array<string, mixed>}
     */
    private function derniereOccurrence(string $groupId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT message, stack, context FROM error_events
              WHERE group_id = :id ORDER BY occurred_at DESC LIMIT 1',
        );
        $statement->execute(['id' => $groupId]);

        /** @var array<string, mixed> $row */
        $row = $statement->fetch();

        return [
            'message' => (string) $row['message'],
            'stack'   => (string) $row['stack'],
            'context' => Database::toArray($row['context']),
        ];
    }

    /**
     * @return list<array{module: string, action: string}>
     */
    private function filDeLInstance(): array
    {
        $statement = Database::connection()->query(
            "SELECT a.module, a.action FROM activity a
               JOIN organizations o ON o.id = a.organization_id AND o.kind = 'instance'
              ORDER BY a.id",
        );

        /** @var list<array{module: string, action: string}> */
        return $statement->fetchAll();
    }

    /**
     * @return array<string, string>
     */
    private function erreurNavigateur(
        string $cadre = 'TicketsView-Bx3kLm9a.js:1:2345',
        string $message = 'TypeError: impossible de lire « total »',
    ): array {
        return [
            'kind'      => 'component',
            'message'   => $message,
            'stack'     => "TypeError: impossible de lire « total »\n    at setup (https://app.exemple.fr/assets/{$cadre})",
            'route'     => 'module-tickets',
            'component' => 'TicketPanel',
            'release'   => '2026.09.13',
        ];
    }
}
