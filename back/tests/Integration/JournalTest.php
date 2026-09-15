<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Le journal, sur les cinq modules.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  UN MODULE SUR CINQ ÉTAIT LE PIRE DES ÉTATS                             │
 * │                                                                         │
 * │  Le tableau des tickets bougeait tout seul quand un coéquipier          │
 * │  travaillait ; l'écran des déploiements restait figé. Rien à l'écran    │
 * │  n'expliquait la différence, et personne ne pouvait deviner laquelle    │
 * │  des deux était la règle.                                               │
 * │                                                                         │
 * │  Ce fichier vérifie l'UNIFORMITÉ, pas la mécanique — celle-ci est déjà  │
 * │  couverte par CollaborationTest. La question posée ici est : est-ce que │
 * │  les cinq modules se comportent pareil ?                                │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
final class JournalTest extends ApiTestCase
{
    // =======================================================================
    //  Les cinq modules consignent
    // =======================================================================

    #[Test]
    public function chaque_module_consigne_ses_creations(): void
    {
        $session = $this->register('journal@test.local', name: 'Autrice');
        $entete  = $this->bearer($session['token']);

        $depart = $this->curseur($session);

        $this->call('POST', '/api/tickets', ['title' => 'Un ticket'], $entete);
        $this->call('POST', '/api/backend/tables', [
            'name'    => 'une_table',
            'columns' => [['name' => 'id', 'type' => 'uuid']],
        ], $entete);
        $this->call('POST', '/api/deployments', [
            'environment' => 'production',
            'branch'      => 'main',
            'commit_sha'  => 'abc1234',
        ], $entete);
        $this->call('POST', '/api/design/files', ['name' => 'Une maquette'], $entete);
        $this->call('POST', '/api/errors', [
            'fingerprint' => 'journal-erreur',
            'title'       => 'Une erreur',
            'message'     => 'Quelque chose a cassé',
        ], $entete);

        $modules = array_column($this->flux($session, $depart), 'module');

        sort($modules);

        // LES CINQ, et pas un de moins. Un module absent de cette liste
        // laisserait son écran figé pendant que les quatre autres bougent.
        $this->assertSame(
            ['backend', 'deploiement', 'design', 'supervision', 'tickets'],
            $modules,
        );
    }

    #[Test]
    public function chaque_entree_porte_de_quoi_se_lire_seule(): void
    {
        $session = $this->register('lisible@test.local', name: 'Autrice');
        $depart  = $this->curseur($session);

        $this->call(
            'POST',
            '/api/deployments',
            ['environment' => 'production', 'branch' => 'main', 'commit_sha' => 'deadbee', 'commit_message' => 'Corrige le panier'],
            $this->bearer($session['token']),
        );

        $entree = $this->flux($session, $depart)[0];

        // Le fil doit se lire SANS aller relire chaque objet : qui, quoi, sur
        // quoi. La référence d'un déploiement est « branche@empreinte », comme
        // à l'écran — c'est ce qu'on cherche du regard.
        $this->assertSame('Autrice', $entree['actor_name']);
        $this->assertSame('deploiement', $entree['module']);
        $this->assertSame('created', $entree['action']);
        $this->assertSame('main@deadbee', $entree['subject_ref']);
        $this->assertSame('Corrige le panier', $entree['subject_title']);
    }

    #[Test]
    public function une_modification_dit_ce_qui_a_change_et_rien_d_autre(): void
    {
        $session = $this->register('diff@test.local');
        $entete  = $this->bearer($session['token']);

        $fichier = $this->call('POST', '/api/design/files', ['name' => 'Maquette'], $entete);
        $depart  = $this->curseur($session);

        $this->call('PUT', '/api/design/files/' . $fichier['body']['data']['id'], [
            'name'        => 'Maquette',
            'description' => 'Une description',
        ], $entete);

        $entree = $this->flux($session, $depart)[0];

        // Le nom est renvoyé À L'IDENTIQUE : ce n'est pas un changement, et le
        // consigner ferait du bruit dans le fil de toute l'équipe.
        $this->assertSame(['description' => [null, 'Une description']], $entree['changes']);
    }

