<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Annulation d'une suppression.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  LA DONNÉE ÉTAIT LÀ DEPUIS LE DÉBUT                                     │
 * │                                                                         │
 * │  Toutes les suppressions de l'application sont LOGIQUES : la ligne      │
 * │  reste en base, marquée d'un « deleted_at ». Rien n'a jamais été perdu  │
 * │  — et pourtant, du point de vue de celui qui venait de cliquer, c'était │
 * │  définitif. Il ne manquait que le chemin de retour.                     │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Cinq modules, cinq routes de restauration. Ce qui se vérifie ici : qu'elles
 * ramènent bien la ressource dans les listes, qu'elles refusent celles d'un
 * autre compte, et qu'elles ne créent pas de doublon quand on insiste.
 */
final class RestoreTest extends ApiTestCase
{
    /**
     * Crée un ticket et renvoie son identifiant.
     *
     * @param array{token: string} $user
     */
    private function ticket(array $user, string $titre = 'À restaurer'): string
    {
        $reponse = $this->call(
            'POST',
            '/api/tickets',
            ['title' => $titre],
            headers: $this->bearer($user['token']),
        );

        $this->assertSame(201, $reponse['status']);

        return $reponse['body']['data']['id'];
    }

    #[Test]
    public function un_ticket_supprime_revient_dans_la_liste(): void
    {
        $user = $this->register();
        $id   = $this->ticket($user);

        $this->call('DELETE', "/api/tickets/{$id}", headers: $this->bearer($user['token']));

        $apres = $this->call('GET', '/api/tickets', headers: $this->bearer($user['token']));
        $this->assertCount(0, $apres['body']['data']);

        $restaure = $this->call(
            'POST',
            "/api/tickets/{$id}/restore",
            headers: $this->bearer($user['token']),
        );

        $this->assertSame(200, $restaure['status']);

        // La ressource est RENVOYÉE, pas seulement acquittée : l'écran qui
        // annule veut la remettre en place sans second aller-retour.
        $this->assertSame($id, $restaure['body']['data']['id']);
        $this->assertSame('À restaurer', $restaure['body']['data']['title']);

        $liste = $this->call('GET', '/api/tickets', headers: $this->bearer($user['token']));
        $this->assertCount(1, $liste['body']['data']);
    }

    #[Test]
    public function un_ticket_restaure_garde_son_numero(): void
    {
        $user     = $this->register();
        $premier  = $this->ticket($user, 'Premier');
        $deuxieme = $this->ticket($user, 'Deuxième');

        $numero = $this->call('GET', "/api/tickets/{$premier}", headers: $this->bearer($user['token']))
            ['body']['data']['number'];

        $this->call('DELETE', "/api/tickets/{$premier}", headers: $this->bearer($user['token']));

        // Un troisième ticket est créé PENDANT que le premier est supprimé :
        // c'est le moment où un compteur mal conçu réattribuerait le numéro.
        $this->ticket($user, 'Troisième');

        $restaure = $this->call(
            'POST',
            "/api/tickets/{$premier}/restore",
            headers: $this->bearer($user['token']),
        );

        // Le numéro n'est jamais réattribué : c'est un compteur par compte,
        // pas un rang. Le ticket restauré retrouve exactement le sien.
        $this->assertSame($numero, $restaure['body']['data']['number']);
        $this->assertNotSame($deuxieme, $restaure['body']['data']['id']);
    }

    #[Test]
    public function restaurer_deux_fois_ne_cree_pas_de_doublon(): void
    {
        $user = $this->register();
        $id   = $this->ticket($user);

        $this->call('DELETE', "/api/tickets/{$id}", headers: $this->bearer($user['token']));
        $this->call('POST', "/api/tickets/{$id}/restore", headers: $this->bearer($user['token']));

        // Un double clic sur « Annuler », ou un bandeau resté à l'écran. La
        // seconde tentative ne trouve plus rien à restaurer : 404, et surtout
        // pas une seconde ligne.
        $rejeu = $this->call(
            'POST',
            "/api/tickets/{$id}/restore",
            headers: $this->bearer($user['token']),
        );

        $this->assertSame(404, $rejeu['status']);

        $liste = $this->call('GET', '/api/tickets', headers: $this->bearer($user['token']));
        $this->assertCount(1, $liste['body']['data']);
    }

    #[Test]
    public function on_ne_restaure_pas_ce_qu_on_a_pas_supprime(): void
    {
        $victime = $this->register('victime@test.local');
        $id      = $this->ticket($victime);

        $this->call('DELETE', "/api/tickets/{$id}", headers: $this->bearer($victime['token']));

        $attaquant = $this->register('attaquant@test.local');

        $reponse = $this->call(
            'POST',
            "/api/tickets/{$id}/restore",
            headers: $this->bearer($attaquant['token']),
        );

        // 404 et non 403 : distinguer « n'existe pas » de « ne vous appartient
        // pas » confirmerait à un attaquant quels identifiants sont réels.
        $this->assertSame(404, $reponse['status']);

        // Et le ticket de la victime est toujours supprimé — donc restaurable
        // par elle seule.
        $sien = $this->call(
            'POST',
            "/api/tickets/{$id}/restore",
            headers: $this->bearer($victime['token']),
        );

        $this->assertSame(200, $sien['status']);
    }

