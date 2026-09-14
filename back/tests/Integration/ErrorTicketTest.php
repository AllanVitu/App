<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Une erreur devient un ticket, et s'en souvient.
 *
 * Ce qui compte : un seul ticket par erreur tant qu'il vit, un ticket qui dit
 * d'où il vient, et une frontière entre espaces que le lien ne franchit pas.
 */
final class ErrorTicketTest extends ApiTestCase
{
    #[Test]
    public function une_erreur_devient_un_ticket_qui_dit_d_ou_il_vient(): void
    {
        $entete = $this->bearer($this->register('lien@test.local')['token']);
        $erreur = $this->erreur($entete, 'panier-total', 'fatal');

        $reponse = $this->call('POST', "/api/errors/{$erreur}/ticket", [], $entete);

        $this->assertSame(201, $reponse['status'], json_encode($reponse['body']) ?: '');

        $ticket = $reponse['body']['data']['ticket'];
        $groupe = $reponse['body']['data']['group'];

        $this->assertStringStartsWith('Erreur : TypeError', $ticket['title']);
        $this->assertSame('urgent', $ticket['priority']);
        $this->assertSame('todo', $ticket['status']);
        $this->assertStringContainsString('panier.js', (string) $ticket['description']);
        $this->assertContains('erreur', $ticket['labels']);

        $this->assertSame(
            ['id' => $ticket['id'], 'number' => $ticket['number'], 'status' => 'todo'],
            $groupe['ticket'],
        );

        // Le lien se relit : l'écran rouvert montre le ticket.
        $relu = $this->call('GET', "/api/errors/{$erreur}", [], $entete)['body']['data'];
        $this->assertSame($ticket['number'], $relu['ticket']['number']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function niveaux(): iterable
    {
        yield 'avertissement' => ['warning', 'medium'];
        yield 'erreur'        => ['error', 'high'];
        yield 'fatale'        => ['fatal', 'urgent'];
    }

    #[Test]
    #[DataProvider('niveaux')]
    public function la_priorite_suit_la_gravite(string $niveau, string $priorite): void
    {
        $entete = $this->bearer($this->register("niveau-{$niveau}@test.local")['token']);
        $erreur = $this->erreur($entete, "gravite-{$niveau}", $niveau);

        $ticket = $this->call('POST', "/api/errors/{$erreur}/ticket", [], $entete)['body']['data']['ticket'];

        $this->assertSame($priorite, $ticket['priority']);
    }

    #[Test]
    public function un_second_clic_ne_cree_pas_de_doublon(): void
    {
        $entete = $this->bearer($this->register('doublon@test.local')['token']);
        $erreur = $this->erreur($entete, 'double-clic');

        $premier = $this->call('POST', "/api/errors/{$erreur}/ticket", [], $entete);
        $second  = $this->call('POST', "/api/errors/{$erreur}/ticket", [], $entete);

        $this->assertSame(201, $premier['status']);
        $this->assertSame(200, $second['status']);
        $this->assertSame($premier['body']['data']['ticket']['id'], $second['body']['data']['ticket']['id']);

        $this->assertCount(1, $this->call('GET', '/api/tickets', [], $entete)['body']['data']);
    }

    #[Test]
    public function un_ticket_supprime_rend_l_erreur_a_personne(): void
    {
        $entete = $this->bearer($this->register('liberee@test.local')['token']);
        $erreur = $this->erreur($entete, 'ticket-supprime');

        $ticket = $this->call('POST', "/api/errors/{$erreur}/ticket", [], $entete)['body']['data']['ticket'];

        $this->assertSame(204, $this->call('DELETE', "/api/tickets/{$ticket['id']}", [], $entete)['status']);

        // Un ticket à la corbeille ne s'occupe plus de rien : l'erreur ne le
        // montre plus, et un nouveau clic en ouvre un autre.
        $this->assertNull($this->call('GET', "/api/errors/{$erreur}", [], $entete)['body']['data']['ticket']);

        $nouveau = $this->call('POST', "/api/errors/{$erreur}/ticket", [], $entete);

        $this->assertSame(201, $nouveau['status']);
        $this->assertNotSame($ticket['id'], $nouveau['body']['data']['ticket']['id']);
    }

    #[Test]
    public function l_erreur_d_un_autre_espace_est_introuvable(): void
    {
        $alice  = $this->bearer($this->register('alice@test.local')['token']);
        $bob    = $this->bearer($this->register('bob@test.local')['token']);
        $erreur = $this->erreur($alice, 'frontiere');

        $this->assertSame(404, $this->call('POST', "/api/errors/{$erreur}/ticket", [], $bob)['status']);
        $this->assertCount(0, $this->call('GET', '/api/tickets', [], $bob)['body']['data']);
        $this->assertCount(0, $this->call('GET', '/api/tickets', [], $alice)['body']['data']);
    }

    #[Test]
    public function le_journal_raconte_les_deux_cotes(): void
    {
        $entete = $this->bearer($this->register('journal-lien@test.local')['token']);
        $erreur = $this->erreur($entete, 'journal-lien');

        $ticket = $this->call('POST', "/api/errors/{$erreur}/ticket", [], $entete)['body']['data']['ticket'];

        $statement = Database::connection()->prepare(
            'SELECT module, action, subject_ref FROM activity WHERE subject_id IN (:erreur, :ticket) ORDER BY id',
        );
        $statement->execute(['erreur' => $erreur, 'ticket' => $ticket['id']]);

        $faits = array_map(
            static fn (array $ligne): string => "{$ligne['module']}:{$ligne['action']}:{$ligne['subject_ref']}",
            $statement->fetchAll(),
        );

        $this->assertContains("tickets:created:TICK-{$ticket['number']}", $faits);
        $this->assertContains("supervision:linked:TICK-{$ticket['number']}", $faits);
    }

    /**
     * @param array<string, string> $entete
     */
    private function erreur(array $entete, string $empreinte, string $niveau = 'error'): string
    {
        $reponse = $this->call('POST', '/api/errors', [
            'fingerprint' => $empreinte,
            'title'       => "TypeError: impossible de lire « total » ({$empreinte})",
            'culprit'     => 'panier.js',
            'level'       => $niveau,
            'message'     => 'Cannot read properties of undefined (reading total)',
        ], $entete);

        $this->assertContains($reponse['status'], [200, 201, 202], json_encode($reponse['body']) ?: '');

        return (string) $reponse['body']['data']['id'];
    }
}
