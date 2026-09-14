<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Terms;
use App\Core\Database;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * « Télécharger mes données », et l'acceptation des nouvelles conditions.
 *
 * L'export doit tout contenir de ce qui se rattache au compte, rien de ce qui
 * ouvrirait quelque chose, et rien du travail des autres.
 */
final class DataExportTest extends ApiTestCase
{
    #[Test]
    public function l_export_contient_ce_qui_se_rattache_au_compte(): void
    {
        $session = $this->register('export@test.local');
        $entete  = $this->bearer($session['token']);

        $ticket = $this->call('POST', '/api/tickets', ['title' => 'Écrit pour l’export'], $entete)['body']['data'];
        $this->call('POST', "/api/tickets/{$ticket['id']}/comments", ['body' => 'Mon commentaire exporté'], $entete);
        $this->call('POST', '/api/docs', ['title' => 'Ma procédure'], $entete);

        $reponse = $this->call('GET', '/api/profile/export', [], $entete);

        $this->assertSame(200, $reponse['status']);

        $donnees = $reponse['body']['data'];

        $this->assertSame('relais.export.v1', $donnees['format']);
        $this->assertSame('export@test.local', $donnees['compte']['email']);
        $this->assertSame('T', substr((string) $donnees['compte']['created_at'], 10, 1), 'des dates ISO 8601');

        $this->assertContains('Écrit pour l’export', array_column($donnees['contenus']['tickets_ecrits'], 'title'));
        $this->assertSame(['Mon commentaire exporté'], array_column($donnees['contenus']['commentaires'], 'body'));
        $this->assertContains('Ma procédure', array_column($donnees['contenus']['pages_de_documentation'], 'title'));

        $this->assertNotEmpty($donnees['espaces']);
        $this->assertNotEmpty($donnees['sessions']);
        $this->assertNotEmpty($donnees['historique']);
    }

    #[Test]
    public function l_export_ne_contient_aucune_empreinte_de_secret(): void
    {
        $session = $this->register('secrets@test.local');

        $texte = (string) json_encode($this->call('GET', '/api/profile/export', [], $this->bearer($session['token']))['body']);

        foreach (['password_hash', 'token_hash', '$2y$'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $texte);
        }
    }

    #[Test]
    public function le_travail_des_autres_n_y_figure_pas(): void
    {
        $hote   = $this->register('hote-export@test.local');
        $membre = $this->membre($hote, 'collegue-export@test.local');

        $ecrit = $this->call('POST', '/api/tickets', [
            'title'       => 'Confié à l’hôte',
            'description' => 'Description écrite par la collègue',
            'assigned_to' => $hote['id'],
        ], $this->bearer($membre['token']));

        $this->assertSame(201, $ecrit['status'], json_encode($ecrit['body']) ?: '');

        $donnees = $this->call('GET', '/api/profile/export', [], $this->bearer($hote['token']))['body']['data'];

        // Le ticket confié y figure par son numéro, son titre et son état…
        $this->assertSame([$ecrit['body']['data']['number']], array_column($donnees['contenus']['tickets_attribues'], 'number'));
        $this->assertSame([], $donnees['contenus']['tickets_ecrits']);

        // …jamais par ce que quelqu'un d'autre y a écrit.
        $this->assertStringNotContainsString('Description écrite par la collègue', (string) json_encode($donnees));
    }

    #[Test]
    public function l_export_se_limite_a_dix_par_heure(): void
    {
        $entete = $this->bearer($this->register('frequence@test.local')['token']);

        for ($i = 1; $i <= 10; $i++) {
            $this->assertSame(200, $this->call('GET', '/api/profile/export', [], $entete)['status'], "export n° {$i}");
        }

        $this->assertSame(429, $this->call('GET', '/api/profile/export', [], $entete)['status']);
    }

    #[Test]
    public function les_nouvelles_conditions_s_acceptent_sans_choisir_leur_numero(): void
    {
        $session = $this->register('nouvelles-conditions@test.local');
        $entete  = $this->bearer($session['token']);

        Database::connection()
            ->prepare("UPDATE users SET terms_accepted_version = '1.0' WHERE id = :id")
            ->execute(['id' => $session['id']]);

        $this->assertSame(422, $this->call('POST', '/api/profile/terms', ['accepted' => false], $entete)['status']);

        // Un numéro envoyé par le client est ignoré : on accepte le texte publié.
        $acceptee = $this->call('POST', '/api/profile/terms', ['accepted' => true, 'version' => '9.9'], $entete);

        $this->assertSame(200, $acceptee['status']);
        $this->assertSame(Terms::CURRENT_VERSION, $acceptee['body']['data']['terms_version']);
    }

    #[Test]
    public function il_faut_etre_connecte(): void
    {
        $this->assertSame(401, $this->call('GET', '/api/profile/export')['status']);
        $this->assertSame(401, $this->call('POST', '/api/profile/terms', ['accepted' => true])['status']);
    }
}