    #[Test]
    public function un_fichier_de_design_revient_avec_tout_son_historique(): void
    {
        $user = $this->register();

        $fichier = $this->call(
            'POST',
            '/api/design/files',
            ['name' => 'Écrans publics', 'kind' => 'maquette'],
            headers: $this->bearer($user['token']),
        )['body']['data'];

        $this->call(
            'POST',
            "/api/design/files/{$fichier['id']}/versions",
            ['label' => 'Passe typographique'],
            headers: $this->bearer($user['token']),
        );

        $this->call(
            'DELETE',
            "/api/design/files/{$fichier['id']}",
            headers: $this->bearer($user['token']),
        );
        $this->call(
            'POST',
            "/api/design/files/{$fichier['id']}/restore",
            headers: $this->bearer($user['token']),
        );

        $revenu = $this->call(
            'GET',
            "/api/design/files/{$fichier['id']}",
            headers: $this->bearer($user['token']),
        );

        // Les versions ne sont jamais supprimées : elles sont masquées par
        // l'état du FICHIER. Un historique de deux versions restauré est donc
        // exactement le même qu'avant.
        $this->assertSame(200, $revenu['status']);

        // « versions » est un COMPTE, « history » la liste : la version initiale
        // posée à la création, plus celle ajoutée ici.
        $this->assertSame(2, $revenu['body']['data']['versions']);
        $this->assertCount(2, $revenu['body']['data']['history']);
    }

    #[Test]
    public function les_cinq_modules_savent_restaurer(): void
    {
        $user = $this->register();
        $jeton = $this->bearer($user['token']);

        // Un élément par module, chacun par son propre chemin de création.
        $cibles = [
            '/api/tickets/%s/restore' => $this->ticket($user),

            '/api/design/files/%s/restore' => $this->call(
                'POST',
                '/api/design/files',
                ['name' => 'Maquette', 'kind' => 'maquette'],
                headers: $jeton,
            )['body']['data']['id'],

            '/api/deployments/%s/restore' => $this->call(
                'POST',
                '/api/deployments',
                ['branch' => 'main', 'commit_sha' => 'a3f9c1d', 'environment' => 'production'],
                headers: $jeton,
            )['body']['data']['id'],

            '/api/errors/%s/restore' => $this->call(
                'POST',
                '/api/errors',
                [
                    'fingerprint' => 'abc123def456',
                    'title'       => 'TypeError',
                    'message'     => 'Cannot read properties of undefined',
                    'level'       => 'error',
                ],
                headers: $jeton,
            )['body']['data']['id'],

            '/api/items/%s/restore' => $this->call(
                'POST',
                '/api/modules/backend/items',
                ['title' => 'Élément'],
                headers: $jeton,
            )['body']['data']['id'],
        ];

        foreach ($cibles as $gabarit => $id) {
            $suppression = str_replace('/restore', '', sprintf($gabarit, $id));

            $this->call('DELETE', $suppression, headers: $jeton);

            $reponse = $this->call('POST', sprintf($gabarit, $id), headers: $jeton);

            $this->assertSame(200, $reponse['status'], $gabarit);
            $this->assertSame($id, $reponse['body']['data']['id'], $gabarit);
        }
    }

    #[Test]
    public function la_restauration_exige_une_authentification(): void
    {
        $user = $this->register();
        $id   = $this->ticket($user);

        $this->call('DELETE', "/api/tickets/{$id}", headers: $this->bearer($user['token']));

        $this->assertSame(401, $this->call('POST', "/api/tickets/{$id}/restore")['status']);
    }

    #[Test]
    public function aucune_limite_de_temps_cote_serveur(): void
    {
        $user = $this->register();
        $id   = $this->ticket($user);

        $this->call('DELETE', "/api/tickets/{$id}", headers: $this->bearer($user['token']));

        // Supprimé il y a six mois. L'INTERFACE propose l'annulation pendant
        // huit secondes ; le serveur, lui, n'a aucune raison de refuser plus
        // tard ce qu'il accepte tout de suite.
        Database::connection()
            ->prepare("UPDATE tickets SET deleted_at = NOW() - interval '6 months' WHERE id = :id")
            ->execute(['id' => $id]);

        $reponse = $this->call(
            'POST',
            "/api/tickets/{$id}/restore",
            headers: $this->bearer($user['token']),
        );

        $this->assertSame(200, $reponse['status']);
    }
}
