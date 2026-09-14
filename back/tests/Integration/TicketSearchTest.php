<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * La recherche de tickets, côté serveur.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  L'ÉCRAN PROMETTAIT UN CHEMIN QUI N'EXISTAIT PAS                        │
 * │                                                                         │
 * │  Au-delà de 500 tickets, l'avertissement conseillait : « Cherchez par   │
 * │  numéro, par projet ou par étiquette pour atteindre le reste. » Or la   │
 * │  recherche de l'écran est LOCALE — elle ne voit jamais au-delà de ce    │
 * │  qui est chargé —, et celle du serveur ne regardait que le titre et la  │
 * │  description. Ni numéro, ni projet, ni étiquette : le conseil menait    │
 * │  à une impasse, en toutes lettres.                                      │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Le serveur cherche donc désormais EXACTEMENT là où l'écran cherche. Une
 * divergence entre les deux ferait trouver un ticket tant que la liste tient
 * sous le plafond, puis plus du tout le jour où elle le dépasse — sans que
 * rien, à l'écran, n'explique la différence.
 */
final class TicketSearchTest extends ApiTestCase
{
    #[Test]
    public function le_serveur_cherche_la_ou_l_ecran_cherche(): void
    {
        $token = $this->register()['token'];

        $panier   = $this->creer($token, ['title' => 'Panier vide au rechargement']);
        $facture  = $this->creer($token, ['title' => 'Relance', 'description' => 'La facture PDF est tronquée']);
        $securite = $this->creer($token, ['title' => 'Jetons', 'project' => 'Sécurité']);
        $client   = $this->creer($token, ['title' => 'Export', 'labels' => ['urgent-client']]);

        $this->assertSame([$panier], $this->chercher($token, 'panier'), 'le titre');
        $this->assertSame([$facture], $this->chercher($token, 'FACTURE'), 'la description, sans égard à la casse');
        $this->assertSame([$securite], $this->chercher($token, 'sécu'), 'le projet');
        $this->assertSame([$client], $this->chercher($token, 'urgent'), 'une étiquette');

        // « 3 » trouve le ticket n° 3 : c'est ce que fait l'écran, qui cherche
        // le numéro comme du texte. Aucun titre ci-dessus ne contient de chiffre.
        $this->assertSame([3], $this->chercher($token, '3'), 'le numéro');
    }

    /**
     * « % » et « _ » sont des jokers en SQL. Tapés dans une recherche, ils
     * doivent redevenir des caractères — sans quoi « % » renverrait tous les
     * tickets en prétendant filtrer.
     */
    #[Test]
    public function les_jokers_sql_se_cherchent_comme_des_caracteres(): void
    {
        $token = $this->register()['token'];

        $remise = $this->creer($token, ['title' => 'Remise de 10 % refusée']);
        $this->creer($token, ['title' => 'Remise de 10 euros']);
        $code = $this->creer($token, ['title' => 'nom_de_code']);

        $this->assertSame([$remise], $this->chercher($token, '%'));
        $this->assertSame([$code], $this->chercher($token, '_'));
    }

    /**
     * LE CAS QUI JUSTIFIE LE RESTE : un ticket que le plafond ne charge pas.
     */
    #[Test]
    public function la_recherche_atteint_ce_que_le_plafond_ne_charge_pas(): void
    {
        $session = $this->register();

        // 505 tickets d'un coup, directement en base : la même numérotation par
        // déclencheur qu'un passage par l'API, sans cinq cents requêtes. Le
        // ticket cherché est le PLUS ANCIEN, donc le dernier d'une liste triée
        // du plus récent au plus ancien — au-delà du plafond.
        Database::connection()->prepare(
            "INSERT INTO tickets (organization_id, created_by, title, created_at)
             SELECT :org, :user,
                    CASE WHEN n = 505 THEN 'Ticket introuvable autrement' ELSE 'Ticket ordinaire ' || n END,
                    NOW() - (n || ' seconds')::interval
               FROM generate_series(1, 505) AS n",
        )->execute(['org' => $session['org'], 'user' => $session['id']]);

        $liste = $this->call('GET', '/api/tickets', [], $this->bearer($session['token']));

        $this->assertCount(500, $liste['body']['data']);
        $this->assertSame(505, $liste['body']['meta']['total']);
        $this->assertNotContains(
            'Ticket introuvable autrement',
            array_column($liste['body']['data'], 'title'),
            'la prémisse : sans recherche, le ticket reste au-delà du plafond',
        );

        $trouve = $this->call('GET', '/api/tickets', [], $this->bearer($session['token']), ['search' => 'introuvable']);

        $this->assertSame(['Ticket introuvable autrement'], array_column($trouve['body']['data'], 'title'));
        $this->assertSame(1, $trouve['body']['meta']['total']);
    }

    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $ticket
     */
    private function creer(string $token, array $ticket): int
    {
        $reponse = $this->call('POST', '/api/tickets', $ticket, $this->bearer($token));

        $this->assertSame(201, $reponse['status'], 'création de ticket refusée');

        return (int) $reponse['body']['data']['number'];
    }

    /**
     * @return list<int> Les numéros trouvés, dans l'ordre croissant
     */
    private function chercher(string $token, string $terme): array
    {
        $reponse = $this->call('GET', '/api/tickets', [], $this->bearer($token), ['search' => $terme]);

        $this->assertSame(200, $reponse['status']);

        $numeros = array_map('intval', array_column($reponse['body']['data'], 'number'));
        sort($numeros);

        return $numeros;
    }
}
