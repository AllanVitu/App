<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Terms;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\UserRepository;
use App\Services\AccountMailer;
use App\Services\DataExport;
use App\Services\FileStorage;
use App\Services\Queue;
use App\Services\RateLimiter;
use App\Services\RefreshTokenService;

/**
 * Page Profil : consultation et modification du compte.
 */
final class ProfileController
{
    private const BCRYPT_COST = 12;

    /**
     * Changements de photo par heure, par compte. Largement assez pour
     * quelqu'un qui hésite, et une borne au disque qu'un compte peut remplir.
     */
    private const AVATAR_UPLOADS_PER_HOUR = 10;

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
     *
     * L'avatar non plus : il se TÉLÉVERSE (cf. uploadAvatar). Un
     * « avatar_url » encore envoyé est ignoré — l'URL libre d'autrefois
     * faisait charger à toute l'équipe une image hébergée n'importe où, qui
     * voyait passer leurs adresses IP.
     */
    public function update(Request $request): void
    {
        $validator = new Validator($request->all());
        $fullName  = $validator->string('full_name', min: 2, max: 120, label: 'nom complet');
        $validator->check();

        /** @var string $fullName */
        Response::json($this->users->updateProfile($request->userId(), $fullName));
    }

    /**
     * POST /api/profile/avatar   (multipart, champ « avatar »)
     *
     * L'image arrive déjà recadrée par le navigateur ; elle est malgré tout
     * contrôlée et débarrassée de ses métadonnées ici, comme n'importe quel
     * envoi — le client n'est pas une barrière.
     */
    public function uploadAvatar(Request $request): void
    {
        $userId = $request->userId();

        (new RateLimiter())->hit('avatar', $userId, self::AVATAR_UPLOADS_PER_HOUR, 3600);

        $upload = $request->file('avatar');

        if ($upload === null) {
            throw HttpException::validation(['avatar' => 'Choisissez une image.']);
        }

        $storage  = new FileStorage();
        $stored   = $storage->store($upload, 'avatar', null, $userId, $userId, 'avatar');
        $previous = $this->users->replaceAvatar($userId, (string) $stored['id']);

        // L'ancienne photo part du disque : garder la photo d'hier de quelqu'un
        // qui l'a remplacée, c'est garder une donnée qu'il a retirée.
        if ($previous !== null) {
            $storage->delete($previous);
        }

        Response::json($this->users->findById($userId));
    }

    /**
     * DELETE /api/profile/avatar
     */
    public function removeAvatar(Request $request): void
    {
        $userId   = $request->userId();
        $previous = $this->users->replaceAvatar($userId, null);

        if ($previous !== null) {
            (new FileStorage())->delete($previous);
        }

        Response::json($this->users->findById($userId));
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

        // La cascade a emporté la description de sa photo, et la base a posé
        // une pierre tombale. La purge efface les octets maintenant plutôt qu'à
        // l'heure suivante : un compte effacé ne laisse pas son visage sur le
        // disque.
        Queue::push('storage.purge');

        Response::noContent();
    }

    /**
     * GET /api/profile/export
     *
     * Droits d'accès et de portabilité (RGPD, art. 15 et 20) : tout ce qui se
     * rattache au compte, en archive ZIP (cf. DataExport). Dix par heure : un export
     * pèse, et personne n'en a besoin de onze.
     */
    public function export(Request $request): void
    {
        (new RateLimiter())->hit('data-export', $request->userId(), 10, 3600);

        $archive = (new DataExport())->archive($request->userId());

        try {
            if (!headers_sent()) {
                http_response_code(200);
                header('Content-Type: application/zip');
                header('Content-Length: ' . (string) filesize($archive));
                header('Content-Disposition: attachment; filename="relais-mes-donnees-' . gmdate('Y-m-d') . '.zip"');
                header('Cache-Control: no-store');
                header('X-Content-Type-Options: nosniff');
            }

            readfile($archive);
        } finally {
            // L'archive contient toutes les données du compte : elle ne reste
            // pas dans le dossier temporaire une fois envoyée.
            @unlink($archive);
        }
    }

    /**
     * POST /api/profile/terms
     *
     * Accepte la version EN VIGUEUR des conditions. La version n'est jamais lue
     * dans la requête : on accepte le texte que le serveur publie, pas un
     * numéro qu'un client aurait choisi.
     */
    public function acceptTerms(Request $request): void
    {
        $validator = new Validator($request->all());

        if (!$validator->boolean('accepted')) {
            $validator->addError('accepted', 'Cochez la case pour accepter les conditions générales.');
        }

        $validator->check();

        $this->users->acceptTerms($request->userId(), Terms::CURRENT_VERSION);

        Response::json($this->users->findById($request->userId()));
    }
}
