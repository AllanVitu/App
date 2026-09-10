<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Deux personnes sur le même écran.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  CE QUI SE VÉRIFIE ICI N'EST PAS UNE FONCTIONNALITÉ, C'EST UNE PERTE    │
 * │                                                                         │
 * │  Avant ce jalon, la dernière écriture gagnait en silence : Alice tapait │
 * │  une description, Bob changeait la priorité, et le second à enregistrer │
 * │  effaçait le travail du premier sans que personne ne l'apprenne.        │
 * │                                                                         │
 * │  Le test qui compte le plus n'est PAS celui du conflit — c'est celui de │
 * │  l'absence de conflit. Neuf écritures concurrentes sur dix portent sur  │
 * │  des champs différents, et refuser celles-là ferait perdre un           │
 * │  paragraphe pour rien. Un arbitrage trop zélé est aussi nuisible qu'une │
 * │  écriture aveugle.                                                      │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
final class CollaborationTest extends ApiTestCase
{
    // =======================================================================
    //  Le conflit, et surtout son absence
    // =======================================================================

    #[Test]
    public function deux_champs_differents_ne_sont_pas_un_conflit(): void
    {
        ['alice' => $alice, 'bob' => $bob] = $this->equipe();

        $ticket = $this->creer($alice, 'Chantier commun');
        $depart = $ticket['version'];

        // Bob change la priorité. Alice, qui n'a pas rechargé, écrit la
        // description en partant de la MÊME version.
        $this->modifier($bob, $ticket['id'], ['priority' => 'urgent', 'version' => $depart]);

        $reponse = $this->call(
            'PUT',
            '/api/tickets/' . $ticket['id'],
            ['description' => 'Le paragraphe d\'Alice', 'version' => $depart],
            $this->bearer($alice['token']),
        );

        // LE CAS QUI COMPTE : les deux écritures tiennent. Refuser celle
        // d'Alice aurait fait perdre son texte pour un désaccord qui n'existe
        // pas.
        $this->assertSame(200, $reponse['status']);
        $this->assertSame('Le paragraphe d\'Alice', $reponse['body']['data']['description']);
        $this->assertSame('urgent', $reponse['body']['data']['priority']);
    }

    #[Test]
    public function le_meme_champ_est_un_conflit_et_le_serveur_le_nomme(): void
    {
        ['alice' => $alice, 'bob' => $bob] = $this->equipe();

        $ticket = $this->creer($alice, 'Chantier disputé');
        $depart = $ticket['version'];

        $this->modifier($bob, $ticket['id'], [
            'description' => 'La version de Bob',
            'version'     => $depart,
        ]);

        $reponse = $this->call(
            'PUT',
            '/api/tickets/' . $ticket['id'],
            ['description' => 'La version d\'Alice', 'version' => $depart],
            $this->bearer($alice['token']),
        );

        $this->assertSame(409, $reponse['status']);

        // Le champ EST NOMMÉ, et son auteur aussi. « La ressource a été
        // modifiée » n'aurait laissé aucun moyen de décider quoi faire.
        $this->assertArrayHasKey('description', $reponse['body']['errors']);
        $this->assertStringContainsString('Bob', $reponse['body']['errors']['description']);
        $this->assertStringContainsString('la description', $reponse['body']['errors']['description']);
    }

    #[Test]
    public function le_refus_porte_l_etat_courant(): void
    {
        ['alice' => $alice, 'bob' => $bob] = $this->equipe();

        $ticket = $this->creer($alice, 'Chantier');
        $depart = $ticket['version'];

        $this->modifier($bob, $ticket['id'], ['title' => 'Titre de Bob', 'version' => $depart]);

        $reponse = $this->call(
            'PUT',
            '/api/tickets/' . $ticket['id'],
            ['title' => 'Titre d\'Alice', 'version' => $depart],
            $this->bearer($alice['token']),
        );

        // Sans l'état courant, le client ne pourrait que recharger — donc
        // perdre ce qu'il avait saisi. Avec lui, il peut proposer un choix.
        $this->assertSame(409, $reponse['status']);
        $this->assertSame('Titre de Bob', $reponse['body']['meta']['current']['title']);
        $this->assertGreaterThan($depart, $reponse['body']['meta']['current']['version']);
    }

