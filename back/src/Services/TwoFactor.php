<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TwoFactorRepository;

/**
 * La double authentification d'un compte : mise en place, vérification des
 * codes, codes de secours, défis de connexion.
 *
 * Le secret TOTP n'existe en clair que le temps d'un calcul, ici : scellé avant
 * d'atteindre la base (SecretBox), il n'en ressort que pour vérifier un code.
 * Il n'est remis à la personne qu'une fois, à la mise en place, pour que son
 * application le prenne.
 */
final class TwoFactor
{
    private const USAGE = 'two-factor';

    private const CODES_DE_SECOURS = 10;

    /** Sans 0/o, 1/l/i : un code de secours se recopie à la main. */
    private const ALPHABET_SECOURS = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function __construct(private readonly TwoFactorRepository $depot = new TwoFactorRepository())
    {
    }

    /**
     * @return array{enabled: bool, enabled_at: ?string, recovery_codes_remaining: int}
     */
    public function status(string $userId): array
    {
        $etat = $this->depot->state($userId);

        return [
            'enabled'                  => $etat['enabled_at'] !== null,
            'enabled_at'               => $etat['enabled_at'],
            'recovery_codes_remaining' => $etat['enabled_at'] !== null ? $this->depot->remainingRecoveryCodes($userId) : 0,
        ];
    }

    /**
     * Un secret neuf, en attente d'un premier code. Remplace une mise en place
     * abandonnée : seul le dernier QR code affiché vaut.
     *
     * @return array{secret: string, uri: string}
     */
    public function start(string $userId, string $email): array
    {
        $secret = Totp::nouveauSecret();

        $this->depot->setPending($userId, SecretBox::seal($secret, self::USAGE));

        return ['secret' => $secret, 'uri' => Totp::uri($secret, $email)];
    }

    public function hasPending(string $userId): bool
    {
        return $this->depot->state($userId)['pending'] !== null;
    }

    /**
     * Active la double authentification si le code prouve que l'application a
     * bien pris le secret. Renvoie les codes de secours — la seule fois où ils
     * existent en clair —, ou null si le code ne correspond pas.
     *
     * @return list<string>|null
     */
    public function enable(string $userId, string $code): ?array
    {
        $etat = $this->depot->state($userId);

        if ($etat['pending'] === null) {
            return null;
        }

        $pas = Totp::verifier(SecretBox::open($etat['pending'], self::USAGE), $code);

        if ($pas === null) {
            return null;
        }

        $codes = $this->nouveauxCodes();

        $this->depot->enable($userId, $pas, array_map(self::empreinte(...), $codes));

        return $codes;
    }

    /**
     * Un code de l'application, valable ET jamais servi. Le pas de temps est
     * réservé atomiquement : deux requêtes avec le même code, une seule passe.
     */
    public function verify(string $userId, string $code): bool
    {
        $etat = $this->depot->state($userId);

        if ($etat['secret'] === null) {
            return false;
        }

        $pas = Totp::verifier(SecretBox::open($etat['secret'], self::USAGE), $code, $etat['last_step']);

        return $pas !== null && $this->depot->acceptStep($userId, $pas);
    }

    public function useRecoveryCode(string $userId, string $code): bool
    {
        return $this->depot->consumeRecoveryCode($userId, self::empreinte($code));
    }

    public function remainingRecoveryCodes(string $userId): int
    {
        return $this->depot->remainingRecoveryCodes($userId);
    }

    /**
     * De nouveaux codes de secours ; les précédents cessent de valoir.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(string $userId): array
    {
        $codes = $this->nouveauxCodes();

        $this->depot->replaceRecoveryCodes($userId, array_map(self::empreinte(...), $codes));

        return $codes;
    }

    public function disable(string $userId): void
    {
        $this->depot->disable($userId);
    }

    /**
     * Ouvre l'étape entre le mot de passe et le code. Renvoie le jeton EN
     * CLAIR, remis au client ; la base n'en garde que l'empreinte.
     */
    public function openChallenge(string $userId, int $secondes): string
    {
        $jeton = bin2hex(random_bytes(32));

        $this->depot->createChallenge($userId, hash('sha256', $jeton), $secondes);

        return $jeton;
    }

    /**
     * @return array{id: string, user_id: string}|null
     */
    public function findChallenge(string $jeton): ?array
    {
        return $this->depot->findChallenge(hash('sha256', $jeton));
    }

    public function failChallenge(string $challengeId): int
    {
        return $this->depot->countFailure($challengeId);
    }

    public function closeChallenge(string $challengeId): void
    {
        $this->depot->deleteChallenge($challengeId);
    }

    /**
     * Dix codes « xxxx-xxxx » : 31 symboles, huit tirages — près de quarante
     * bits chacun, assez pour qu'une empreinte SHA-256 suffise à les garder.
     *
     * @return list<string>
     */
    private function nouveauxCodes(): array
    {
        $codes  = [];
        $taille = strlen(self::ALPHABET_SECOURS);

        while (count($codes) < self::CODES_DE_SECOURS) {
            $code = '';

            for ($i = 0; $i < 8; $i++) {
                $code .= self::ALPHABET_SECOURS[random_int(0, $taille - 1)];
            }

            $codes[] = substr($code, 0, 4) . '-' . substr($code, 4);
            $codes   = array_values(array_unique($codes));
        }

        return $codes;
    }

    /** Casse, tirets et espaces ne comptent pas : « ABCD EFGH » vaut « abcd-efgh ». */
    private static function empreinte(string $code): string
    {
        return hash('sha256', strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $code)));
    }
}