    #[Test]
    public function une_erreur_qui_se_repete_ne_consigne_que_sa_premiere_occurrence(): void
    {
        $session = $this->register('bruyante@test.local');
        $entete  = $this->bearer($session['token']);
        $depart  = $this->curseur($session);

        $erreur = [
            'fingerprint' => 'boucle-infinie',
            'title'       => 'La même, encore',
            'message'     => 'Encore',
        ];

        for ($i = 0; $i < 5; ++$i) {
            $this->call('POST', '/api/errors', $erreur, $entete);
        }

        // Une erreur de production se répète des centaines de fois par minute.
        // Consigner chaque occurrence noierait le fil de toute l'équipe sous
        // une seule panne — le compteur du groupe, lui, monte bien.
        $this->assertCount(1, $this->flux($session, $depart));

        $liste = $this->call('GET', '/api/errors', headers: $entete);
        $this->assertSame(5, $liste['body']['data'][0]['occurrences']);
    }

    #[Test]
    public function la_revocation_d_une_cle_laisse_une_trace(): void
    {
        $session = $this->register('cles@test.local');
        $entete  = $this->bearer($session['token']);

        $cle    = $this->call('POST', '/api/backend/keys', ['label' => 'Intégration'], $entete);
        $depart = $this->curseur($session);

        $this->call('DELETE', '/api/backend/keys/' . $cle['body']['data']['key']['id'], headers: $entete);

        $entree = $this->flux($session, $depart)[0];

        // Émission et révocation d'une clé sont des faits de SÉCURITÉ : ce sont
        // précisément ceux qu'on cherche dans un fil, longtemps après.
        $this->assertSame('key.revoked', $entree['action']);
    }

    // =======================================================================
    //  L'arbitrage vaut pour les cinq
    // =======================================================================

    #[Test]
    public function le_jeton_de_version_remonte_sur_les_cinq_modules(): void
    {
        $session = $this->register('versions@test.local');
        $entete  = $this->bearer($session['token']);

        $creations = [
            ['/api/tickets', ['title' => 'T']],
            ['/api/backend/tables', ['name' => 'table_v', 'columns' => [['name' => 'id', 'type' => 'uuid']]]],
            ['/api/deployments', ['environment' => 'preview', 'branch' => 'main', 'commit_sha' => 'abc1234']],
            ['/api/design/files', ['name' => 'F']],
            ['/api/errors', ['fingerprint' => 'version-test', 'title' => 'E', 'message' => 'm']],
        ];

        foreach ($creations as [$url, $payload]) {
            $reponse = $this->call('POST', $url, $payload, $entete);

            // Sans jeton renvoyé, un client ne PEUT PAS demander d'arbitrage :
            // il n'a rien à annoncer. La colonne existerait sans servir.
            $this->assertIsInt($reponse['body']['data']['version'] ?? null, "version absente de {$url}");
            $this->assertGreaterThanOrEqual(1, $reponse['body']['data']['version']);
        }
    }

    #[Test]
    public function une_erreur_bruyante_ne_fabrique_pas_de_faux_conflit(): void
    {
        $session = $this->register('faux-conflit@test.local');
        $entete  = $this->bearer($session['token']);

        $erreur = ['fingerprint' => 'tres-bruyante', 'title' => 'Répétée', 'message' => 'Encore'];

        $groupe = $this->call('POST', '/api/errors', $erreur, $entete)['body']['data'];

        // ┌───────────────────────────────────────────────────────────────────┐
        // │  LA VERSION D'UN GROUPE MONTE SANS QUE PERSONNE N'Y TOUCHE        │
        // │                                                                   │
        // │  Un déclencheur incrémente le compteur d'occurrences à chaque      │
        // │  erreur reçue, ce qui déclenche à son tour le jeton de version.    │
        // │  Une application en panne fait donc grimper cette version des      │
        // │  dizaines de fois par minute.                                      │
        // │                                                                   │
        // │  Si l'arbitrage se fondait sur la SEULE comparaison de versions,   │
        // │  l'écran de supervision deviendrait inutilisable au pire moment :  │
        // │  refuser de marquer « résolu » précisément pendant l'incident.     │
        // │                                                                   │
        // │  Il se fonde sur le JOURNAL — quels champs QUELQU'UN a changés —   │
        // │  et un compteur qui monte tout seul n'y écrit rien.                │
        // └───────────────────────────────────────────────────────────────────┘
        for ($i = 0; $i < 10; ++$i) {
            $this->call('POST', '/api/errors', $erreur, $entete);
        }

        $reponse = $this->call('PUT', '/api/errors/' . $groupe['id'], [
            'status'  => 'resolved',
            'version' => $groupe['version'],
        ], $entete);

        $this->assertSame(200, $reponse['status']);
        $this->assertSame('resolved', $reponse['body']['data']['status']);
    }

