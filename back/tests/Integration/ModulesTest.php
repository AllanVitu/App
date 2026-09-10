<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Modules Backend, Déploiement, Supervision et Design.
 *
 * Ces tests portent d'abord sur ce que l'APPLICATION NE FAIT PAS elle-même :
 * numérotation des versions, dates de fin, agrégats d'erreurs sont posés par
 * des triggers PostgreSQL. Une suite qui ne les exercerait pas laisserait la
 * partie la plus subtile de ces modules sans filet.
 *
 * Le cloisonnement entre comptes est vérifié pour chacun : c'est la garantie
 * dont tout le reste dépend.
 */
final class ModulesTest extends ApiTestCase
{
    // =====================================================================
    //  Backend — schémas et clés d'API
    // =====================================================================

    #[Test]
    public function une_table_refuse_un_nom_qui_n_est_pas_un_identifiant_sql(): void
    {
        $session = $this->register();

        foreach (['Ma Table', '2commandes', 'clients-actifs', 'accentué'] as $invalide) {
            $reponse = $this->call(
                'POST',
                '/api/backend/tables',
                ['name' => $invalide],
                $this->bearer($session['token']),
            );

            $this->assertSame(422, $reponse['status'], "« {$invalide} » aurait dû être refusé");
            $this->assertArrayHasKey('name', $reponse['body']['errors']);
        }

        $valide = $this->call(
            'POST',
            '/api/backend/tables',
            ['name' => 'commandes_2024'],
            $this->bearer($session['token']),
        );

        $this->assertSame(201, $valide['status']);
    }

    #[Test]
    public function une_colonne_de_type_inconnu_est_refusee(): void
    {
        $session = $this->register();

        $reponse = $this->call(
            'POST',
            '/api/backend/tables',
            [
                'name'    => 'produits',
                'columns' => [['name' => 'prix', 'type' => 'money']],
            ],
            $this->bearer($session['token']),
        );

        // Un JSONB accepte n'importe quoi : sans validation, un type
        // inexistant rendrait le schéma inexploitable sans que rien ne le
        // signale.
        $this->assertSame(422, $reponse['status']);
        $this->assertArrayHasKey('columns', $reponse['body']['errors']);
    }

    #[Test]
    public function deux_colonnes_de_meme_nom_sont_refusees(): void
    {
        $session = $this->register();

        $reponse = $this->call(
            'POST',
            '/api/backend/tables',
            [
                'name'    => 'clients',
                'columns' => [
                    ['name' => 'email', 'type' => 'text'],
                    ['name' => 'email', 'type' => 'varchar'],
                ],
            ],
            $this->bearer($session['token']),
        );

        // La base ne peut pas l'interdire à l'INTÉRIEUR d'un JSONB : c'est
        // donc au contrôleur de le faire.
        $this->assertSame(422, $reponse['status']);
    }

