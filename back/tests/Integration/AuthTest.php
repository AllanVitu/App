<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\RefreshTokenService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Parcours d'authentification, du point de vue d'un client de l'API.
 */
final class AuthTest extends ApiTestCase
{
    #[Test]
    public function une_inscription_ouvre_la_session_et_provisionne_le_compte(): void
    {
        $response = $this->call('POST', '/api/auth/register', [
            'full_name' => 'Jean Dupont',
            'email'     => 'jean@test.local',
            'password'  => 'Motdepasse1-solide',
            'terms_accepted' => true,
        ]);

        $this->assertSame(201, $response['status']);
        $this->assertNotEmpty($response['body']['data']['access_token']);

        // Deux provisionnements, et non plus un : les préférences suivent le
        // COMPTE, les modules suivent l'ORGANISATION. Activer un module est
        // une décision d'équipe ; le thème ne l'est pas.
        $userId = $response['body']['data']['user']['id'];
        $orgId  = $response['body']['data']['organization']['id'];

        $modules = Database::connection()->prepare(
            'SELECT count(*) FROM organization_modules WHERE organization_id = :id',
        );
        $modules->execute(['id' => $orgId]);
        $this->assertSame(5, (int) $modules->fetchColumn(), 'modules non attribués');

        $settings = Database::connection()->prepare(
            'SELECT count(*) FROM user_settings WHERE user_id = :id',
        );
        $settings->execute(['id' => $userId]);
        $this->assertSame(1, (int) $settings->fetchColumn(), 'préférences non créées');
    }

    #[Test]
    public function le_mot_de_passe_n_est_jamais_renvoye(): void
    {
        $response = $this->call('POST', '/api/auth/register', [
            'full_name' => 'Jean Dupont',
            'email'     => 'jean@test.local',
            'password'  => 'Motdepasse1-solide',
            'terms_accepted' => true,
        ]);

        $this->assertArrayNotHasKey('password_hash', $response['body']['data']['user']);
        $this->assertStringNotContainsString('Motdepasse1-solide', json_encode($response['body']));
    }

    #[Test]
    public function une_inscription_sans_consentement_est_refusee(): void
    {
        $response = $this->call('POST', '/api/auth/register', [
            'full_name' => 'Jean Dupont',
            'email'     => 'jean@test.local',
            'password'  => 'Motdepasse1-solide',
            // terms_accepted volontairement absent
        ]);

        $this->assertSame(422, $response['status']);
        $this->assertArrayHasKey('terms_accepted', $response['body']['errors']);

        // Et rien n'a été créé : un refus de consentement ne doit pas laisser
        // de compte orphelin derrière lui.
        $this->assertFalse((new \App\Models\UserRepository())->emailExists('jean@test.local'));
    }

    #[Test]
    public function le_consentement_est_horodate_et_versionne(): void
    {
        $session = $this->register('jean@test.local');

        $moi = $this->call('GET', '/api/auth/me', headers: $this->bearer($session['token']));
        $user = $moi['body']['data']['user'];

        $this->assertNotNull($user['terms_accepted_at']);

        // Sans le numéro de version, la trace dirait seulement que
        // l'utilisateur a accepté « quelque chose ».
        $this->assertSame(\App\Config\Terms::CURRENT_VERSION, $user['terms_version']);
    }

    #[Test]
    public function une_adresse_deja_prise_est_refusee(): void
    {
        $this->register('jean@test.local');

        $response = $this->call('POST', '/api/auth/register', [
            'full_name' => 'Autre Personne',
            'email'     => 'jean@test.local',
            'password'  => 'Motdepasse1-solide',
            'terms_accepted' => true,
        ]);

        $this->assertSame(409, $response['status']);
    }

    #[Test]
    public function l_adresse_est_insensible_a_la_casse(): void
    {
        $this->register('jean@test.local');

        // La colonne est de type citext : « Jean@Test.local » ne doit pas
        // pouvoir créer un second compte.
        $response = $this->call('POST', '/api/auth/register', [
            'full_name' => 'Jean Majuscule',
            'email'     => 'Jean@Test.Local',
            'password'  => 'Motdepasse1-solide',
            'terms_accepted' => true,
        ]);

        $this->assertSame(409, $response['status']);
    }

    #[Test]
    public function un_mot_de_passe_trop_faible_est_refuse(): void
    {
        $response = $this->call('POST', '/api/auth/register', [
            'full_name' => 'Jean Dupont',
            'email'     => 'jean@test.local',
            'password'  => 'court',
        ]);

        $this->assertSame(422, $response['status']);
        $this->assertArrayHasKey('password', $response['body']['errors']);
    }

