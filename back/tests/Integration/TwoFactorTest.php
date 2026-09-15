<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\DataExport;
use App\Services\Totp;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * La double authentification, de bout en bout par l'API.
 *
 * Un détail de méthode : l'activation consomme le pas de temps courant, et un
 * code ne sert qu'une fois. Pour se connecter aussitôt avec le code du même
 * instant, les tests « font passer le temps » en effaçant le dernier pas
 * accepté (oublierLeDernierPas) — sans quoi il faudrait attendre trente
 * secondes par test.
 */
final class TwoFactorTest extends ApiTestCase
{
    private const MOT_DE_PASSE = 'Motdepasse1-solide';

    #[Test]
    public function l_activation_exige_le_mot_de_passe_puis_un_premier_code(): void
    {
        $session = $this->register('activation-2fa@test.local');
        $entete  = $this->bearer($session['token']);

        $this->assertSame(422, $this->call('POST', '/api/profile/two-factor/setup', ['password' => 'pas-le-bon'], $entete)['status']);

        $miseEnPlace = $this->call('POST', '/api/profile/two-factor/setup', ['password' => self::MOT_DE_PASSE], $entete);
        $this->assertSame(200, $miseEnPlace['status']);

        $secret = (string) $miseEnPlace['body']['data']['secret'];
        $this->assertStringStartsWith('otpauth://totp/Relais:', $miseEnPlace['body']['data']['uri']);

        // Le secret n'est jamais en clair en base.
        $this->assertStringNotContainsString($secret, $this->valeur('SELECT two_factor_pending_secret FROM users WHERE id = :id', $session['id']));

        $faux = $this->codeFaux($secret);
        $this->assertSame(422, $this->call('POST', '/api/profile/two-factor/enable', ['code' => $faux], $entete)['status']);

        $activation = $this->call('POST', '/api/profile/two-factor/enable', ['code' => $this->codeCourant($secret)], $entete);

        $this->assertSame(200, $activation['status'], json_encode($activation['body']) ?: '');
        $this->assertCount(10, $activation['body']['data']['recovery_codes']);
        $this->assertTrue($activation['body']['data']['enabled']);

        $etat = $this->call('GET', '/api/profile/two-factor', [], $entete)['body']['data'];
        $this->assertSame(10, $etat['recovery_codes_remaining']);

        $this->assertTrue($this->call('GET', '/api/profile', [], $entete)['body']['data']['two_factor_enabled']);
        $this->assertSame(1, $this->avis('activation-2fa@test.local', 'activée'));
    }

    #[Test]
    public function la_connexion_n_ouvre_la_session_qu_avec_le_code_et_un_code_ne_sert_qu_une_fois(): void
    {
        ['session' => $session, 'secret' => $secret] = $this->activer('connexion-2fa@test.local');
        $this->oublierLeDernierPas($session['id']);

        $etape = $this->connecter('connexion-2fa@test.local');

        $this->assertTrue($etape['two_factor_required']);
        $this->assertArrayNotHasKey('access_token', $etape);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $etape['challenge']);

        $this->assertSame(422, $this->second($etape['challenge'], ['code' => $this->codeFaux($secret)])['status']);

        $code    = $this->codeCourant($secret);
        $reussie = $this->second($etape['challenge'], ['code' => $code]);

        $this->assertSame(200, $reussie['status'], json_encode($reussie['body']) ?: '');
        $this->assertArrayHasKey('access_token', $reussie['body']['data']);