    #[Test]
    public function on_ne_s_arbitre_pas_contre_soi_meme(): void
    {
        ['alice' => $alice] = $this->equipe();

        $ticket = $this->creer($alice, 'Deux onglets');
        $depart = $ticket['version'];

        // Le même compte, deux onglets, le même champ.
        $this->modifier($alice, $ticket['id'], ['description' => 'Onglet A', 'version' => $depart]);

        $reponse = $this->call(
            'PUT',
            '/api/tickets/' . $ticket['id'],
            ['description' => 'Onglet B', 'version' => $depart],
            $this->bearer($alice['token']),
        );

        // Ils se marchent dessus, mais demander à quelqu'un d'arbitrer contre
        // lui-même n'aiderait personne : le second onglet gagne, comme partout.
        $this->assertSame(200, $reponse['status']);
        $this->assertSame('Onglet B', $reponse['body']['data']['description']);
    }

    #[Test]
    public function une_ecriture_sans_version_n_est_pas_arbitree(): void
    {
        ['alice' => $alice, 'bob' => $bob] = $this->equipe();

        $ticket = $this->creer($alice, 'Au clavier');

        $this->modifier($bob, $ticket['id'], ['priority' => 'urgent']);

        // Les raccourcis clavier écrivent un champ unique et connu. Leur
        // imposer une lecture préalable annulerait ce qui fait l'intérêt du
        // module — et « d » sur un ticket ne peut pas se tromper de champ.
        $reponse = $this->call(
            'PUT',
            '/api/tickets/' . $ticket['id'],
            ['status' => 'done'],
            $this->bearer($alice['token']),
        );

        $this->assertSame(200, $reponse['status']);
    }

    #[Test]
    public function la_version_monte_a_chaque_ecriture_et_ne_se_choisit_pas(): void
    {
        ['alice' => $alice] = $this->equipe();

        $ticket = $this->creer($alice, 'Compteur');

        $this->assertSame(1, $ticket['version']);

        // « version » dans le corps est un jeton DE LECTURE : le client
        // annonce ce qu'il détient, il ne décide pas de la suite. Un client
        // qui tenterait de fixer 99 n'obtient que 2.
        $modifie = $this->modifier($alice, $ticket['id'], ['title' => 'Renommé', 'version' => 99]);

        $this->assertSame(2, $modifie['version']);
    }

    // =======================================================================
    //  Le journal
    // =======================================================================

    #[Test]
    public function le_journal_consigne_ce_qui_a_change_et_rien_d_autre(): void
    {
        ['alice' => $alice] = $this->equipe();

        $ticket = $this->creer($alice, 'Suivi');

        $this->modifier($alice, $ticket['id'], [
            'priority' => 'high',
            // Le titre est renvoyé À L'IDENTIQUE : ce n'est pas un changement,
            // et le consigner ferait du bruit dans le fil de toute l'équipe.
            'title'    => 'Suivi',
        ]);

        $evenements = $this->flux($alice, null);
        $suite      = $this->flux($alice, 0);

        $modification = end($suite['body']['data']);

        $this->assertSame('updated', $modification['action']);
        $this->assertSame(['priority' => ['none', 'high']], $modification['changes']);
        $this->assertArrayNotHasKey('title', $modification['changes']);

        // La référence courte et le titre voyagent avec : le fil doit se lire
        // sans aller relire chaque ticket.
        $this->assertSame('TICK-' . $ticket['number'], $modification['subject_ref']);
        $this->assertSame('Alice', $modification['actor_name']);

        unset($evenements);
    }

    #[Test]
    public function le_journal_survit_a_la_suppression_de_son_auteur(): void
    {
        ['alice' => $alice, 'bob' => $bob] = $this->equipe();

        $ticket = $this->creer($bob, 'Écrit par Bob');

        Database::connection()
            ->prepare('DELETE FROM users WHERE id = :id')
            ->execute(['id' => $bob['id']]);

        $flux = $this->flux($alice, 0);
        $premier = $flux['body']['data'][0];

        // Le nom est RECOPIÉ dans le journal, contre toutes les habitudes de
        // normalisation. Un journal qui se réécrit quand un compte disparaît
        // n'est plus un journal : « Bob a créé TICK-1 » doit rester lisible.
        $this->assertSame('Bob', $premier['actor_name']);
        $this->assertNull($premier['actor_id']);
        $this->assertSame('TICK-' . $ticket['number'], $premier['subject_ref']);
    }

