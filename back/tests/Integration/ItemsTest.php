<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * CRUD des éléments de module.
 *
 * Le test le plus important de ce fichier est le cloisonnement : c'est la
 * garantie qu'un compte ne voit jamais les données d'un autre. Tout le reste
 * de la sécurité serait sans effet s'il tombait.
 */
final class ItemsTest extends ApiTestCase
{
    #[Test]
    public function un_element_se_cree_et_se_relit(): void
    {
        $session = $this->register();

        $creation = $this->call(
            'POST',
            '/api/modules/module-1/items',
            [
                'title'       => 'Première fiche',
                'description' => 'Une description',
                'status'      => 'active',
                'due_date'    => '2026-12-24',
                'data'        => ['priority' => 'high'],
            ],
            $this->bearer($session['token']),
        );

        $this->assertSame(201, $creation['status']);

        $item = $creation['body']['data'];
        $this->assertSame('Première fiche', $item['title']);
        $this->assertSame('active', $item['status']);
        $this->assertSame('high', $item['data']['priority']);

        $lecture = $this->call('GET', "/api/items/{$item['id']}", headers: $this->bearer($session['token']));
        $this->assertSame(200, $lecture['status']);
    }

    #[Test]
    public function un_compte_ne_voit_jamais_les_elements_d_un_autre(): void
    {
        $alice = $this->register('alice@test.local');
        $bob = $this->register('bob@test.local');

        $item = $this->call(
            'POST',
            '/api/modules/module-1/items',
            ['title' => 'Document confidentiel'],
            $this->bearer($alice['token']),
        )['body']['data'];

        // Lecture directe par identifiant : 404, jamais 403 — Bob ne doit pas
        // même apprendre que cet élément existe.
        $lecture = $this->call('GET', "/api/items/{$item['id']}", headers: $this->bearer($bob['token']));
        $this->assertSame(404, $lecture['status']);

        // Modification et suppression : mêmes garanties.
        $ecriture = $this->call(
            'PUT',
            "/api/items/{$item['id']}",
            ['title' => 'Détourné'],
            $this->bearer($bob['token']),
        );
        $this->assertSame(404, $ecriture['status']);

        $suppression = $this->call('DELETE', "/api/items/{$item['id']}", headers: $this->bearer($bob['token']));
        $this->assertSame(404, $suppression['status']);

        // Et la liste de Bob reste vide.
        $liste = $this->call('GET', '/api/modules/module-1/items', headers: $this->bearer($bob['token']));
        $this->assertSame([], $liste['body']['data']);

        // L'élément d'Alice est intact.
        $this->assertSame(
            200,
            $this->call('GET', "/api/items/{$item['id']}", headers: $this->bearer($alice['token']))['status'],
        );
    }

    #[Test]
    public function la_mise_a_jour_est_partielle(): void
    {
        $session = $this->register();

        $item = $this->call(
            'POST',
            '/api/modules/module-1/items',
            ['title' => 'Titre initial', 'status' => 'active', 'data' => ['priority' => 'low']],
            $this->bearer($session['token']),
        )['body']['data'];

        // Un champ absent conserve sa valeur…
        $modifie = $this->call(
            'PUT',
            "/api/items/{$item['id']}",
            ['title' => 'Titre modifié'],
            $this->bearer($session['token']),
        );

        $this->assertSame('Titre modifié', $modifie['body']['data']['title']);
        $this->assertSame('active', $modifie['body']['data']['status']);
        $this->assertSame('low', $modifie['body']['data']['data']['priority']);
    }

    #[Test]
    public function un_titre_explicitement_vide_est_refuse(): void
    {
        $session = $this->register();

        $item = $this->call(
            'POST',
            '/api/modules/module-1/items',
            ['title' => 'Titre initial'],
            $this->bearer($session['token']),
        )['body']['data'];

        // Présent mais vide : c'est une intention de l'utilisateur, elle doit
        // être rejetée plutôt qu'ignorée en silence.
        $reponse = $this->call(
            'PUT',
            "/api/items/{$item['id']}",
            ['title' => ''],
            $this->bearer($session['token']),
        );

        $this->assertSame(422, $reponse['status']);
        $this->assertArrayHasKey('title', $reponse['body']['errors']);
    }

