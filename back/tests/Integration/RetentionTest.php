<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\JobHandlers;
use App\Services\Retention;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Les durées de conservation publiées sont des durées appliquées.
 *
 * Chaque test vieillit une ligne au-delà de sa durée et une autre en deçà :
 * la purge doit effacer la première et garder la seconde. Une purge qui
 * n'efface rien passe aussi mal qu'une purge qui efface tout.
 */
final class RetentionTest extends ApiTestCase
{
    #[Test]
    public function chaque_purge_de_la_migration_a_son_gestionnaire(): void
    {
        $migration = (string) file_get_contents(__DIR__ . '/../../database/migrations/202609181000_conformite.sql');

        preg_match_all("/'([a-z_]+[.]purge)'/", $migration, $types);

        $this->assertCount(8, $types[1]);

        // Un type sans branche dans JobHandlers lèverait « Type de tâche
        // inconnu » chaque jour, en silence, dans le worker.
        foreach ($types[1] as $type) {
            JobHandlers::handle($type, ['retention_days' => 30]);
        }
    }

    #[Test]
    public function les_tentatives_de_connexion_partent_apres_trente_jours(): void
    {
        $this->register('tentatives@test.local');

        foreach ([1, 2] as $essai) {
            $this->call('POST', '/api/auth/login', ['email' => 'tentatives@test.local', 'password' => "faux-{$essai}"]);
        }

        $this->vieillir(
            "UPDATE login_attempts SET attempted_at = NOW() - INTERVAL '31 days'
              WHERE id = (SELECT MIN(id) FROM login_attempts WHERE email = 'tentatives@test.local')",
        );

        $this->assertGreaterThan(0, (new Retention())->loginAttempts(30));
        $this->assertSame(1, $this->compter("SELECT COUNT(*) FROM login_attempts WHERE email = 'tentatives@test.local' AND successful = false"));
    }

    #[Test]
    public function l_historique_part_apres_un_an(): void
    {
        $entete = $this->bearer($this->register('historique-purge@test.local')['token']);

        $ancien  = $this->call('POST', '/api/tickets', ['title' => 'Il y a longtemps'], $entete)['body']['data']['id'];
        $recent  = $this->call('POST', '/api/tickets', ['title' => 'Hier'], $entete)['body']['data']['id'];

        $this->vieillir("UPDATE activity SET happened_at = NOW() - INTERVAL '366 days' WHERE subject_id = '{$ancien}'");

        (new Retention())->activity(365);

        $this->assertSame(0, $this->compter("SELECT COUNT(*) FROM activity WHERE subject_id = '{$ancien}'"));
        $this->assertSame(1, $this->compter("SELECT COUNT(*) FROM activity WHERE subject_id = '{$recent}'"));
    }

    #[Test]
    public function les_occurrences_d_erreur_partent_apres_quatre_vingt_dix_jours_et_le_groupe_reste(): void
    {
        $entete = $this->bearer($this->register('evenements@test.local')['token']);
        $erreur = ['fingerprint' => 'purge-evenements', 'title' => 'TypeError', 'message' => 'Quelque chose a cassé.'];

        $groupe = $this->call('POST', '/api/errors', $erreur, $entete)['body']['data']['id'];
        $this->call('POST', '/api/errors', $erreur, $entete);

        $this->vieillir(
            "UPDATE error_events SET occurred_at = NOW() - INTERVAL '91 days'
              WHERE id = (SELECT id FROM error_events WHERE group_id = '{$groupe}' ORDER BY occurred_at LIMIT 1)",
        );

        (new Retention())->errorEvents(90);

        $this->assertSame(1, $this->compter("SELECT COUNT(*) FROM error_events WHERE group_id = '{$groupe}'"));
        $this->assertSame(1, $this->compter("SELECT COUNT(*) FROM error_groups WHERE id = '{$groupe}'"));
    }