    #[Test]
    public function une_cle_d_api_n_est_jamais_stockee_en_clair(): void
    {
        $session = $this->register();

        $reponse = $this->call(
            'POST',
            '/api/backend/keys',
            ['label' => 'Client web', 'scope' => 'service'],
            $this->bearer($session['token']),
        );

        $this->assertSame(201, $reponse['status']);

        $token = $reponse['body']['data']['token'];
        $this->assertNotEmpty($token);

        // La base ne contient QUE l'empreinte. Une fuite ne livre donc aucune
        // clé utilisable.
        $statement = Database::connection()->prepare(
            'SELECT token_hash, token_prefix FROM backend_api_keys WHERE organization_id = :id',
        );
        $statement->execute(['id' => $session['org']]);
        $row = $statement->fetch();

        $this->assertSame(hash('sha256', $token), $row['token_hash']);
        $this->assertNotSame($token, $row['token_hash']);
        $this->assertStringStartsWith('sk_', $row['token_prefix'], 'une clé de service porte son préfixe');

        // Et la clé en clair ne ressort d'AUCUNE lecture ultérieure.
        $liste = $this->call('GET', '/api/backend/tables', headers: $this->bearer($session['token']));
        $this->assertStringNotContainsString($token, json_encode($liste['body'], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function une_cle_revoquee_ne_se_revoque_pas_deux_fois(): void
    {
        $session = $this->register();

        $cle = $this->call(
            'POST',
            '/api/backend/keys',
            ['label' => 'Temporaire'],
            $this->bearer($session['token']),
        )['body']['data']['key'];

        $this->assertSame(
            204,
            $this->call('DELETE', "/api/backend/keys/{$cle['id']}", headers: $this->bearer($session['token']))['status'],
        );

        // 404 : il n'y a plus rien à révoquer. Renvoyer 204 laisserait croire
        // qu'une seconde révocation a eu un effet.
        $this->assertSame(
            404,
            $this->call('DELETE', "/api/backend/keys/{$cle['id']}", headers: $this->bearer($session['token']))['status'],
        );
    }

    // =====================================================================
    //  Déploiement
    // =====================================================================

    #[Test]
    public function la_date_de_fin_et_la_duree_suivent_le_statut(): void
    {
        $session = $this->register();

        $deploiement = $this->call(
            'POST',
            '/api/deployments',
            ['branch' => 'main', 'commit_sha' => 'a3f9c1d', 'status' => 'building'],
            $this->bearer($session['token']),
        )['body']['data'];

        $this->assertNull($deploiement['finished_at'], 'un déploiement en cours n\'a pas de fin');
        $this->assertNull($deploiement['duration_ms']);

        $termine = $this->call(
            'PUT',
            "/api/deployments/{$deploiement['id']}",
            ['status' => 'ready'],
            $this->bearer($session['token']),
        )['body']['data'];

        $this->assertNotNull($termine['finished_at']);
        $this->assertIsInt($termine['duration_ms']);
        $this->assertGreaterThanOrEqual(0, $termine['duration_ms']);

        // Relance : sans effacement, un déploiement relancé afficherait la
        // durée de sa tentative précédente — pire qu'une durée absente.
        $relance = $this->call(
            'PUT',
            "/api/deployments/{$deploiement['id']}",
            ['status' => 'queued'],
            $this->bearer($session['token']),
        )['body']['data'];

        $this->assertNull($relance['finished_at'], 'relancer efface la date de fin');
        $this->assertNull($relance['duration_ms'], 'relancer efface la durée');
    }

    #[Test]
    public function une_empreinte_de_commit_invalide_est_refusee(): void
    {
        $session = $this->register();

        foreach (['xyz', 'ZZZZZZZ', 'a3f9c1'] as $invalide) {
            $reponse = $this->call(
                'POST',
                '/api/deployments',
                ['branch' => 'main', 'commit_sha' => $invalide],
                $this->bearer($session['token']),
            );

            $this->assertSame(422, $reponse['status'], "« {$invalide} » aurait dû être refusé");
        }
    }

    // =====================================================================
    //  Supervision
    // =====================================================================

    #[Test]
    public function deux_occurrences_de_meme_empreinte_forment_un_seul_groupe(): void
    {
        $session = $this->register();

        $occurrence = [
            'fingerprint' => 'a1f0c3d29b47',
            'title'       => 'TypeError: undefined',
            'culprit'     => 'app.js:118',
            'level'       => 'error',
            'message'     => 'boum',
        ];

        $premier = $this->call('POST', '/api/errors', $occurrence, $this->bearer($session['token']));
        $second  = $this->call('POST', '/api/errors', $occurrence, $this->bearer($session['token']));

        $this->assertSame(201, $premier['status']);
        $this->assertSame(201, $second['status']);

        // Le regroupement EST l'intérêt du module : mille fois la même
        // exception est un seul problème à corriger.
        $this->assertSame($premier['body']['data']['id'], $second['body']['data']['id']);
        $this->assertSame(2, $second['body']['data']['occurrences'], 'compteur entretenu par trigger');

        $liste = $this->call('GET', '/api/errors', headers: $this->bearer($session['token']));
        $this->assertCount(1, $liste['body']['data']);
    }

    #[Test]
    public function une_erreur_resolue_qui_se_reproduit_est_rouverte(): void
    {
        $session = $this->register();

        $occurrence = [
            'fingerprint' => 'b7e21d84a0c9',
            'title'       => 'PDOException',
            'message'     => 'colonne inconnue',
        ];

        $groupe = $this->call('POST', '/api/errors', $occurrence, $this->bearer($session['token']))['body']['data'];

        $resolu = $this->call(
            'PUT',
            "/api/errors/{$groupe['id']}",
            ['status' => 'resolved'],
            $this->bearer($session['token']),
        )['body']['data'];

        $this->assertSame('resolved', $resolu['status']);

        // Laisser « résolu » sur un problème qui frappe encore est le pire des
        // mensonges pour un outil de supervision.
        $rouvert = $this->call('POST', '/api/errors', $occurrence, $this->bearer($session['token']))['body']['data'];

        $this->assertSame('unresolved', $rouvert['status']);
    }

    #[Test]
    public function une_erreur_ignoree_le_reste_malgre_les_occurrences(): void
    {
        $session = $this->register();

        $occurrence = ['fingerprint' => 'c39a5f7e1b02', 'title' => 'Warning', 'message' => 'bruit'];

        $groupe = $this->call('POST', '/api/errors', $occurrence, $this->bearer($session['token']))['body']['data'];

        $this->call(
            'PUT',
            "/api/errors/{$groupe['id']}",
            ['status' => 'ignored'],
            $this->bearer($session['token']),
        );

        $encore = $this->call('POST', '/api/errors', $occurrence, $this->bearer($session['token']))['body']['data'];

        // « Ignoré » est une décision explicite de ne plus vouloir en entendre
        // parler : contrairement à « résolu », elle ne se révoque pas toute
        // seule.
        $this->assertSame('ignored', $encore['status']);
    }

    #[Test]
    public function la_courbe_comprend_les_jours_sans_erreur(): void
    {
        $session = $this->register();

        $this->call(
            'POST',
            '/api/errors',
            ['fingerprint' => 'd02b6c1a8f35', 'title' => 'Erreur', 'message' => 'une fois'],
            $this->bearer($session['token']),
        );

        $daily = $this->call('GET', '/api/errors', headers: $this->bearer($session['token']))['body']['meta']['daily'];

        // 14 points, jours calmes compris : sans eux, la courbe relierait deux
        // pics en sautant les jours vides et donnerait l'impression d'un
        // problème continu.
        $this->assertCount(14, $daily);
        $this->assertSame(0, $daily[0]['count'], 'le premier jour de la fenêtre est vide');
        $this->assertSame(1, $daily[13]['count'], 'l\'occurrence du jour est comptée');
    }

    // =====================================================================
    //  Design
    // =====================================================================

    #[Test]
    public function un_fichier_nait_avec_sa_premiere_version(): void
    {
        $session = $this->register();

        $fichier = $this->call(
            'POST',
            '/api/design/files',
            ['name' => 'Poste de travail', 'kind' => 'maquette'],
            $this->bearer($session['token']),
        )['body']['data'];

        // Un fichier de design sans aucune version serait un objet vide : il
        // ne documenterait rien.
        $this->assertSame(1, $fichier['versions']);
        $this->assertCount(1, $fichier['history']);
        $this->assertSame(1, $fichier['history'][0]['number']);
    }

    #[Test]
    public function la_numerotation_des_versions_repart_de_un_pour_chaque_fichier(): void
    {
        $session = $this->register();

        $premier = $this->call(
            'POST',
            '/api/design/files',
            ['name' => 'Fichier A'],
            $this->bearer($session['token']),
        )['body']['data'];

        $second = $this->call(
            'POST',
            '/api/design/files',
            ['name' => 'Fichier B'],
            $this->bearer($session['token']),
        )['body']['data'];

        $v2 = $this->call(
            'POST',
            "/api/design/files/{$premier['id']}/versions",
            ['label' => 'Passe typographique'],
            $this->bearer($session['token']),
        )['body']['data'];

        $this->assertSame(2, $v2['number']);

        // « v3 » n'a de sens que rapporté au fichier auquel il appartient :
        // le compteur du second fichier ne doit rien hériter du premier.
        $recharge = $this->call(
            'GET',
            "/api/design/files/{$second['id']}",
            headers: $this->bearer($session['token']),
        )['body']['data'];

        $this->assertSame(1, $recharge['versions']);
        $this->assertSame(1, $recharge['history'][0]['number']);
    }

    #[Test]
    public function une_couleur_hors_format_hexadecimal_est_refusee(): void
    {
        $session = $this->register();

        $reponse = $this->call(
            'POST',
            '/api/design/files',
            ['name' => 'Essai', 'accent' => 'rouge'],
            $this->bearer($session['token']),
        );

        $this->assertSame(422, $reponse['status']);
        $this->assertArrayHasKey('accent', $reponse['body']['errors']);
    }

    // =====================================================================
    //  Cloisonnement — la garantie dont tout le reste dépend
    // =====================================================================

    #[Test]
    public function un_compte_ne_voit_jamais_les_donnees_d_un_autre(): void
    {
        $alice = $this->register('alice@test.local');
        $bob = $this->register('bob@test.local');

        $table = $this->call(
            'POST',
            '/api/backend/tables',
            ['name' => 'secrets'],
            $this->bearer($alice['token']),
        )['body']['data'];

        $deploiement = $this->call(
            'POST',
            '/api/deployments',
            ['branch' => 'main', 'commit_sha' => 'a3f9c1d'],
            $this->bearer($alice['token']),
        )['body']['data'];

        $erreur = $this->call(
            'POST',
            '/api/errors',
            ['fingerprint' => 'e1f2a3b4c5d6', 'title' => 'Fuite', 'message' => 'privé'],
            $this->bearer($alice['token']),
        )['body']['data'];

        $fichier = $this->call(
            'POST',
            '/api/design/files',
            ['name' => 'Confidentiel'],
            $this->bearer($alice['token']),
        )['body']['data'];

        // 404 et non 403 : Bob ne doit pas même apprendre que ces ressources
        // existent.
        $cibles = [
            "/api/backend/tables/{$table['id']}",
            "/api/deployments/{$deploiement['id']}",
            "/api/errors/{$erreur['id']}",
            "/api/design/files/{$fichier['id']}",
        ];

        foreach ($cibles as $url) {
            $this->assertSame(
                404,
                $this->call('GET', $url, headers: $this->bearer($bob['token']))['status'],
                "lecture de {$url} par un autre compte",
            );
            $this->assertSame(
                404,
                $this->call('DELETE', $url, headers: $this->bearer($bob['token']))['status'],
                "suppression de {$url} par un autre compte",
            );
        }

        // Le cloisonnement vaut aussi en lecture de masse, pas seulement par
        // identifiant.
        foreach (['/api/backend/tables', '/api/deployments', '/api/errors', '/api/design/files'] as $liste) {
            $this->assertSame(
                [],
                $this->call('GET', $liste, headers: $this->bearer($bob['token']))['body']['data'],
                "listing {$liste} pour un autre compte",
            );
        }
    }

    #[Test]
    public function le_tableau_de_bord_hierarchise_les_alertes_de_tous_les_modules(): void
    {
        $session = $this->register();

        // Un ticket urgent : une INTENTION.
        $this->call(
            'POST',
            '/api/tickets',
            ['title' => 'Urgent déclaré', 'priority' => 'urgent'],
            $this->bearer($session['token']),
        );

        // Une erreur fatale : un FAIT.
        $this->call(
            'POST',
            '/api/errors',
            ['fingerprint' => 'f0e1d2c3b4a5', 'title' => 'Exception fatale', 'level' => 'fatal', 'message' => 'x'],
            $this->bearer($session['token']),
        );

        // Une production cassée : le fait le plus grave.
        $deploiement = $this->call(
            'POST',
            '/api/deployments',
            ['branch' => 'main', 'commit_sha' => 'a3f9c1d', 'environment' => 'production'],
            $this->bearer($session['token']),
        )['body']['data'];

        $this->call(
            'PUT',
            "/api/deployments/{$deploiement['id']}",
            ['status' => 'error'],
            $this->bearer($session['token']),
        );

        $attention = $this->call('GET', '/api/dashboard', headers: $this->bearer($session['token']))
            ['body']['data']['attention'];

        $this->assertCount(3, $attention);

        // Les faits avant l'intention, et le plus grave des faits en tête.
        $this->assertSame('failed', $attention[0]['reason']);
        $this->assertSame('deploiement', $attention[0]['module']);
        $this->assertSame('fatal', $attention[1]['reason']);
        $this->assertSame('urgent', $attention[2]['reason']);
    }

    #[Test]
    public function le_fil_d_activite_traverse_tous_les_modules(): void
    {
        $session = $this->register();

        $this->call('POST', '/api/backend/tables', ['name' => 'clients'], $this->bearer($session['token']));
        $this->call('POST', '/api/design/files', ['name' => 'Maquette'], $this->bearer($session['token']));
        $this->call('POST', '/api/tickets', ['title' => 'Un ticket'], $this->bearer($session['token']));

        $recent = $this->call('GET', '/api/dashboard', headers: $this->bearer($session['token']))
            ['body']['data']['recent'];

        $modules = array_column($recent, 'module');

        // Le fil lisait module_items, que plus aucun écran n'affiche : il
        // aurait montré des lignes menant vers des modules où elles n'existent
        // pas.
        $this->assertContains('backend', $modules);
        $this->assertContains('design', $modules);
        $this->assertContains('tickets', $modules);
    }
}