    #[Test]
    public function la_suppression_est_logique(): void
    {
        $session = $this->register();

        $item = $this->call(
            'POST',
            '/api/modules/module-1/items',
            ['title' => 'À supprimer'],
            $this->bearer($session['token']),
        )['body']['data'];

        $this->assertSame(
            204,
            $this->call('DELETE', "/api/items/{$item['id']}", headers: $this->bearer($session['token']))['status'],
        );

        // Invisible de l'API…
        $this->assertSame(
            404,
            $this->call('GET', "/api/items/{$item['id']}", headers: $this->bearer($session['token']))['status'],
        );

        // …mais toujours en base, avec sa date de suppression.
        $statement = Database::connection()->prepare(
            'SELECT deleted_at IS NOT NULL FROM module_items WHERE id = :id',
        );
        $statement->execute(['id' => $item['id']]);
        $this->assertTrue(Database::toBool($statement->fetchColumn()));
    }

    #[Test]
    public function la_recherche_et_les_filtres_restreignent_la_liste(): void
    {
        $session = $this->register();
        $headers = $this->bearer($session['token']);

        foreach ([['Rapport mensuel', 'active'], ['Brouillon interne', 'draft']] as [$title, $status]) {
            $this->call('POST', '/api/modules/module-1/items', compact('title', 'status'), $headers);
        }

        $recherche = $this->call('GET', '/api/modules/module-1/items', headers: $headers, query: ['search' => 'rapport']);
        $this->assertCount(1, $recherche['body']['data']);
        $this->assertSame('Rapport mensuel', $recherche['body']['data'][0]['title']);

        $filtre = $this->call('GET', '/api/modules/module-1/items', headers: $headers, query: ['status' => 'draft']);
        $this->assertCount(1, $filtre['body']['data']);
        $this->assertSame('Brouillon interne', $filtre['body']['data'][0]['title']);
    }

    #[Test]
    public function les_jokers_de_recherche_sont_neutralises(): void
    {
        $session = $this->register();
        $headers = $this->bearer($session['token']);

        $this->call('POST', '/api/modules/module-1/items', ['title' => 'Marge 100%'], $headers);
        $this->call('POST', '/api/modules/module-1/items', ['title' => 'Sans pourcentage'], $headers);

        // « % » est un joker SQL : mal échappé, il ramènerait toute la liste.
        $reponse = $this->call('GET', '/api/modules/module-1/items', headers: $headers, query: ['search' => '100%']);

        $this->assertCount(1, $reponse['body']['data']);
    }

    #[Test]
    public function la_pagination_expose_son_total(): void
    {
        $session = $this->register();
        $headers = $this->bearer($session['token']);

        for ($i = 1; $i <= 5; $i++) {
            $this->call('POST', '/api/modules/module-1/items', ['title' => "Élément {$i}"], $headers);
        }

        $reponse = $this->call(
            'GET',
            '/api/modules/module-1/items',
            headers: $headers,
            query: ['per_page' => '2', 'page' => '1'],
        );

        $this->assertCount(2, $reponse['body']['data']);
        $this->assertSame(5, $reponse['body']['meta']['total']);
        $this->assertSame(3, $reponse['body']['meta']['total_pages']);
    }

    #[Test]
    public function un_identifiant_malforme_est_rejete_avant_la_base(): void
    {
        $session = $this->register();

        // La colonne est de type UUID : une valeur invalide y provoquerait une
        // erreur SQL brute au lieu d'une réponse propre.
        $reponse = $this->call('GET', '/api/items/pas-un-uuid', headers: $this->bearer($session['token']));

        $this->assertSame(422, $reponse['status']);
    }

    #[Test]
    public function un_module_non_attribue_est_introuvable(): void
    {
        $session = $this->register();

        $this->assertSame(
            404,
            $this->call('GET', '/api/modules/module-99/items', headers: $this->bearer($session['token']))['status'],
        );
    }

    #[Test]
    public function un_tri_non_autorise_ne_permet_pas_d_injection(): void
    {
        $session = $this->register();
        $headers = $this->bearer($session['token']);

        $this->call('POST', '/api/modules/module-1/items', ['title' => 'Une fiche'], $headers);

        // ORDER BY ne peut pas être paramétré : la valeur est comparée à une
        // liste blanche et toute autre valeur retombe sur le tri par défaut.
        $reponse = $this->call(
            'GET',
            '/api/modules/module-1/items',
            headers: $headers,
            query: ['sort' => 'title; DROP TABLE users; --'],
        );

        $this->assertSame(200, $reponse['status']);
        $this->assertCount(1, $reponse['body']['data']);

        // La table est toujours là.
        $this->assertSame(
            200,
            $this->call('GET', '/api/auth/me', headers: $headers)['status'],
        );
    }
}