    #[Test]
    public function la_connexion_reussit_avec_les_bons_identifiants(): void
    {
        $this->register('jean@test.local', 'Motdepasse1-solide');

        $response = $this->call('POST', '/api/auth/login', [
            'email'    => 'jean@test.local',
            'password' => 'Motdepasse1-solide',
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertNotEmpty($response['body']['data']['access_token']);
    }

    #[Test]
    public function un_compte_inconnu_et_un_mot_de_passe_faux_donnent_la_meme_reponse(): void
    {
        $this->register('jean@test.local', 'Motdepasse1-solide');

        $inconnu = $this->call('POST', '/api/auth/login', [
            'email'    => 'personne@test.local',
            'password' => 'Motdepasse1-solide',
        ]);

        $mauvais = $this->call('POST', '/api/auth/login', [
            'email'    => 'jean@test.local',
            'password' => 'MauvaisMdp1',
        ]);

        // Toute différence ici transformerait l'endpoint en oracle permettant
        // d'énumérer les comptes existants.
        $this->assertSame(401, $inconnu['status']);
        $this->assertSame(401, $mauvais['status']);
        $this->assertSame($inconnu['body']['message'], $mauvais['body']['message']);
    }

    #[Test]
    public function cinq_echecs_declenchent_la_limitation_de_debit(): void
    {
        $this->register('jean@test.local', 'Motdepasse1-solide');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->call('POST', '/api/auth/login', [
                'email'    => 'jean@test.local',
                'password' => 'MauvaisMdp1',
            ]);
        }

        $bloque = $this->call('POST', '/api/auth/login', [
            'email'    => 'jean@test.local',
            'password' => 'MauvaisMdp1',
        ]);

        $this->assertSame(429, $bloque['status']);

        // Le blocage doit tenir même avec le BON mot de passe : sinon un
        // attaquant saurait qu'il vient de le trouver.
        $avecBonMdp = $this->call('POST', '/api/auth/login', [
            'email'    => 'jean@test.local',
            'password' => 'Motdepasse1-solide',
        ]);

        $this->assertSame(429, $avecBonMdp['status']);
    }

    #[Test]
    public function une_connexion_reussie_efface_les_echecs_precedents(): void
    {
        $this->register('jean@test.local', 'Motdepasse1-solide');

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->call('POST', '/api/auth/login', [
                'email'    => 'jean@test.local',
                'password' => 'MauvaisMdp1',
            ]);
        }

        $this->call('POST', '/api/auth/login', [
            'email'    => 'jean@test.local',
            'password' => 'Motdepasse1-solide',
        ]);

        // Trois nouveaux échecs ne doivent pas suffire à bloquer : le compteur
        // est reparti de zéro.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $reponse = $this->call('POST', '/api/auth/login', [
                'email'    => 'jean@test.local',
                'password' => 'MauvaisMdp1',
            ]);
        }

        $this->assertSame(401, $reponse['status']);
    }

    #[Test]
    public function une_route_protegee_exige_un_jeton(): void
    {
        $this->assertSame(401, $this->call('GET', '/api/auth/me')['status']);
        $this->assertSame(
            401,
            $this->call('GET', '/api/auth/me', headers: ['Authorization' => 'Bearer a.b.c'])['status'],
        );
    }

    #[Test]
    public function un_compte_desactive_perd_l_acces_immediatement(): void
    {
        $session = $this->register('jean@test.local');

        // Le jeton reste cryptographiquement valide : c'est le rechargement
        // de l'utilisateur en base qui doit fermer la porte.
        Database::connection()
            ->prepare('UPDATE users SET is_active = FALSE WHERE id = :id')
            ->execute(['id' => $session['id']]);

        $response = $this->call('GET', '/api/auth/me', headers: $this->bearer($session['token']));

        $this->assertSame(403, $response['status']);
    }

    #[Test]
    public function le_jeton_de_rafraichissement_est_stocke_hache(): void
    {
        $session = $this->register('jean@test.local');

        $statement = Database::connection()->prepare(
            'SELECT token_hash FROM refresh_tokens WHERE user_id = :id',
        );
        $statement->execute(['id' => $session['id']]);
        $hash = (string) $statement->fetchColumn();

        // 64 caractères hexadécimaux : une empreinte SHA-256, pas la valeur.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    #[Test]
    public function le_rafraichissement_tourne_le_jeton_et_revoque_l_ancien(): void
    {
        // Le cookie n'étant pas observable depuis les tests, on émet le jeton
        // par le service — le même que celui utilisé par le contrôleur.
        $session = $this->register('jean@test.local');
        $service = new RefreshTokenService();
        $request = \App\Core\Request::create('POST', '/api/auth/refresh');
        $token = $service->issue($session['id'], $request);

        $premier = $this->call(
            'POST',
            '/api/auth/refresh',
            cookies: [RefreshTokenService::COOKIE_NAME => $token],
        );

        $this->assertSame(200, $premier['status']);

        // Rejouer le même jeton doit échouer : il a été consommé.
        $rejeu = $this->call(
            'POST',
            '/api/auth/refresh',
            cookies: [RefreshTokenService::COOKIE_NAME => $token],
        );

        $this->assertSame(401, $rejeu['status']);
    }
}
