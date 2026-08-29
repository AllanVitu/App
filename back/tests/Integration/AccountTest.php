<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Core\Request;
use App\Services\UserTokenService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Confirmation d'adresse et réinitialisation de mot de passe.
 *
 * Le jeton en clair n'existe que dans l'e-mail : les tests l'émettent par le
 * service — celui-là même qu'utilise le contrôleur — plutôt que de lire une
 * boîte SMTP.
 */
final class AccountTest extends ApiTestCase
{
    #[Test]
    public function l_inscription_prepare_une_demande_de_confirmation(): void
    {
        $session = $this->register('jean@test.local');

        $this->assertSame(
            1,
            $this->pendingTokenCount($session['id'], UserTokenService::TYPE_EMAIL_VERIFICATION),
        );
    }

    #[Test]
    public function une_panne_smtp_n_annule_pas_l_inscription(): void
    {
        // Le bootstrap pointe volontairement vers un port fermé : l'envoi
        // échoue à chaque test. L'inscription doit néanmoins aboutir.
        $response = $this->call('POST', '/api/auth/register', [
            'full_name' => 'Jean Dupont',
            'email'     => 'jean@test.local',
            'password'  => 'Motdepasse1',
            'terms_accepted' => true,
        ]);

        $this->assertSame(201, $response['status']);
    }

    #[Test]
    public function un_jeton_de_confirmation_valide_marque_l_adresse(): void
    {
        $session = $this->register('jean@test.local');
        $token = $this->issueToken($session['id'], UserTokenService::TYPE_EMAIL_VERIFICATION);

        $reponse = $this->call('POST', '/api/auth/email/verify', ['token' => $token]);
        $this->assertSame(200, $reponse['status']);

        $moi = $this->call('GET', '/api/auth/me', headers: $this->bearer($session['token']));
        $this->assertNotNull($moi['body']['data']['user']['email_verified_at']);
    }

    #[Test]
    public function un_jeton_de_confirmation_ne_sert_qu_une_fois(): void
    {
        $session = $this->register('jean@test.local');
        $token = $this->issueToken($session['id'], UserTokenService::TYPE_EMAIL_VERIFICATION);

        $this->call('POST', '/api/auth/email/verify', ['token' => $token]);
        $rejeu = $this->call('POST', '/api/auth/email/verify', ['token' => $token]);

        $this->assertSame(400, $rejeu['status']);
    }

    #[Test]
    public function un_jeton_expire_est_refuse(): void
    {
        $session = $this->register('jean@test.local');
        $token = $this->issueToken($session['id'], UserTokenService::TYPE_PASSWORD_RESET);

        Database::connection()
            ->prepare("UPDATE user_tokens SET expires_at = NOW() - INTERVAL '1 hour' WHERE user_id = :id")
            ->execute(['id' => $session['id']]);

        $reponse = $this->call('POST', '/api/auth/password/reset', [
            'token'    => $token,
            'password' => 'NouveauMdp1',
        ]);

        $this->assertSame(400, $reponse['status']);
    }

    #[Test]
    public function une_nouvelle_demande_invalide_la_precedente(): void
    {
        $session = $this->register('jean@test.local');

        $ancien = $this->issueToken($session['id'], UserTokenService::TYPE_PASSWORD_RESET);
        $this->issueToken($session['id'], UserTokenService::TYPE_PASSWORD_RESET);

        // Un lien envoyé par erreur doit cesser de fonctionner dès qu'un
        // nouveau est demandé.
        $reponse = $this->call('POST', '/api/auth/password/reset', [
            'token'    => $ancien,
            'password' => 'NouveauMdp1',
        ]);

        $this->assertSame(400, $reponse['status']);
    }

    #[Test]
    public function mot_de_passe_oublie_repond_pareil_pour_un_compte_inconnu(): void
    {
        $this->register('jean@test.local');

        $connu = $this->call('POST', '/api/auth/password/forgot', ['email' => 'jean@test.local']);
        $inconnu = $this->call('POST', '/api/auth/password/forgot', ['email' => 'personne@test.local']);

        $this->assertSame(200, $connu['status']);
        $this->assertSame(200, $inconnu['status']);
        $this->assertSame($connu['body']['data']['message'], $inconnu['body']['data']['message']);
    }