        // Le même code, sur un défi tout neuf : refusé.
        $encore = $this->connecter('connexion-2fa@test.local');
        $this->assertSame(422, $this->second($encore['challenge'], ['code' => $code])['status']);
    }

    #[Test]
    public function un_code_de_secours_ouvre_une_session_une_seule_fois_et_le_signale(): void
    {
        ['codes' => $codes] = $this->activer('secours-2fa@test.local');

        $premiere = $this->second($this->connecter('secours-2fa@test.local')['challenge'], ['recovery_code' => strtoupper($codes[0])]);
        $this->assertSame(200, $premiere['status'], 'la casse ne compte pas');

        $seconde = $this->second($this->connecter('secours-2fa@test.local')['challenge'], ['recovery_code' => $codes[0]]);
        $this->assertSame(422, $seconde['status']);

        $this->assertSame(1, $this->avis('secours-2fa@test.local', 'code de secours'));
    }

    #[Test]
    public function trop_de_codes_errones_ferment_l_etape_de_connexion(): void
    {
        ['session' => $session, 'secret' => $secret] = $this->activer('essais-2fa@test.local');
        $this->oublierLeDernierPas($session['id']);

        $defi = $this->connecter('essais-2fa@test.local')['challenge'];

        for ($i = 1; $i <= 4; $i++) {
            $this->assertSame(422, $this->second($defi, ['code' => $this->codeFaux($secret)])['status'], "essai n° {$i}");
        }

        $this->assertSame(401, $this->second($defi, ['code' => $this->codeFaux($secret)])['status'], 'le cinquième ferme le défi');
        $this->assertSame(401, $this->second($defi, ['code' => $this->codeCourant($secret)])['status'], 'même le bon code arrive trop tard');
    }

    #[Test]
    public function une_etape_de_connexion_expiree_ne_vaut_plus_rien(): void
    {
        ['session' => $session, 'secret' => $secret] = $this->activer('expiration-2fa@test.local');
        $this->oublierLeDernierPas($session['id']);

        $defi = $this->connecter('expiration-2fa@test.local')['challenge'];

        Database::connection()->exec("UPDATE two_factor_challenges SET expires_at = NOW() - INTERVAL '1 second'");

        $this->assertSame(401, $this->second($defi, ['code' => $this->codeCourant($secret)])['status']);
    }

    #[Test]
    public function desactiver_exige_le_mot_de_passe_et_un_code(): void
    {
        ['session' => $session, 'secret' => $secret] = $this->activer('desactivation-2fa@test.local');
        $this->oublierLeDernierPas($session['id']);

        $entete = $this->bearer($session['token']);
        $url    = '/api/profile/two-factor';

        $this->assertSame(422, $this->call('DELETE', $url, ['password' => 'pas-le-bon', 'code' => $this->codeCourant($secret)], $entete)['status']);
        $this->assertSame(422, $this->call('DELETE', $url, ['password' => self::MOT_DE_PASSE, 'code' => $this->codeFaux($secret)], $entete)['status']);

        $desactivee = $this->call('DELETE', $url, ['password' => self::MOT_DE_PASSE, 'code' => $this->codeCourant($secret)], $entete);

        $this->assertSame(200, $desactivee['status'], json_encode($desactivee['body']) ?: '');
        $this->assertFalse($desactivee['body']['data']['enabled']);

        // Le mot de passe suffit de nouveau.
        $this->assertArrayHasKey('access_token', $this->connecter('desactivation-2fa@test.local'));
        $this->assertSame(1, $this->avis('desactivation-2fa@test.local', 'désactivée'));
    }

    #[Test]
    public function regenerer_les_codes_de_secours_invalide_les_anciens(): void
    {
        ['session' => $session, 'secret' => $secret, 'codes' => $anciens] = $this->activer('regeneration-2fa@test.local');
        $this->oublierLeDernierPas($session['id']);

        $nouveaux = $this->call('POST', '/api/profile/two-factor/recovery-codes', ['code' => $this->codeCourant($secret)], $this->bearer($session['token']));
        $this->assertSame(200, $nouveaux['status'], json_encode($nouveaux['body']) ?: '');

        $codes = $nouveaux['body']['data']['recovery_codes'];

        $this->assertSame(422, $this->second($this->connecter('regeneration-2fa@test.local')['challenge'], ['recovery_code' => $anciens[0]])['status']);
        $this->assertSame(200, $this->second($this->connecter('regeneration-2fa@test.local')['challenge'], ['recovery_code' => $codes[0]])['status']);
    }

    #[Test]
    public function activer_ferme_les_sessions_ouvertes_sans_second_facteur(): void
    {
        $session = $this->register('sessions-2fa@test.local');

        // Une autre session, ouverte avec le seul mot de passe.
        $this->connecter('sessions-2fa@test.local');
        $this->assertGreaterThan(0, $this->compter('SELECT COUNT(*) FROM refresh_tokens WHERE user_id = :id AND revoked_at IS NULL', $session['id']));

        $this->activer('sessions-2fa@test.local', $session);

        $this->assertSame(0, $this->compter('SELECT COUNT(*) FROM refresh_tokens WHERE user_id = :id AND revoked_at IS NULL', $session['id']));
    }

    #[Test]
    public function le_secret_ne_quitte_jamais_le_serveur_apres_la_mise_en_place(): void
    {
        ['session' => $session, 'secret' => $secret] = $this->activer('secret-2fa@test.local');
        $entete = $this->bearer($session['token']);

        foreach (['/api/profile', '/api/profile/two-factor', '/api/auth/me'] as $chemin) {
            $this->assertStringNotContainsString($secret, (string) json_encode($this->call('GET', $chemin, [], $entete)['body']), $chemin);
        }

        $export = (string) json_encode((new DataExport())->forUser($session['id']));

        $this->assertStringNotContainsString($secret, $export);
        $this->assertStringNotContainsString('two_factor_secret', $export);
        $this->assertStringContainsString('two_factor_enabled_at', $export);
    }

    // -----------------------------------------------------------------------

    /**
     * @param  array{token: string, id: string}|null $session
     * @return array{session: array<string, string>, secret: string, codes: list<string>}
     */
    private function activer(string $email, ?array $session = null): array
    {
        $session ??= $this->register($email);
        $entete    = $this->bearer($session['token']);

        $secret = (string) $this->call('POST', '/api/profile/two-factor/setup', ['password' => self::MOT_DE_PASSE], $entete)['body']['data']['secret'];

        $activation = $this->call('POST', '/api/profile/two-factor/enable', ['code' => $this->codeCourant($secret)], $entete);
        $this->assertSame(200, $activation['status'], json_encode($activation['body']) ?: '');

        return ['session' => $session, 'secret' => $secret, 'codes' => $activation['body']['data']['recovery_codes']];
    }

    /**
     * @return array<string, mixed>
     */
    private function connecter(string $email): array
    {
        $reponse = $this->call('POST', '/api/auth/login', ['email' => $email, 'password' => self::MOT_DE_PASSE]);

        $this->assertSame(200, $reponse['status'], json_encode($reponse['body']) ?: '');

        return $reponse['body']['data'];
    }

    /**
     * @param  array<string, string> $preuve
     * @return array{status: int, body: array<string, mixed>}
     */
    private function second(string $defi, array $preuve): array
    {
        return $this->call('POST', '/api/auth/login/two-factor', ['challenge' => $defi] + $preuve);
    }

    private function codeCourant(string $secret): string
    {
        return Totp::code($secret, Totp::pas());
    }

    /** Un code à six chiffres qui n'est celui d'aucun pas toléré. */
    private function codeFaux(string $secret): string
    {
        $pas      = Totp::pas();
        $valables = [Totp::code($secret, $pas - 1), Totp::code($secret, $pas), Totp::code($secret, $pas + 1)];

        for ($candidat = 0; ; $candidat++) {
            $code = str_pad((string) $candidat, 6, '0', STR_PAD_LEFT);

            if (!in_array($code, $valables, true)) {
                return $code;
            }
        }
    }

    private function oublierLeDernierPas(string $userId): void
    {
        Database::connection()
            ->prepare('UPDATE users SET two_factor_last_step = NULL WHERE id = :id')
            ->execute(['id' => $userId]);
    }

    private function avis(string $email, string $motDansLeSujet): int
    {
        $statement = Database::connection()->prepare(
            "SELECT COUNT(*) FROM jobs WHERE type = 'mail.send' AND payload->>'to' = :email AND payload->>'subject' ILIKE :sujet",
        );
        $statement->execute(['email' => $email, 'sujet' => '%' . $motDansLeSujet . '%']);

        return (int) $statement->fetchColumn();
    }

    private function compter(string $sql, string $userId): int
    {
        return (int) $this->valeur($sql, $userId);
    }

    private function valeur(string $sql, string $userId): string
    {
        $statement = Database::connection()->prepare($sql);
        $statement->execute(['id' => $userId]);

        return (string) $statement->fetchColumn();
    }
}
