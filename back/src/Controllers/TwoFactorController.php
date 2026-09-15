<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\UserRepository;
use App\Services\AccountMailer;
use App\Services\RateLimiter;
use App\Services\RefreshTokenService;
use App\Services\TwoFactor;

/**
 * La double authentification, depuis le profil.
 *
 * Chaque geste qui CHANGE la façon d'entrer dans le compte redemande une
 * preuve : le mot de passe pour commencer, un code pour régénérer, les deux
 * pour désactiver. Un jeton d'accès volé ne suffit donc ni à poser son propre
 * second facteur, ni à retirer celui de la personne. Et chaque changement est
 * signalé par e-mail : c'est le seul signal dont elle dispose s'il ne vient pas
 * d'elle.
 */
final class TwoFactorController
{
    private TwoFactor $deuxFacteurs;

    public function __construct()
    {
        $this->deuxFacteurs = new TwoFactor();
    }

    /**
     * GET /api/profile/two-factor
     */
    public function show(Request $request): void
    {
        Response::json($this->deuxFacteurs->status($request->userId()));
    }

    /**
     * POST /api/profile/two-factor/setup
     *
     * Remet le secret et l'adresse du QR code — la seule fois où le secret
     * quitte le serveur, pour que l'application de la personne le prenne.
     */
    public function setup(Request $request): void
    {
        $this->limiter($request);

        $validator = new Validator($request->all());
        $password  = (string) $validator->string('password', min: 1, max: 200, label: 'mot de passe');
        $validator->check();

        $this->exigerMotDePasse($request, $password);

        if ($this->deuxFacteurs->status($request->userId())['enabled']) {
            throw HttpException::conflict('La double authentification est déjà activée.');
        }

        Response::json($this->deuxFacteurs->start($request->userId(), (string) $request->user()['email']));
    }

    /**
     * POST /api/profile/two-factor/enable
     *
     * Le premier code prouve que l'application a pris le secret : sans cette
     * preuve, un QR code mal scanné fermerait le compte à son propriétaire.
     */
    public function enable(Request $request): void
    {
        $this->limiter($request);

        $userId = $request->userId();
        $code   = $this->code($request);

        if (!$this->deuxFacteurs->hasPending($userId)) {
            throw HttpException::conflict('Aucune mise en place en cours : recommencez depuis le profil.');
        }

        $codes = $this->deuxFacteurs->enable($userId, $code);

        if ($codes === null) {
            throw HttpException::validation([
                'code' => 'Ce code ne correspond pas : vérifiez l\'heure de votre téléphone, ou attendez le code suivant.',
            ]);
        }

        // Les autres sessions ont été ouvertes sans second facteur : elles se
        // ferment. Celle d'où l'on active reste ouverte.
        (new RefreshTokenService())->revokeOthers($userId, $request->cookie(RefreshTokenService::COOKIE_NAME));

        $this->avertir($request, 'activation');

        Response::json(['recovery_codes' => $codes] + $this->deuxFacteurs->status($userId));
    }

    /**
     * POST /api/profile/two-factor/recovery-codes
     */
    public function regenerate(Request $request): void
    {
        $this->limiter($request);

        $userId = $request->userId();
        $code   = $this->code($request);

        $this->exigerActive($userId);

        if (!$this->deuxFacteurs->verify($userId, $code)) {
            throw HttpException::validation(['code' => 'Ce code n\'est pas valable, ou a déjà servi.']);
        }

        $codes = $this->deuxFacteurs->regenerateRecoveryCodes($userId);

        $this->avertir($request, 'codes');

        Response::json(['recovery_codes' => $codes] + $this->deuxFacteurs->status($userId));
    }

    /**
     * DELETE /api/profile/two-factor
     */
    public function disable(Request $request): void
    {
        $this->limiter($request);

        $validator = new Validator($request->all());
        $password  = (string) $validator->string('password', min: 1, max: 200, label: 'mot de passe');
        $code      = (string) $validator->string('code', min: 6, max: 12, label: 'code');
        $validator->check();

        $userId = $request->userId();

        $this->exigerMotDePasse($request, $password);
        $this->exigerActive($userId);

        if (!$this->deuxFacteurs->verify($userId, $code)) {
            throw HttpException::validation(['code' => 'Ce code n\'est pas valable, ou a déjà servi.']);
        }

        $this->deuxFacteurs->disable($userId);

        $this->avertir($request, 'desactivation');

        Response::json($this->deuxFacteurs->status($userId));
    }

    // -----------------------------------------------------------------------

    /** Dix gestes par quart d'heure : de quoi se tromper, pas de quoi deviner. */
    private function limiter(Request $request): void
    {
        (new RateLimiter())->hit('two-factor-profile', $request->userId(), 10, 900);
    }

    private function code(Request $request): string
    {
        $validator = new Validator($request->all());
        $code      = (string) $validator->string('code', min: 6, max: 12, label: 'code');
        $validator->check();

        return $code;
    }

    private function exigerMotDePasse(Request $request, string $password): void
    {
        $stored = (new UserRepository())->findByIdWithPassword($request->userId());

        if ($stored === null || !password_verify($password, $stored['password_hash'])) {
            throw HttpException::validation(['password' => 'Mot de passe incorrect.']);
        }
    }

    private function exigerActive(string $userId): void
    {
        if (!$this->deuxFacteurs->status($userId)['enabled']) {
            throw HttpException::conflict('La double authentification n\'est pas activée.');
        }
    }

    private function avertir(Request $request, string $evenement): void
    {
        $user = $request->user();

        (new AccountMailer())->sendTwoFactorNotice((string) $user['email'], (string) $user['full_name'], $evenement);
    }
}