    #[Test]
    public function une_suppression_laisse_une_trace_lisible(): void
    {
        ['alice' => $alice] = $this->equipe();

        $ticket = $this->creer($alice, 'À supprimer');

        $this->call(
            'DELETE',
            '/api/tickets/' . $ticket['id'],
            headers: $this->bearer($alice['token']),
        );

        $flux = $this->flux($alice, 0);
        $dernier = end($flux['body']['data']);

        // Le titre est figé au moment du fait : après suppression, il n'est
        // plus lisible ailleurs, et le fil afficherait une ligne muette.
        $this->assertSame('deleted', $dernier['action']);
        $this->assertSame('À supprimer', $dernier['subject_title']);
    }

    // =======================================================================
    //  Le flux
    // =======================================================================

    #[Test]
    public function un_ecran_qui_s_ouvre_ne_rejoue_pas_l_histoire(): void
    {
        ['alice' => $alice] = $this->equipe();

        $this->creer($alice, 'Avant');
        $this->creer($alice, 'Encore avant');

        $premier = $this->flux($alice, null);

        // Curseur à zéro : l'écran vient de charger son état complet. Lui
        // renvoyer tous les événements passés le ferait rejouer ce qu'il
        // affiche déjà.
        $this->assertSame([], $premier['body']['data']);
        $this->assertGreaterThan(0, $premier['body']['meta']['cursor']);

        // À partir de là, seule la SUITE arrive.
        $this->creer($alice, 'Après');

        $suite = $this->flux($alice, $premier['body']['meta']['cursor']);

        $this->assertCount(1, $suite['body']['data']);
        $this->assertSame('Après', $suite['body']['data'][0]['subject_title']);
    }

    #[Test]
    public function le_flux_ne_traverse_pas_les_espaces(): void
    {
        ['alice' => $alice] = $this->equipe();
        $etranger = $this->register('etranger@test.local', name: 'Étranger');

        $depart = $this->flux($etranger, null)['body']['meta']['cursor'];

        $this->creer($alice, 'Secret d\'équipe');

        $flux = $this->flux($etranger, $depart);

        // Le curseur est un entier global à la table : rien n'empêcherait un
        // compte d'un autre espace de demander « ce qui suit le 12 ». Le
        // cloisonnement doit donc tenir dans la requête, pas dans le curseur.
        $this->assertSame([], $flux['body']['data']);
    }

    #[Test]
    public function un_ecran_trop_en_retard_est_prie_de_recharger(): void
    {
        ['alice' => $alice] = $this->equipe();

        $this->creer($alice, 'Un seul ticket');

        // Curseur volontairement très ancien. Rattraper deux cents
        // changements un par un ferait clignoter l'écran plus longtemps qu'un
        // rechargement franc.
        $lointain = 1;

        Database::connection()->exec(
            'INSERT INTO activity (organization_id, module, action, subject_id)
             SELECT a.organization_id, \'tickets\', \'updated\', gen_random_uuid()
               FROM activity a
               CROSS JOIN generate_series(1, 250)
              WHERE a.id = 1',
        );

        $flux = $this->flux($alice, $lointain);

        $this->assertTrue($flux['body']['meta']['distanced']);
        $this->assertSame([], $flux['body']['data']);
    }

    // =======================================================================
    //  La présence
    // =======================================================================

    #[Test]
    public function chacun_voit_les_autres_et_jamais_soi_meme(): void
    {
        ['alice' => $alice, 'bob' => $bob] = $this->equipe();

        $this->flux($bob, 0, ['ecran' => 'tickets']);

        $vueAlice = $this->flux($alice, 0, ['ecran' => 'tickets']);
        $presents = $vueAlice['body']['meta']['presence'];

        $this->assertCount(1, $presents);
        $this->assertSame('Bob', $presents[0]['full_name']);
        $this->assertSame('tickets', $presents[0]['screen']);

        // Se voir soi-même n'apprendrait rien et ferait un marqueur de plus
        // sur le ticket qu'on a justement sous les yeux.
        $this->assertSame(
            [],
            array_filter($presents, static fn (array $p): bool => $p['user_id'] === $alice['id']),
        );
    }

    #[Test]
    public function la_presence_dit_quel_ticket_est_ouvert(): void
    {
        ['alice' => $alice, 'bob' => $bob] = $this->equipe();

        $ticket = $this->creer($alice, 'Ouvert par Bob');

        $this->flux($bob, 0, ['ecran' => 'tickets', 'sujet' => $ticket['id']]);

        $presents = $this->flux($alice, 0, ['ecran' => 'tickets'])['body']['meta']['presence'];

        // C'est CETTE information qui évite une collision : savoir que
        // quelqu'un est sur le tableau ne sert à rien, savoir qu'il a CE
        // ticket ouvert évite d'y écrire par-dessus.
        $this->assertSame($ticket['id'], $presents[0]['subject_id']);
    }

