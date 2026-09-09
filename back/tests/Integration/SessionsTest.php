<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\RefreshTokenService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Sessions ouvertes : les lister, en fermer une à distance.
 *
 * Les colonnes « user_agent » et « ip_address » étaient remplies à chaque
 * émission de jeton depuis le premier jour, et lues par personne. Ces routes
 * les exposent enfin — ce qui en fait aussi une nouvelle surface : un
 * identifiant de session deviné ne doit rien permettre.
 */
final class SessionsTest extends ApiTestCase
{
    /**
     * Ouvre une session supplémentaire pour le compte, comme le ferait un
     * second appareil, et renvoie son jeton de rafraîchissement en clair.
     */
    private function ouvrirAilleurs(string $userId, string $agent, string $ip): string
    {
        $service = new RefreshTokenService();
        $token   = $service->issue($userId, $this->requete($agent, $ip));

        return $token;
    }

    /** Une requête porteuse d'un agent et d'une adresse donnés. */
    private function requete(string $agent, string $ip): \App\Core\Request
    {
        return \App\Core\Request::create(
            'POST',
            '/api/auth/login',
            headers: ['User-Agent' => $agent, 'X-Forwarded-For' => $ip],
        );
    }

    #[Test]
    public function la_liste_montre_les_appareils_et_designe_le_courant(): void
    {
        $user = $this->register();

        $courant = $this->ouvrirAilleurs(
            $user['id'],
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36',
            '10.0.0.1',
        );

        $this->ouvrirAilleurs(
            $user['id'],
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile Safari/604.1',
            '10.0.0.2',
        );

        $response = $this->call(
            'GET',
            '/api/auth/sessions',
            headers: $this->bearer($user['token']),
            cookies: [RefreshTokenService::COOKIE_NAME => $courant],
        );

        $this->assertSame(200, $response['status']);

        $sessions = $response['body']['data'];

        // Trois : celle de la connexion du test, et les deux ouvertes ici.
        $this->assertCount(3, $sessions);

        $courantes = array_values(array_filter($sessions, static fn (array $s): bool => $s['current']));
        $this->assertCount(1, $courantes, 'une seule session doit être marquée courante');
        $this->assertSame('Chrome sur Windows', $courantes[0]['label']);

        $libelles = array_column($sessions, 'label');
        $this->assertContains('Safari sur iPhone', $libelles);
    }

    #[Test]
    public function la_liste_ne_divulgue_ni_le_jeton_ni_son_empreinte(): void
    {
        $user    = $this->register();
        $courant = $this->ouvrirAilleurs($user['id'], 'Firefox/121.0', '10.0.0.1');

        $response = $this->call(
            'GET',
            '/api/auth/sessions',
            headers: $this->bearer($user['token']),
            cookies: [RefreshTokenService::COOKIE_NAME => $courant],
        );

        $brut = json_encode($response['body'], JSON_THROW_ON_ERROR);

        // Ni le jeton en clair — qui ouvrirait la session à quiconque lit la
        // réponse — ni son empreinte, qui suffirait à la révoquer par la
        // bande. Seul l'identifiant de ligne circule.
        $this->assertStringNotContainsString($courant, $brut);
        $this->assertStringNotContainsString(hash('sha256', $courant), $brut);
        $this->assertArrayNotHasKey('token_hash', $response['body']['data'][0]);
    }

    #[Test]
    public function une_session_expiree_ou_revoquee_ne_figure_pas(): void
    {
        $user = $this->register();

        $revoque = $this->ouvrirAilleurs($user['id'], 'Firefox/121.0', '10.0.0.1');
        (new RefreshTokenService())->revoke($revoque);

        $expire = $this->ouvrirAilleurs($user['id'], 'Firefox/121.0', '10.0.0.2');
        Database::connection()
            ->prepare('UPDATE refresh_tokens SET expires_at = NOW() - interval \'1 day\' WHERE token_hash = :h')
            ->execute(['h' => hash('sha256', $expire)]);

        $response = $this->call('GET', '/api/auth/sessions', headers: $this->bearer($user['token']));

        // Une liste de sessions qui montre des sessions fermées ne sert à rien :
        // on ne saurait plus lesquelles couper.
        $this->assertCount(1, $response['body']['data']);
    }

    #[Test]
    public function fermer_une_session_la_retire_de_la_liste(): void
    {
        $user = $this->register();
        $this->ouvrirAilleurs($user['id'], 'Firefox/121.0', '10.0.0.1');

        $sessions = $this->call('GET', '/api/auth/sessions', headers: $this->bearer($user['token']));
        $cible    = $sessions['body']['data'][0]['id'];

        $suppression = $this->call(
            'DELETE',
            "/api/auth/sessions/{$cible}",
            headers: $this->bearer($user['token']),
        );

        $this->assertSame(204, $suppression['status']);

        $restantes = $this->call('GET', '/api/auth/sessions', headers: $this->bearer($user['token']));
        $this->assertCount(1, $restantes['body']['data']);
        $this->assertNotSame($cible, $restantes['body']['data'][0]['id']);
    }