    #[Test]
    public function un_conflit_sur_un_fichier_de_design_nomme_son_champ(): void
    {
        $session = $this->register('conflit-design@test.local');
        $entete  = $this->bearer($session['token']);

        $fichier = $this->call('POST', '/api/design/files', ['name' => 'Disputée'], $entete)['body']['data'];

        // Un second compte, invité, pour que l'arbitrage se déclenche : on ne
        // s'arbitre jamais contre soi-même.
        $autre = $this->coequipier($session);

        $this->call('PUT', '/api/design/files/' . $fichier['id'], [
            'name'        => 'Disputée',
            'description' => 'La version de l\'autre',
            'version'     => $fichier['version'],
        ], $this->bearer($autre['token']));

        $reponse = $this->call('PUT', '/api/design/files/' . $fichier['id'], [
            'name'        => 'Disputée',
            'description' => 'La mienne',
            'version'     => $fichier['version'],
        ], $entete);

        $this->assertSame(409, $reponse['status']);
        $this->assertArrayHasKey('description', $reponse['body']['errors']);
        $this->assertStringContainsString('la description', $reponse['body']['errors']['description']);
        $this->assertSame('La version de l\'autre', $reponse['body']['meta']['current']['description']);
    }

    #[Test]
    public function deux_champs_differents_passent_sur_un_module_generique(): void
    {
        $session = $this->register('generique@test.local');
        $entete  = $this->bearer($session['token']);

        $item = $this->call(
            'POST',
            '/api/modules/' . $this->moduleSlug() . '/items',
            ['title' => 'Élément'],
            $entete,
        )['body']['data'];

        $autre = $this->coequipier($session);

        $this->call('PUT', '/api/items/' . $item['id'], [
            'status'  => 'active',
            'version' => $item['version'],
        ], $this->bearer($autre['token']));

        $reponse = $this->call('PUT', '/api/items/' . $item['id'], [
            'description' => 'Mon texte',
            'version'     => $item['version'],
        ], $entete);

        // La règle vaut aussi ici : une version périmée n'est pas un conflit
        // quand les champs ne se croisent pas.
        $this->assertSame(200, $reponse['status']);
        $this->assertSame('Mon texte', $reponse['body']['data']['description']);
        $this->assertSame('active', $reponse['body']['data']['status']);
    }

    // =======================================================================
    //  L'historique
    // =======================================================================

    #[Test]
    public function l_historique_se_filtre_par_module(): void
    {
        $session = $this->register('historique@test.local');
        $entete  = $this->bearer($session['token']);

        $this->call('POST', '/api/tickets', ['title' => 'Un ticket'], $entete);
        $this->call('POST', '/api/design/files', ['name' => 'Un fichier'], $entete);

        $tout    = $this->call('GET', '/api/activity', headers: $entete);
        $design  = $this->call('GET', '/api/activity', [], $entete, ['module' => 'design']);

        $this->assertGreaterThanOrEqual(2, count($tout['body']['data']));
        $this->assertCount(1, $design['body']['data']);
        $this->assertSame('Un fichier', $design['body']['data'][0]['subject_title']);
    }