    #[Test]
    public function une_presence_qui_ne_bat_plus_disparait(): void
    {
        ['alice' => $alice, 'bob' => $bob] = $this->equipe();

        $this->flux($bob, 0, ['ecran' => 'tickets']);

        // Vieillie en base plutôt qu'attendue : c'est la date qui fait foi.
        Database::connection()->exec("UPDATE presence SET seen_at = NOW() - INTERVAL '1 minute'");

        $presents = $this->flux($alice, 0, ['ecran' => 'tickets'])['body']['meta']['presence'];

        // Un onglet fermé sans prévenir — coupure, plantage — ne doit pas
        // laisser un marqueur éternel sur un ticket que plus personne ne
        // regarde.
        $this->assertSame([], $presents);
    }

    #[Test]
    public function fermer_l_onglet_retire_le_marqueur_tout_de_suite(): void
    {
        ['alice' => $alice, 'bob' => $bob] = $this->equipe();

        $this->flux($bob, 0, ['ecran' => 'tickets']);
        $this->assertCount(1, $this->flux($alice, 0)['body']['meta']['presence']);

        $this->call('DELETE', '/api/stream', headers: $this->bearer($bob['token']));

        // Le repli du temps rendrait le même service quinze secondes plus
        // tard ; ceci fait disparaître le marqueur immédiatement.
        $this->assertSame([], $this->flux($alice, 0)['body']['meta']['presence']);
    }

    #[Test]
    public function un_ecran_inconnu_est_refuse(): void
    {
        ['alice' => $alice] = $this->equipe();

        $reponse = $this->flux($alice, 0, ['ecran' => 'inventé']);

        // Liste blanche, comme les tris et les filtres : une valeur libre
        // finirait par remplir la colonne de n'importe quoi, et l'affichage
        // de la présence n'aurait plus de vocabulaire.
        $this->assertSame(422, $reponse['status']);
    }

    // =======================================================================

    /**
     * @return array<string, mixed>
     */
    private function creer(array $session, string $titre): array
    {
        $reponse = $this->call(
            'POST',
            '/api/tickets',
            ['title' => $titre],
            $this->bearer($session['token']),
        );

        $this->assertSame(201, $reponse['status'], 'création refusée');

        return $reponse['body']['data'];
    }

    /**
     * @param  array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function modifier(array $session, string $id, array $payload): array
    {
        $reponse = $this->call('PUT', "/api/tickets/{$id}", $payload, $this->bearer($session['token']));

        $this->assertSame(200, $reponse['status'], 'modification refusée');

        return $reponse['body']['data'];
    }

    /**
     * Un sondage du flux.
     *
     * « null » OMET le paramètre : c'est ainsi que se dit « premier appel »,
     * et non par la valeur zéro — qui est un curseur parfaitement valide dans
     * un espace neuf.
     *
     * @param  array<string, string> $query
     * @return array{status: int, body: array<string, mixed>}
     */
    private function flux(array $session, ?int $depuis, array $query = []): array
    {
        return $this->call(
            'GET',
            '/api/stream',
            [],
            $this->bearer($session['token']),
            ($depuis === null ? [] : ['depuis' => (string) $depuis]) + $query,
        );
    }

    /**
     * Un espace à deux, par le vrai parcours d'invitation.
     *
     * @return array{alice: array<string, mixed>, bob: array<string, mixed>}
     */
    private function equipe(): array
    {
        $alice = $this->register('alice@test.local', name: 'Alice');

        $this->call('POST', '/api/organizations/invitations', [
            'email' => 'bob@test.local',
        ], $this->bearer($alice['token']));

        $statement = Database::connection()->query(
            "SELECT payload FROM jobs
              WHERE type = 'mail.send' AND payload->>'to' = 'bob@test.local'
           ORDER BY created_at DESC LIMIT 1",
        );

        $payload = json_decode((string) $statement->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($payload, 'aucune invitation déposée en file');

        preg_match('/token=([a-f0-9]{64})/', (string) $payload['text'], $trouve);

        $bob = $this->register('bob@test.local', name: 'Bob');

        $this->call('POST', "/api/invitations/{$trouve[1]}/accept", headers: $this->bearer($bob['token']));

        return ['alice' => $alice, 'bob' => ['org' => $alice['org']] + $bob];
    }
}