    #[Test]
    public function une_session_fermee_ne_peut_plus_rafraichir(): void
    {
        $user  = $this->register();
        $autre = $this->ouvrirAilleurs($user['id'], 'Firefox/121.0', '10.0.0.1');

        $sessions = $this->call(
            'GET',
            '/api/auth/sessions',
            headers: $this->bearer($user['token']),
            cookies: [RefreshTokenService::COOKIE_NAME => $autre],
        );

        $cible = array_values(array_filter(
            $sessions['body']['data'],
            static fn (array $s): bool => $s['current'],
        ))[0]['id'];

        $this->call('DELETE', "/api/auth/sessions/{$cible}", headers: $this->bearer($user['token']));

        // LE POINT DE TOUTE LA FONCTIONNALITÉ : fermer une session à distance
        // doit réellement couper l'appareil, pas seulement le faire
        // disparaître d'une liste.
        $refresh = $this->call(
            'POST',
            '/api/auth/refresh',
            cookies: [RefreshTokenService::COOKIE_NAME => $autre],
        );

        $this->assertSame(401, $refresh['status']);
    }

    #[Test]
    public function on_ne_ferme_pas_la_session_d_un_autre_compte(): void
    {
        $victime = $this->register('victime@test.local');
        $sessions = $this->call('GET', '/api/auth/sessions', headers: $this->bearer($victime['token']));
        $cible    = $sessions['body']['data'][0]['id'];

        $attaquant = $this->register('attaquant@test.local');

        $response = $this->call(
            'DELETE',
            "/api/auth/sessions/{$cible}",
            headers: $this->bearer($attaquant['token']),
        );

        // 404 et non 403 : distinguer « n'existe pas » de « ne vous appartient
        // pas » confirmerait à un attaquant quels identifiants sont réels.
        $this->assertSame(404, $response['status']);

        // Et la session de la victime est toujours là.
        $apres = $this->call('GET', '/api/auth/sessions', headers: $this->bearer($victime['token']));
        $this->assertCount(1, $apres['body']['data']);
    }

    #[Test]
    public function un_identifiant_inventé_repond_404_sans_erreur_serveur(): void
    {
        $user = $this->register();

        foreach (['00000000-0000-4000-8000-000000000000', 'pas-un-uuid'] as $id) {
            $response = $this->call(
                'DELETE',
                "/api/auth/sessions/{$id}",
                headers: $this->bearer($user['token']),
            );

            $this->assertSame(404, $response['status'], $id);
        }
    }

    #[Test]
    public function fermer_les_autres_epargne_celle_qui_le_demande(): void
    {
        $user = $this->register();

        $courant = $this->ouvrirAilleurs($user['id'], 'Firefox/121.0', '10.0.0.1');
        $this->ouvrirAilleurs($user['id'], 'Chrome/120', '10.0.0.2');
        $this->ouvrirAilleurs($user['id'], 'Safari/605', '10.0.0.3');

        $response = $this->call(
            'DELETE',
            '/api/auth/sessions',
            headers: $this->bearer($user['token']),
            cookies: [RefreshTokenService::COOKIE_NAME => $courant],
        );

        $this->assertSame(200, $response['status']);
        $this->assertSame(3, $response['body']['data']['closed']);

        // « Se déconnecter partout ailleurs » ne doit pas déconnecter
        // l'appareil sur lequel on vient de cliquer — sinon le geste se
        // retourne contre celui qui le fait.
        $restantes = $this->call(
            'GET',
            '/api/auth/sessions',
            headers: $this->bearer($user['token']),
            cookies: [RefreshTokenService::COOKIE_NAME => $courant],
        );

        $this->assertCount(1, $restantes['body']['data']);
        $this->assertTrue($restantes['body']['data'][0]['current']);
    }

    #[Test]
    public function un_compte_n_accumule_pas_les_sessions_sans_fin(): void
    {
        $user = $this->register();

        // Quinze connexions depuis le même poste — un utilisateur qui ouvre
        // son navigateur chaque matin pendant trois semaines.
        for ($i = 0; $i < 15; $i++) {
            $this->ouvrirAilleurs($user['id'], 'Firefox/121.0', '10.0.0.1');
        }

        $response = $this->call('GET', '/api/auth/sessions', headers: $this->bearer($user['token']));

        // Sans plafond, la liste en compterait seize et la table croîtrait
        // indéfiniment : une « session » cesserait de désigner un appareil
        // pour ne plus désigner qu'une connexion. Constaté sur le compte de
        // démonstration, qui en avait accumulé 1 038.
        $this->assertCount(10, $response['body']['data']);
    }

    #[Test]
    public function les_routes_de_session_exigent_une_authentification(): void
    {
        foreach ([['GET', '/api/auth/sessions'], ['DELETE', '/api/auth/sessions']] as [$methode, $chemin]) {
            $this->assertSame(401, $this->call($methode, $chemin)['status'], "{$methode} {$chemin}");
        }
    }
}