    #[Test]
    public function l_historique_pagine_par_cle_et_annonce_sa_suite(): void
    {
        $session = $this->register('pagination@test.local');
        $entete  = $this->bearer($session['token']);

        for ($i = 1; $i <= 5; ++$i) {
            $this->call('POST', '/api/tickets', ['title' => "Ticket {$i}"], $entete);
        }

        $premiere = $this->call('GET', '/api/activity', [], $entete, ['module' => 'tickets']);

        // La page complète ne dit rien de plus qu'elle-même : c'est « next »
        // qui annonce une suite, sans avoir à compter la table entière.
        $this->assertNull($premiere['body']['meta']['next']);

        // Avec une borne, la suite devient visible et le curseur apparaît.
        $tronquee = $this->call('GET', '/api/activity', [], $entete, [
            'module' => 'tickets',
            'avant'  => (string) $premiere['body']['data'][1]['id'],
        ]);

        $this->assertCount(3, $tronquee['body']['data']);

        // Aucune entrée en double entre les deux pages : c'est précisément ce
        // qu'un OFFSET ne peut pas garantir quand la table grossit par le haut.
        $identifiants = array_merge(
            array_column(array_slice($premiere['body']['data'], 0, 2), 'id'),
            array_column($tronquee['body']['data'], 'id'),
        );

        $this->assertSame($identifiants, array_unique($identifiants));
    }

    #[Test]
    public function l_historique_ne_traverse_pas_les_espaces(): void
    {
        $mien   = $this->register('mien-hist@test.local');
        $autrui = $this->register('autrui-hist@test.local');

        $this->call('POST', '/api/tickets', ['title' => 'Chez moi'], $this->bearer($mien['token']));

        $vue = $this->call('GET', '/api/activity', headers: $this->bearer($autrui['token']));

        $this->assertSame([], $vue['body']['data']);
    }

    #[Test]
    public function le_tableau_de_bord_lit_le_journal_et_voit_les_modifications(): void
    {
        $session = $this->register('bord@test.local', name: 'Autrice');
        $entete  = $this->bearer($session['token']);

        $ticket = $this->call('POST', '/api/tickets', ['title' => 'Suivi'], $entete)['body']['data'];

        $this->call('PUT', '/api/tickets/' . $ticket['id'], ['priority' => 'urgent'], $entete);

        $bord = $this->call('GET', '/api/dashboard', headers: $entete);
        $fil  = $bord['body']['data']['recent'];

        // L'UNION des cinq tables ne pouvait montrer que des CRÉATIONS : une
        // table de données ne garde aucune trace de ce qui l'a modifiée, ni de
        // qui. C'est la différence que ce test mesure.
        $actions = array_column($fil, 'action');

        $this->assertContains('updated', $actions);
        $this->assertSame('Autrice', $fil[0]['actor_name']);
    }

    // =======================================================================

    /**
     * Le curseur de tête, avant d'agir.
     *
     * Sans lui, le flux renverrait tout l'historique de l'espace et les
     * assertions porteraient sur des faits antérieurs au test.
     *
     * @param array<string, mixed> $session
     */
    private function curseur(array $session): int
    {
        $reponse = $this->call('GET', '/api/stream', [], $this->bearer($session['token']));

        return (int) $reponse['body']['meta']['cursor'];
    }

    /**
     * Les événements survenus depuis un curseur.
     *
     * @param  array<string, mixed> $session
     * @return list<array<string, mixed>>
     */
    private function flux(array $session, int $depuis): array
    {
        $reponse = $this->call(
            'GET',
            '/api/stream',
            [],
            $this->bearer($session['token']),
            ['depuis' => (string) $depuis],
        );

        return $reponse['body']['data'];
    }

    /**
     * Un second compte dans le même espace, par le parcours d'invitation.
     *
     * @param  array<string, mixed> $hote
     * @return array<string, mixed>
     */
    private function coequipier(array $hote): array
    {
        $adresse = 'coequipier-' . bin2hex(random_bytes(4)) . '@test.local';

        $this->call(
            'POST',
            '/api/organizations/invitations',
            ['email' => $adresse],
            $this->bearer($hote['token']),
        );

        $statement = \App\Core\Database::connection()->prepare(
            "SELECT payload FROM jobs
              WHERE type = 'mail.send' AND payload->>'to' = :email
           ORDER BY created_at DESC LIMIT 1",
        );
        $statement->execute(['email' => $adresse]);

        $payload = json_decode((string) $statement->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($payload, 'aucune invitation déposée en file');

        preg_match('/token=([a-f0-9]{64})/', (string) $payload['text'], $trouve);

        $invite = $this->register($adresse, name: 'Coéquipier');

        $this->call('POST', "/api/invitations/{$trouve[1]}/accept", headers: $this->bearer($invite['token']));

        return $invite;
    }
}
