<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * La discussion d'un ticket.
 *
 * Trois promesses : le texte revient EXACTEMENT tel qu'il a été écrit (c'est
 * le client qui le découpe, jamais le serveur qui le « nettoie ») ; seul son
 * auteur, ou un administrateur, retire un commentaire ; et le fil d'une autre
 * équipe est introuvable, même avec l'identifiant de son ticket.
 */
final class TicketCommentsTest extends ApiTestCase
{
    #[Test]
    public function un_membre_commente_et_le_texte_revient_tel_quel(): void
    {
        $hote   = $this->register('hote-fil@test.local');
        $membre = $this->membre($hote, 'membre-fil@test.local');
        $ticket = $this->ticket($hote);

        $texte = "**Reproduit** sur mobile.\n\n<script>alert(1)</script>\n\n    indenté";

        $cree = $this->call('POST', "/api/tickets/{$ticket['id']}/comments", ['body' => $texte], $this->bearer($membre['token']));

        $this->assertSame(201, $cree['status'], json_encode($cree['body']) ?: '');
        $this->assertSame($texte, $cree['body']['data']['body']);
        $this->assertSame('Membre Simple', $cree['body']['data']['author_name']);

        $fil = $this->call('GET', "/api/tickets/{$ticket['id']}/comments", [], $this->bearer($hote['token']));

        $this->assertSame(200, $fil['status']);
        $this->assertSame([$texte], array_column($fil['body']['data'], 'body'));
        $this->assertSame('T', substr((string) $fil['body']['data'][0]['created_at'], 10, 1));
    }

    #[Test]
    public function le_fil_se_lit_dans_l_ordre_ou_il_a_ete_ecrit(): void
    {
        $hote   = $this->register('ordre@test.local');
        $ticket = $this->ticket($hote);
        $entete = $this->bearer($hote['token']);

        foreach (['premier', 'deuxième', 'troisième'] as $mot) {
            $this->call('POST', "/api/tickets/{$ticket['id']}/comments", ['body' => $mot], $entete);
        }

        $fil = $this->call('GET', "/api/tickets/{$ticket['id']}/comments", [], $entete)['body']['data'];

        $this->assertSame(['premier', 'deuxième', 'troisième'], array_column($fil, 'body'));
    }

    #[Test]
    public function seul_l_auteur_ou_un_administrateur_retire_un_commentaire(): void
    {
        $hote   = $this->register('moderation@test.local');
        $membre = $this->membre($hote, 'moderation-membre@test.local');
        $ticket = $this->ticket($hote);

        $duHote   = $this->commenter($hote, $ticket, 'écrit par le propriétaire');
        $duMembre = $this->commenter($membre, $ticket, 'écrit par le membre');

        $url = "/api/tickets/{$ticket['id']}/comments";

        // Un membre ne retire pas ce qu'un autre a écrit…
        $this->assertSame(403, $this->call('DELETE', "{$url}/{$duHote}", [], $this->bearer($membre['token']))['status']);

        // …mais retire le sien, et le propriétaire retire celui de n'importe qui.
        $this->assertSame(204, $this->call('DELETE', "{$url}/{$duMembre}", [], $this->bearer($membre['token']))['status']);

        $autre = $this->commenter($membre, $ticket, 'un secret collé par erreur');
        $this->assertSame(204, $this->call('DELETE', "{$url}/{$autre}", [], $this->bearer($hote['token']))['status']);

        $restants = $this->call('GET', $url, [], $this->bearer($hote['token']))['body']['data'];
        $this->assertSame(['écrit par le propriétaire'], array_column($restants, 'body'));
    }

