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
use App\Services\ThrottleService;
use App\Services\UserTokenService;

/**
 * Vérification d'adresse e-mail et réinitialisation de mot de passe.
 *
 * Ces parcours sont les plus exposés d'une application : ils s'adressent à
 * des visiteurs non authentifiés et manipulent l'accès au compte. Trois
 * règles les gouvernent ici :
 *
 *  1. Aucune énumération : la réponse est identique que le compte existe ou
 *     non, et le temps de traitement reste comparable.
 *  2. Limitation de débit systématique, car chaque demande déclenche un envoi
 *     réel vers une boîte que l'on ne veut pas laisser inonder.
 *  3. Jetons à usage unique, de courte durée, stockés hachés, invalidant les
 *     demandes précédentes.
 */
final class AccountController
{
    private const BCRYPT_COST = 12;

    private UserRepository $users;
    private UserTokenService $tokens;
    private ThrottleService $throttle;
    private AccountMailer $mailer;

    public function __construct()
    {
        $this->users    = new UserRepository();
        $this->tokens   = new UserTokenService();
        $this->throttle = new ThrottleService();
        $this->mailer   = new AccountMailer();
    }

    // -----------------------------------------------------------------------
    // Vérification d'adresse e-mail
    // -----------------------------------------------------------------------

    /**
     * POST /api/auth/email/verify
     *
     * Confirme l'adresse à partir du jeton reçu par e-mail. Route publique :
     * le jeton EST l'authentification, et il est à usage unique.
     */
    public function verifyEmail(Request $request): void
    {
        $validator = new Validator($request->all());
        $token     = $validator->string('token', min: 32, max: 128, label: 'jeton');
        $validator->check();

        $userId = $this->tokens->consume((string) $token, UserTokenService::TYPE_EMAIL_VERIFICATION);
        $this->users->markEmailVerified($userId);

        Response::json(['message' => 'Votre adresse e-mail est confirmée.']);
    }

    /**
     * POST /api/auth/email/resend  (route protégée)
     *
     * Renvoie le lien de confirmation à l'utilisateur connecté.
     */
    public function resendVerification(Request $request): void
    {
        $user = $request->user();

        if ($user['email_verified_at'] !== null) {
            throw HttpException::conflict('Cette adresse est déjà confirmée.');
        }

        $email = (string) $user['email'];
        $ip    = $request->ip();

        $this->throttle->ensureNotLocked($email, $ip, ThrottleService::ACTION_EMAIL_VERIFICATION);
        $this->throttle->record($email, $ip, false, ThrottleService::ACTION_EMAIL_VERIFICATION);

        $this->mailer->sendVerificationLink($user, $request);

        Response::json(['message' => 'Un nouveau lien de confirmation vous a été envoyé.']);
    }

    // -----------------------------------------------------------------------
    // Mot de passe oublié
    // -----------------------------------------------------------------------

    /**
     * POST /api/auth/password/forgot
     *
     * Répond TOUJOURS 200 avec le même message, que l'adresse corresponde ou
     * non à un compte : sinon, l'endpoint devient un oracle permettant de
     * savoir qui est inscrit.
     */
    public function forgotPassword(Request $request): void
    {
        $validator = new Validator($request->all());
        $email     = $validator->email();
        $validator->check();

        $ip = $request->ip();
        $this->throttle->ensureNotLocked((string) $email, $ip, ThrottleService::ACTION_PASSWORD_RESET);
        $this->throttle->record((string) $email, $ip, false, ThrottleService::ACTION_PASSWORD_RESET);

        $user = $this->users->findByEmail((string) $email);

        // Un compte désactivé ne doit pas pouvoir être réactivé par ce biais.
        if ($user !== null && $user['is_active']) {
            $this->mailer->sendResetLink($user, $request);
        }

        Response::json([
            'message' => 'Si un compte existe pour cette adresse, un lien de réinitialisation vient d\'être envoyé.',
        ]);
    }

    /**
     * POST /api/auth/password/reset
     *
     * Consomme le jeton, change le mot de passe et déconnecte toutes les
     * sessions : si un tiers avait pris la main sur le compte, il la perd.
     */
    public function resetPassword(Request $request): void
    {
        $validator = new Validator($request->all());
        $token     = $validator->string('token', min: 32, max: 128, label: 'jeton');
        $password  = $validator->password();

        if ($request->string('password_confirmation') !== null
            && $request->string('password_confirmation') !== $password
        ) {
            $validator->addError('password_confirmation', 'La confirmation ne correspond pas au mot de passe.');
        }

        $validator->check();

        $userId = $this->tokens->consume((string) $token, UserTokenService::TYPE_PASSWORD_RESET);
        $user   = $this->users->findById($userId);

        if ($user === null || !$user['is_active']) {
            throw HttpException::badRequest('Ce compte n\'est plus accessible.');
        }

        $this->users->updatePassword(
            $userId,
            password_hash((string) $password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]),
        );

        $refreshTokens = new RefreshTokenService();
        $refreshTokens->revokeAllForUser($userId);
        $refreshTokens->clearCookie();

        // Confirmer la réussite d'une demande légitime purge le quota.
        $this->throttle->record(
            (string) $user['email'],
            $request->ip(),
            true,
            ThrottleService::ACTION_PASSWORD_RESET,
        );

        // Avertissement : c'est ce message qui permet de réagir à une prise
        // de contrôle du compte.
        $this->mailer->sendPasswordChangedNotice((string) $user['email'], (string) $user['full_name']);

        Response::json(['message' => 'Mot de passe réinitialisé. Vous pouvez vous connecter.']);
    }
}
