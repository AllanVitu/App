<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\UserRepository;
use App\Services\AccountMailer;
use App\Services\RefreshTokenService;

/**
 * Page Profil : consultation et modification du compte.
 */
final class ProfileController
{
    private const BCRYPT_COST = 12;

    private UserRepository $users;

    public function __construct()
    {
        $this->users = new UserRepository();
    }

    /**
     * GET /api/profile
     */
    public function show(Request $request): void
    {
        Response::json($request->user());
    }

    /**
     * PUT /api/profile
     *
     * L'e-mail n'est volontairement pas modifiable ici : le changer suppose
     * une vérification par lien de confirmation, hors périmètre actuel.
     */
    public function update(Request $request): void
    {
        $validator = new Validator($request->all());
        $fullName  = $validator->string('full_name', min: 2, max: 120, label: 'nom complet');
        $avatarUrl = $validator->string('avatar_url', required: false, max: 500, label: 'avatar');
        $validator->check();

        if ($avatarUrl !== null && !$this->isSafeUrl($avatarUrl)) {
            throw HttpException::validation(['avatar_url' => "L'URL de l'avatar doit être en http(s)."]);
        }

        /** @var string $fullName */
        Response::json($this->users->updateProfile($request->userId(), $fullName, $avatarUrl));
    }

    /**
     * PUT /api/profile/password
     *
     * Le mot de passe actuel est exigé : un jeton d'accès volé ne suffit pas
     * à prendre le contrôle définitif du compte.
     */
    public function updatePassword(Request $request): void
    {
        $validator       = new Validator($request->all());
        $currentPassword = $validator->string('current_password', min: 1, max: 200, label: 'mot de passe actuel');
        $newPassword     = $validator->password('new_password');

        if ($request->string('new_password_confirmation') !== null
            && $request->string('new_password_confirmation') !== $newPassword
        ) {
            $validator->addError('new_password_confirmation', 'La confirmation ne correspond pas au nouveau mot de passe.');
        }

        $validator->check();

        $userId = $request->userId();
        $stored = $this->users->findByIdWithPassword($userId);

        if ($stored === null || !password_verify((string) $currentPassword, $stored['password_hash'])) {
            throw HttpException::validation(['current_password' => 'Le mot de passe actuel est incorrect.']);
        }

        if ($currentPassword === $newPassword) {
            throw HttpException::validation(['new_password' => 'Le nouveau mot de passe doit être différent de l\'actuel.']);
        }

        $this->users->updatePassword(
            $userId,
            password_hash((string) $newPassword, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]),
        );

        // Toutes les autres sessions sont invalidées : si un tiers avait pris
        // la main, il perd l'accès immédiatement.
        $refreshTokens = new RefreshTokenService();
        $refreshTokens->revokeAllForUser($userId);
        $refreshTokens->clearCookie();

        // Notification hors bande : c'est le seul signal dont dispose
        // l'utilisateur si le changement ne vient pas de lui.
        $user = $request->user();
        (new AccountMailer())->sendPasswordChangedNotice(
            (string) $user['email'],
            (string) $user['full_name'],
        );

        Response::json(['message' => 'Mot de passe mis à jour. Veuillez vous reconnecter.']);
    }

    /**
     * DELETE /api/profile
     *
     * Suppression définitive du compte et, par cascade, de ses données.
     */
    public function destroy(Request $request): void
    {
        $validator = new Validator($request->all());
        $password  = $validator->string('password', min: 1, max: 200, label: 'mot de passe');
        $validator->check();

        $userId = $request->userId();
        $stored = $this->users->findByIdWithPassword($userId);

        if ($stored === null || !password_verify((string) $password, $stored['password_hash'])) {
            throw HttpException::validation(['password' => 'Mot de passe incorrect.']);
        }

        $this->users->delete($userId);
        (new RefreshTokenService())->clearCookie();

        Response::noContent();
    }

    /**
     * N'accepte que http/https : évite les schémas javascript: ou data:
     * qui deviendraient une XSS une fois l'URL injectée dans un <img>.
     */
    private function isSafeUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