    #[Test]
    public function le_fil_d_un_autre_espace_est_introuvable(): void
    {
        $alice  = $this->register('alice@test.local');
        $bob    = $this->register('bob@test.local');
        $ticket = $this->ticket($alice);
        $id     = $this->commenter($alice, $ticket, 'entre nous');

        $url    = "/api/tickets/{$ticket['id']}/comments";
        $entete = $this->bearer($bob['token']);

        $this->assertSame(404, $this->call('GET', $url, [], $entete)['status']);
        $this->assertSame(404, $this->call('POST', $url, ['body' => 'intrusion'], $entete)['status']);
        $this->assertSame(404, $this->call('DELETE', "{$url}/{$id}", [], $entete)['status']);

        $this->assertCount(1, $this->call('GET', $url, [], $this->bearer($alice['token']))['body']['data']);
    }

    #[Test]
    public function un_commentaire_vide_ou_trop_long_est_refuse(): void
    {
        $hote   = $this->register('bornes-fil@test.local');
        $ticket = $this->ticket($hote);
        $url    = "/api/tickets/{$ticket['id']}/comments";
        $entete = $this->bearer($hote['token']);

        foreach (['', "   \n  ", str_repeat('a', 5001)] as $texte) {
            $refus = $this->call('POST', $url, ['body' => $texte], $entete);

            $this->assertSame(422, $refus['status']);
            $this->assertArrayHasKey('body', $refus['body']['errors']);
        }

        $this->assertSame(422, $this->call('POST', $url, ['body' => ['pas', 'du', 'texte']], $entete)['status']);
        $this->assertSame(201, $this->call('POST', $url, ['body' => str_repeat('a', 5000)], $entete)['status']);
    }

    #[Test]
    public function le_journal_dit_qu_on_a_commente_sans_recopier_le_texte(): void
    {
        $hote   = $this->register('journal-fil@test.local');
        $ticket = $this->ticket($hote);

        $this->commenter($hote, $ticket, 'Le mot de passe provisoire est hibiscus');

        $statement = Database::connection()->prepare(
            "SELECT module, subject_ref, activity::text AS ligne
               FROM activity
              WHERE subject_id = :id AND action = 'commented'",
        );
        $statement->execute(['id' => $ticket['id']]);
        $fait = $statement->fetch();

        $this->assertIsArray($fait);
        $this->assertSame('tickets', $fait['module']);
        $this->assertSame("TICK-{$ticket['number']}", $fait['subject_ref']);
        $this->assertStringNotContainsString('hibiscus', (string) $fait['ligne']);
    }

    #[Test]
    public function un_ticket_supprime_emporte_l_acces_a_son_fil(): void
    {
        $hote   = $this->register('corbeille-fil@test.local');
        $ticket = $this->ticket($hote);
        $entete = $this->bearer($hote['token']);

        $this->commenter($hote, $ticket, 'avant la corbeille');
        $this->assertSame(204, $this->call('DELETE', "/api/tickets/{$ticket['id']}", [], $entete)['status']);

        $this->assertSame(404, $this->call('GET', "/api/tickets/{$ticket['id']}/comments", [], $entete)['status']);

        // Restauré, le ticket retrouve sa discussion : la corbeille n'efface rien.
        $this->call('POST', "/api/tickets/{$ticket['id']}/restore", [], $entete);
        $this->assertCount(1, $this->call('GET', "/api/tickets/{$ticket['id']}/comments", [], $entete)['body']['data']);
    }

    /**
     * @param  array<string, string> $session
     * @return array<string, mixed>
     */
    private function ticket(array $session): array
    {
        $reponse = $this->call('POST', '/api/tickets', ['title' => 'Le panier se vide seul'], $this->bearer($session['token']));

        $this->assertSame(201, $reponse['status']);

        return $reponse['body']['data'];
    }

    /**
     * @param array<string, string> $session
     * @param array<string, mixed>  $ticket
     */
    private function commenter(array $session, array $ticket, string $texte): string
    {
        $reponse = $this->call('POST', "/api/tickets/{$ticket['id']}/comments", ['body' => $texte], $this->bearer($session['token']));

        $this->assertSame(201, $reponse['status'], json_encode($reponse['body']) ?: '');

        return (string) $reponse['body']['data']['id'];
    }
}
