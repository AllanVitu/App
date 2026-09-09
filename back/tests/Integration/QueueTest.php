<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\JobHandlers;
use App\Services\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * La file de tâches.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  CE QUI SE VÉRIFIE ICI NE SE VÉRIFIE NULLE PART AILLEURS                │
 * │                                                                         │
 * │  Une file se juge sur ses cas dégradés : deux workers qui puisent en    │
 * │  même temps, un worker tué en plein travail, une tâche qui échoue       │
 * │  trois fois. Aucun de ces cas n'apparaît dans un parcours navigateur,   │
 * │  et tous font perdre des e-mails quand ils sont mal traités.            │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
final class QueueTest extends ApiTestCase
{
    #[Test]
    public function une_tache_mise_en_file_ressort_avec_sa_charge(): void
    {
        $id = Queue::push('mail.send', ['to' => 'jean@test.local', 'essais' => 2]);

        $tache = Queue::reserve();

        $this->assertNotNull($tache);
        $this->assertSame($id, $tache['id']);
        $this->assertSame('mail.send', $tache['type']);
        $this->assertSame('jean@test.local', $tache['payload']['to']);
        $this->assertSame(2, $tache['payload']['essais']);
    }

    #[Test]
    public function une_tache_reservee_n_est_pas_rendue_deux_fois(): void
    {
        Queue::push('mail.send');

        $premiere = Queue::reserve();
        $seconde  = Queue::reserve();

        // C'est LA propriété de la file : deux workers qui puisent au même
        // instant ne peuvent pas expédier deux fois le même e-mail.
        $this->assertNotNull($premiere);
        $this->assertNull($seconde);
    }

    #[Test]
    public function une_tache_differee_attend_son_heure(): void
    {
        Queue::push('mail.send', [], delay: 60);

        $avant = Queue::reserve();
        $this->assertNull($avant, 'elle ne devait pas être exécutable tout de suite');

        // On avance l'échéance plutôt que le temps : le test reste instantané.
        Database::connection()->exec("UPDATE jobs SET available_at = NOW() - interval '1 second'");

        $this->assertNotNull(Queue::reserve());
    }

    #[Test]
    public function une_tache_terminee_quitte_la_table(): void
    {
        Queue::push('mail.send');
        $tache = Queue::reserve();

        Queue::complete($tache['id']);

        $this->assertSame(0, (int) Database::connection()
            ->query('SELECT count(*) FROM jobs')->fetchColumn());
    }

    #[Test]
    public function une_tache_echouee_est_reprogrammee_avec_du_recul(): void
    {
        Queue::push('mail.send');
        $tache = Queue::reserve();

        Queue::fail($tache['id'], $tache['attempts'], $tache['max_attempts'], 'SMTP muet');

        // Reprogrammée, pas abandonnée : une panne passagère se résout souvent
        // seule, et réessayer dans la seconde ne ferait que la constater plus
        // vite.
        $this->assertNull(Queue::reserve(), 'le recul doit la rendre indisponible');

        $row = Database::connection()->query('SELECT * FROM jobs')->fetch();
        $this->assertNull($row['failed_at']);
        $this->assertSame('SMTP muet', $row['last_error']);
        $this->assertSame(1, (int) $row['attempts']);
    }

    #[Test]
    public function une_tache_abandonnee_reste_en_table_avec_sa_derniere_erreur(): void
    {
        Queue::push('mail.send');

        // Trois essais, comme en production.
        for ($i = 1; $i <= 3; $i++) {
            Database::connection()->exec('UPDATE jobs SET available_at = NOW(), reserved_at = NULL');
            $tache = Queue::reserve();

            Queue::fail($tache['id'], $tache['attempts'], $tache['max_attempts'], "échec {$i}");
        }

        $row = Database::connection()->query('SELECT * FROM jobs')->fetch();

        // Elle RESTE : une tâche échouée qu'on efface est une panne que
        // personne ne verra jamais.
        $this->assertNotNull($row['failed_at']);
        $this->assertSame('échec 3', $row['last_error']);
        $this->assertNull(Queue::reserve(), 'une tâche abandonnée ne doit plus être reprise');
    }

    #[Test]
    public function le_compteur_d_essais_monte_a_la_reservation_et_non_a_l_echec(): void
    {
        Queue::push('mail.send');
        Queue::reserve();

        // ┌─────────────────────────────────────────────────────────────────┐
        // │  LE CAS QUI TUE UN WORKER EN BOUCLE                             │
        // │                                                                 │
        // │  Une tâche qui fait tomber le processus ne signale jamais son   │
        // │  échec. Si le compteur montait à l'échec, elle repartirait      │
        // │  intacte, referait tomber le worker, indéfiniment.              │
        // │                                                                 │
        // │  En comptant à la RÉSERVATION, elle consomme ses trois essais   │
        // │  et finit abandonnée — le worker reprend son travail.           │
        // └─────────────────────────────────────────────────────────────────┘
        $this->assertSame(1, (int) Database::connection()
            ->query('SELECT attempts FROM jobs')->fetchColumn());
    }

