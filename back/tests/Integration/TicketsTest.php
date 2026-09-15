<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Module « Tickets ».
 *
 * Ces tests portent surtout sur les invariants que l'application NE tient
 * PAS elle-même : la numérotation et la date de clôture sont posées par des
 * triggers PostgreSQL. Une suite qui ne les exercerait pas laisserait la
 * partie la plus subtile du module sans filet.
 */
final class TicketsTest extends ApiTestCase
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function createTicket(string $token, array $payload): array
    {
        $response = $this->call('POST', '/api/tickets', $payload, $this->bearer($token));

        $this->assertSame(201, $response['status'], 'création de ticket refusée');

        return $response['body']['data'];
    }

    #[Test]
    public function la_numerotation_repart_de_un_pour_chaque_compte(): void
    {
        $alice = $this->register('alice@test.local');
        $bob = $this->register('bob@test.local');

        $this->assertSame(1, $this->createTicket($alice['token'], ['title' => 'Premier'])['number']);
        $this->assertSame(2, $this->createTicket($alice['token'], ['title' => 'Deuxième'])['number']);

        // Le compteur est PAR COMPTE : celui de Bob ne doit pas hériter des
        // deux tickets d'Alice. Une séquence PostgreSQL globale échouerait ici.
        $this->assertSame(1, $this->createTicket($bob['token'], ['title' => 'Le sien'])['number']);
    }

    #[Test]
    public function un_numero_supprime_n_est_jamais_reattribue(): void
    {
        $session = $this->register();

        $premier = $this->createTicket($session['token'], ['title' => 'Premier']);
        $this->assertSame(1, $premier['number']);

        $this->call('DELETE', "/api/tickets/{$premier['id']}", headers: $this->bearer($session['token']));

        // Une référence écrite ailleurs (commit, conversation) ne doit jamais
        // se mettre à désigner un autre ticket que celui d'origine.
        $this->assertSame(2, $this->createTicket($session['token'], ['title' => 'Suivant'])['number']);
    }

    #[Test]
    public function la_date_de_cloture_suit_le_statut(): void
    {
        $session = $this->register();
        $ticket = $this->createTicket($session['token'], ['title' => 'Cycle de vie']);

        $this->assertNull($ticket['completed_at'], 'un ticket neuf ne peut pas être clos');

        $clos = $this->call(
            'PUT',
            "/api/tickets/{$ticket['id']}",
            ['status' => 'done'],
            $this->bearer($session['token']),
        )['body']['data'];

        $this->assertNotNull($clos['completed_at'], 'la clôture doit être horodatée');

        // Réouverture : sans effacement, un ticket rouvert puis reclos
        // garderait la date du PREMIER passage en « terminé », et toute mesure
        // de délai construite dessus serait fausse.
        $rouvert = $this->call(
            'PUT',
            "/api/tickets/{$ticket['id']}",
            ['status' => 'in_progress'],
            $this->bearer($session['token']),
        )['body']['data'];

        $this->assertNull($rouvert['completed_at'], 'rouvrir doit effacer la date de clôture');
    }

    #[Test]
    public function une_mise_a_jour_partielle_preserve_les_autres_champs(): void
    {
        $session = $this->register();

        $ticket = $this->createTicket($session['token'], [
            'title'    => 'Titre initial',
            'priority' => 'high',
            'project'  => 'Sécurité',
            'labels'   => ['auth'],
        ]);

        // C'est ce qui rend les raccourcis clavier possibles : changer un
        // statut n'exige pas de renvoyer un ticket complet, potentiellement
        // périmé, qui écraserait les modifications d'entre-temps.
        $modifie = $this->call(
            'PUT',
            "/api/tickets/{$ticket['id']}",
            ['status' => 'in_progress'],
            $this->bearer($session['token']),
        )['body']['data'];

        $this->assertSame('in_progress', $modifie['status']);
        $this->assertSame('Titre initial', $modifie['title']);
        $this->assertSame('high', $modifie['priority']);
        $this->assertSame('Sécurité', $modifie['project']);
        $this->assertSame(['auth'], $modifie['labels']);
    }

    #[Test]
    public function un_titre_explicitement_vide_est_refuse(): void
    {
        $session = $this->register();
        $ticket = $this->createTicket($session['token'], ['title' => 'Titre valable']);

        $reponse = $this->call(
            'PUT',
            "/api/tickets/{$ticket['id']}",
            ['title' => ''],
            $this->bearer($session['token']),
        );

        // Champ présent mais vide = intention de l'effacer : à rejeter, et non
        // à ignorer en silence.
        $this->assertSame(422, $reponse['status']);
        $this->assertArrayHasKey('title', $reponse['body']['errors']);
    }

    #[Test]
    public function les_etiquettes_sont_normalisees_et_dedoublonnees(): void
    {
        $session = $this->register();

        $ticket = $this->createTicket($session['token'], [
            'title'  => 'Étiquettes en désordre',
            'labels' => ['  BUG  ', 'bug', 'Régression', 'régression'],
        ]);

        // Sans normalisation, « Bug », « bug » et « bug  » coexisteraient
        // comme trois étiquettes distinctes et les filtres deviendraient
        // inutilisables. L'ordre de première apparition est conservé.
        $this->assertSame(['bug', 'régression'], $ticket['labels']);
    }

    #[Test]
    public function au_dela_de_huit_etiquettes_la_requete_est_refusee(): void
    {
        $session = $this->register();

        $reponse = $this->call(
            'POST',
            '/api/tickets',
            ['title' => 'Trop bavard', 'labels' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i']],
            $this->bearer($session['token']),
        );

        $this->assertSame(422, $reponse['status']);
    }

    #[Test]
    public function un_filtre_inconnu_est_une_erreur_et_non_un_filtre_ignore(): void
    {
        $session = $this->register();
        $this->createTicket($session['token'], ['title' => 'Visible']);

        $reponse = $this->call(
            'GET',
            '/api/tickets',
            headers: $this->bearer($session['token']),
            query: ['status' => 'nimporte'],
        );

        // Renvoyer la liste entière alors que l'utilisateur croit l'avoir
        // restreinte est le plus trompeur des deux comportements possibles.
        $this->assertSame(422, $reponse['status']);
    }

    #[Test]
    public function un_compte_ne_voit_jamais_les_tickets_d_un_autre(): void
    {
        $alice = $this->register('alice@test.local');
        $bob = $this->register('bob@test.local');

        $ticket = $this->createTicket($alice['token'], ['title' => 'Faille à corriger']);

        // 404 et non 403 : Bob ne doit pas même apprendre que ce ticket existe.
        $this->assertSame(
            404,
            $this->call('GET', "/api/tickets/{$ticket['id']}", headers: $this->bearer($bob['token']))['status'],
        );

        $this->assertSame(
            404,
            $this->call(
                'PUT',
                "/api/tickets/{$ticket['id']}",
                ['title' => 'Détourné'],
                $this->bearer($bob['token']),
            )['status'],
        );

        $this->assertSame(
            404,
            $this->call('DELETE', "/api/tickets/{$ticket['id']}", headers: $this->bearer($bob['token']))['status'],
        );

        // La liste de Bob reste vide : le cloisonnement vaut aussi en lecture
        // de masse, pas seulement par identifiant.
        $liste = $this->call('GET', '/api/tickets', headers: $this->bearer($bob['token']));
        $this->assertSame([], $liste['body']['data']);
    }

    #[Test]
    public function les_indicateurs_distinguent_ouvert_urgent_et_en_retard(): void
    {
        $session = $this->register();

        $this->createTicket($session['token'], [
            'title'    => 'Urgent et en retard',
            'priority' => 'urgent',
            'due_date' => '2020-01-01',
        ]);
        $this->createTicket($session['token'], ['title' => 'Ordinaire']);

        $termine = $this->createTicket($session['token'], [
            'title'    => 'Déjà fait, échéance passée',
            'due_date' => '2020-01-01',
        ]);
        $this->call(
            'PUT',
            "/api/tickets/{$termine['id']}",
            ['status' => 'done'],
            $this->bearer($session['token']),
        );

        $stats = $this->call('GET', '/api/tickets', headers: $this->bearer($session['token']))['body']['meta']['stats'];

        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['open']);
        $this->assertSame(1, $stats['urgent']);
        // Le ticket terminé après son échéance n'est PAS en retard : il est
        // terminé. Le compter alimenterait le tableau de bord d'alertes
        // portant sur du travail déjà fait.
        $this->assertSame(1, $stats['overdue']);
    }

    #[Test]
    public function le_catalogue_compte_les_tickets_ouverts_et_ignore_la_table_generique(): void
    {
        $session = $this->register();

        // Une ligne dans la table générique, sur le module « backend ». Depuis
        // que chaque module a son propre modèle, elle ne doit compter NULLE
        // PART : la table générique n'est plus qu'un repli pour un module
        // ajouté en base sans écran dédié.
        $this->call(
            'POST',
            '/api/modules/backend/items',
            ['title' => 'Une fiche orpheline'],
            $this->bearer($session['token']),
        );

        $this->createTicket($session['token'], ['title' => 'Ouvert']);
        $clos = $this->createTicket($session['token'], ['title' => 'Clos']);
        $this->call('PUT', "/api/tickets/{$clos['id']}", ['status' => 'done'], $this->bearer($session['token']));

        $bySlug = $this->catalogue($session['token']);

        // Le compteur du menu lit la source de CHAQUE module. Avant
        // ModuleMetrics, « tickets » affichait son nombre de lignes dans
        // module_items — soit zéro, quel que soit le nombre de tickets.
        $this->assertSame(1, $bySlug['tickets']['items_count'], 'seuls les tickets OUVERTS sont comptés');
        $this->assertSame(0, $bySlug['backend']['items_count'], 'la ligne générique ne compte plus');
        $this->assertSame('tables', $bySlug['backend']['unit'], 'backend compte des tables, pas des éléments');

        // L'unité s'accorde en nombre côté SERVEUR : le laisser au client
        // obligerait chaque affichage à refaire la règle, et « 1 ouverts »
        // finirait par ressortir quelque part.
        $this->assertSame('ouvert', $bySlug['tickets']['unit'], 'un seul ticket : unité au singulier');

        $this->createTicket($session['token'], ['title' => 'Un second ouvert']);
        $bySlug = $this->catalogue($session['token']);

        $this->assertSame(2, $bySlug['tickets']['items_count']);
        $this->assertSame('ouverts', $bySlug['tickets']['unit'], 'deux tickets : unité au pluriel');
    }

    /**
     * Catalogue des modules, indexé par slug.
     *
     * @return array<string, array<string, mixed>>
     */
    private function catalogue(string $token): array
    {
        return array_column(
            $this->call('GET', '/api/modules', headers: $this->bearer($token))['body']['data'],
            null,
            'slug',
        );
    }

    #[Test]
    public function le_tableau_de_bord_signale_le_retard_avant_l_urgence(): void
    {
        $session = $this->register();

        $this->createTicket($session['token'], ['title' => 'Urgent seulement', 'priority' => 'urgent']);
        $this->createTicket($session['token'], ['title' => 'En retard', 'due_date' => '2020-01-01']);
        $this->createTicket($session['token'], ['title' => 'Tranquille']);

        $attention = $this->call('GET', '/api/dashboard', headers: $this->bearer($session['token']))
            ['body']['data']['attention'];

        $this->assertCount(2, $attention, 'seuls le retard et l\'urgence justifient une alerte');

        // Une échéance dépassée est un fait, une priorité n'est qu'une
        // intention : le retard passe donc en premier.
        $this->assertSame('overdue', $attention[0]['reason']);
        $this->assertSame('En retard', $attention[0]['title']);
        $this->assertSame('urgent', $attention[1]['reason']);
    }
}