    #[Test]
    public function les_liens_les_invitations_et_les_taches_en_echec_ne_trainent_pas(): void
    {
        $hote   = $this->register('liens-purge@test.local');
        $entete = $this->bearer($hote['token']);

        // Le lien de confirmation d'adresse posé à l'inscription.
        $this->assertGreaterThan(0, $this->compter("SELECT COUNT(*) FROM user_tokens WHERE user_id = '{$hote['id']}'"));
        $this->vieillir("UPDATE user_tokens SET expires_at = NOW() - INTERVAL '8 days' WHERE user_id = '{$hote['id']}'");

        $this->call('POST', '/api/organizations/invitations', ['email' => 'jamais-venu@test.local', 'role' => 'member'], $entete);
        $this->vieillir("UPDATE invitations SET expires_at = NOW() - INTERVAL '31 days' WHERE email = 'jamais-venu@test.local'");

        $this->vieillir(
            "INSERT INTO jobs (type, payload, failed_at, last_error)
             VALUES ('mail.send', '{\"to\": \"quelqu-un@test.local\"}', NOW() - INTERVAL '31 days', 'SMTP injoignable'),
                    ('mail.send', '{\"to\": \"autre@test.local\"}', NOW() - INTERVAL '2 days', 'SMTP injoignable')",
        );

        $retention = new Retention();
        $retention->userTokens(7);
        $retention->invitations(30);
        $retention->failedJobs(30);

        $this->assertSame(0, $this->compter("SELECT COUNT(*) FROM user_tokens WHERE user_id = '{$hote['id']}'"));
        $this->assertSame(0, $this->compter("SELECT COUNT(*) FROM invitations WHERE email = 'jamais-venu@test.local'"));
        $this->assertSame(0, $this->compter("SELECT COUNT(*) FROM jobs WHERE payload->>'to' = 'quelqu-un@test.local'"));
        $this->assertSame(1, $this->compter("SELECT COUNT(*) FROM jobs WHERE payload->>'to' = 'autre@test.local'"));
    }

    #[Test]
    public function la_corbeille_se_vide_apres_trente_jours(): void
    {
        $entete = $this->bearer($this->register('corbeille-purge@test.local')['token']);

        $ancien = $this->call('POST', '/api/tickets', ['title' => 'Supprimé il y a longtemps'], $entete)['body']['data'];
        $recent = $this->call('POST', '/api/tickets', ['title' => 'Supprimé hier'], $entete)['body']['data'];

        $this->call('POST', "/api/tickets/{$ancien['id']}/comments", ['body' => 'part avec son ticket'], $entete);

        foreach ([$ancien, $recent] as $ticket) {
            $this->assertSame(204, $this->call('DELETE', "/api/tickets/{$ticket['id']}", [], $entete)['status']);
        }

        $parent = $this->call('POST', '/api/docs', ['title' => 'Parent'], $entete)['body']['data']['id'];
        $enfant = $this->call('POST', '/api/docs', ['title' => 'Enfant', 'parent_id' => $parent], $entete)['body']['data']['id'];

        $this->vieillir("UPDATE tickets SET deleted_at = NOW() - INTERVAL '31 days' WHERE id = '{$ancien['id']}'");
        $this->vieillir("UPDATE doc_pages SET deleted_at = NOW() - INTERVAL '40 days' WHERE id IN ('{$parent}', '{$enfant}')");

        $this->assertGreaterThanOrEqual(3, (new Retention())->trash(30));

        $this->assertSame(0, $this->compter("SELECT COUNT(*) FROM tickets WHERE id = '{$ancien['id']}'"));
        $this->assertSame(0, $this->compter("SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = '{$ancien['id']}'"));
        $this->assertSame(1, $this->compter("SELECT COUNT(*) FROM tickets WHERE id = '{$recent['id']}'"), 'la corbeille récente reste restaurable');

        // Le parent n'est effaçable qu'une fois l'enfant parti : les deux partent.
        $this->assertSame(0, $this->compter("SELECT COUNT(*) FROM doc_pages WHERE id IN ('{$parent}', '{$enfant}')"));
    }

    private function vieillir(string $sql): void
    {
        Database::connection()->exec($sql);
    }

    private function compter(string $sql): int
    {
        return (int) Database::connection()->query($sql)->fetchColumn();
    }
}