    #[Test]
    public function seul_un_compte_existant_recoit_reellement_un_jeton(): void
    {
        $session = $this->register('jean@test.local');

        $this->call('POST', '/api/auth/password/forgot', ['email' => 'personne@test.local']);
        $this->assertSame(0, $this->countTokens(UserTokenService::TYPE_PASSWORD_RESET));

        $this->call('POST', '/api/auth/password/forgot', ['email' => 'jean@test.local']);
        $this->assertSame(
            1,
            $this->pendingTokenCount($session['id'], UserTokenService::TYPE_PASSWORD_RESET),
        );
    }

    #[Test]
    public function trois_demandes_declenchent_la_limitation(): void
    {
        $this->register('jean@test.local');

        for ($i = 0; $i < 3; $i++) {
            $this->call('POST', '/api/auth/password/forgot', ['email' => 'jean@test.local']);
        }

        $bloque = $this->call('POST', '/api/auth/password/forgot', ['email' => 'jean@test.local']);

        $this->assertSame(429, $bloque['status']);
    }

    #[Test]
    public function la_reinitialisation_change_le_mot_de_passe_et_ferme_les_sessions(): void
    {
        $session = $this->register('jean@test.local', 'Motdepasse1');
        $token = $this->issueToken($session['id'], UserTokenService::TYPE_PASSWORD_RESET);

        $reponse = $this->call('POST', '/api/auth/password/reset', [
            'token'                 => $token,
            'password'              => 'NouveauMdp1',
            'password_confirmation' => 'NouveauMdp1',
        ]);

        $this->assertSame(200, $reponse['status']);

        // Vérifié AVANT toute reconnexion : se connecter créerait une nouvelle
        // session et masquerait la révocation que l'on veut constater.
        $statement = Database::connection()->prepare(
            'SELECT count(*) FROM refresh_tokens WHERE user_id = :id AND revoked_at IS NULL',
        );
        $statement->execute(['id' => $session['id']]);
        $this->assertSame(0, (int) $statement->fetchColumn(), 'sessions non révoquées');

        // L'ancien mot de passe ne fonctionne plus…
        $this->assertSame(401, $this->call('POST', '/api/auth/login', [
            'email'    => 'jean@test.local',
            'password' => 'Motdepasse1',
        ])['status']);

        // …le nouveau, si.
        $this->assertSame(200, $this->call('POST', '/api/auth/login', [
            'email'    => 'jean@test.local',
            'password' => 'NouveauMdp1',
        ])['status']);
    }

    #[Test]
    public function une_confirmation_divergente_est_refusee(): void
    {
        $session = $this->register('jean@test.local');
        $token = $this->issueToken($session['id'], UserTokenService::TYPE_PASSWORD_RESET);

        $reponse = $this->call('POST', '/api/auth/password/reset', [
            'token'                 => $token,
            'password'              => 'NouveauMdp1',
            'password_confirmation' => 'AutreMdp1',
        ]);

        $this->assertSame(422, $reponse['status']);
        $this->assertArrayHasKey('password_confirmation', $reponse['body']['errors']);
    }

    #[Test]
    public function le_renvoi_est_refuse_si_l_adresse_est_deja_confirmee(): void
    {
        $session = $this->register('jean@test.local');
        $token = $this->issueToken($session['id'], UserTokenService::TYPE_EMAIL_VERIFICATION);
        $this->call('POST', '/api/auth/email/verify', ['token' => $token]);

        $reponse = $this->call('POST', '/api/auth/email/resend', headers: $this->bearer($session['token']));

        $this->assertSame(409, $reponse['status']);
    }

    /** Émet un jeton par le service, comme le fait le contrôleur. */
    private function issueToken(string $userId, string $type): string
    {
        return (new UserTokenService())->issue($userId, $type, Request::create('POST', '/api/test'));
    }

    private function countTokens(string $type): int
    {
        $statement = Database::connection()->prepare(
            'SELECT count(*) FROM user_tokens WHERE type = :type::user_token_type',
        );
        $statement->execute(['type' => $type]);

        return (int) $statement->fetchColumn();
    }
}