    #[Test]
    public function une_tache_abandonnee_par_un_worker_mort_est_reprise(): void
    {
        Queue::push('mail.send');
        Queue::reserve();

        $pendant = Queue::reserve();
        $this->assertNull($pendant, 'elle est réservée');

        // Le worker a été tué : la réservation n'a jamais été rendue. Sans
        // reprise, la tâche resterait bloquée pour toujours — et c'est le
        // genre de panne qu'on ne remarque qu'en cherchant autre chose.
        Database::connection()->exec("UPDATE jobs SET reserved_at = NOW() - interval '10 minutes'");

        $reprise = Queue::reserve();

        $this->assertNotNull($reprise);
        $this->assertSame(2, $reprise['attempts']);
    }

    #[Test]
    public function le_planificateur_ne_declenche_qu_a_echeance(): void
    {
        Database::connection()->exec(
            "INSERT INTO scheduled_tasks (name, type, interval_seconds)
             VALUES ('recette', 'tokens.purge', 3600)",
        );

        // On vise « recette » nommément plutôt que le contenu entier : la
        // table des travaux périodiques est un CATALOGUE, semé par migration
        // comme l'est celui des modules. Elle n'est donc pas vidée entre deux
        // tests, et « purge-jetons » y figure aussi.
        $this->assertContains('recette', Queue::schedule(), 'jamais joué : dû immédiatement');

        // Joué à l'instant : plus dû. Sans cette condition, chaque tour de
        // boucle du worker remettrait le même travail en file.
        $this->assertNotContains('recette', Queue::schedule());

        Database::connection()->exec(
            "UPDATE scheduled_tasks SET last_run_at = NOW() - interval '2 hours' WHERE name = 'recette'",
        );

        $this->assertContains('recette', Queue::schedule());
    }

    #[Test]
    public function la_purge_ne_retire_que_ce_qui_ne_sert_plus(): void
    {
        $user = $this->register();
        $pdo  = Database::connection();

        $poser = static function (string $hash, string $revoke, string $expire) use ($pdo, $user): void {
            $pdo->prepare(
                'INSERT INTO refresh_tokens (user_id, token_hash, expires_at, revoked_at)
                 VALUES (:u, :h, NOW() + (:e || \' days\')::interval,
                         CASE WHEN :r = \'null\' THEN NULL ELSE NOW() - (:r || \' days\')::interval END)',
            )->execute(['u' => $user['id'], 'h' => $hash, 'e' => $expire, 'r' => $revoke]);
        };

        $poser(str_repeat('a', 64), '30', '10');   // révoqué il y a 30 j  → purgé
        $poser(str_repeat('b', 64), '2', '10');    // révoqué il y a 2 j   → gardé
        $poser(str_repeat('c', 64), 'null', '-30'); // expiré il y a 30 j  → purgé
        $poser(str_repeat('d', 64), 'null', '5');  // actif                → gardé

        JobHandlers::handle('tokens.purge', ['retention_days' => 14]);

        $restants = $pdo->query('SELECT token_hash FROM refresh_tokens ORDER BY token_hash')
            ->fetchAll(\PDO::FETCH_COLUMN);

        // On garde de quoi enquêter sur une session récente, pas l'historique
        // complet d'un compte. La session du test compte parmi les actives.
        $this->assertContains(str_repeat('b', 64), $restants);
        $this->assertContains(str_repeat('d', 64), $restants);
        $this->assertNotContains(str_repeat('a', 64), $restants);
        $this->assertNotContains(str_repeat('c', 64), $restants);
    }

    #[Test]
    public function un_type_de_tache_inconnu_echoue_franchement(): void
    {
        // Une tâche dont le gestionnaire a disparu ne doit pas être avalée en
        // silence : elle épuisera ses essais et restera visible en table.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Type de tâche inconnu/');

        JobHandlers::handle('tache.qui.n.existe.pas', []);
    }

    #[Test]
    public function un_envoi_sans_destinataire_echoue_avant_le_serveur_smtp(): void
    {
        // Mieux vaut refuser une charge incomplète que d'ouvrir une connexion
        // SMTP pour découvrir qu'il manque le sujet.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/champ « to » manquant/');

        JobHandlers::handle('mail.send', ['name' => 'Jean']);
    }

    #[Test]
    public function un_e_mail_de_compte_part_en_file_et_non_dans_la_requete(): void
    {
        $this->call('POST', '/api/auth/register', [
            'full_name'      => 'Jean Dupont',
            'email'          => 'jean@test.local',
            'password'       => 'Motdepasse1',
            'terms_accepted' => true,
        ]);

        // ┌─────────────────────────────────────────────────────────────────┐
        // │  L'INSCRIPTION N'ATTEND PLUS LE SERVEUR SMTP                    │
        // │                                                                 │
        // │  Le lien de confirmation partait DANS la requête : un serveur   │
        // │  lent rendait l'inscription lente, un serveur muet la faisait   │
        // │  expirer. Il est désormais déposé ici, et remis par le worker.  │
        // └─────────────────────────────────────────────────────────────────┘
        $tache = Queue::reserve();

        $this->assertNotNull($tache, 'aucun e-mail mis en file');
        $this->assertSame('mail.send', $tache['type']);
        $this->assertSame('jean@test.local', $tache['payload']['to']);
        $this->assertStringContainsString('Confirmez', $tache['payload']['subject']);
    }

    #[Test]
    public function les_compteurs_distinguent_attente_reservation_et_echec(): void
    {
        Queue::push('mail.send');
        Queue::push('mail.send');
        Queue::reserve();

        $stats = Queue::stats();

        $this->assertSame(1, $stats['en_attente']);
        $this->assertSame(1, $stats['reservees']);
        $this->assertSame(0, $stats['echouees']);
    }
}
