<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Terms;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\SettingsRepository;
use App\Models\UserRepository;
use App\Services\AccountMailer;
use App\Services\Jwt;
use App\Services\RefreshTokenService;
use App\Services\ThrottleService;

/**
 * Inscription, connexion, rafraîchissement et déconnexion.
 *
 * Modèle de jetons retenu :
 *  - un jeton d'ACCÈS (JWT, 15 min) renvoyé dans le corps JSON, que le client
 *    place dans l'en-tête Authorization ;
 *  - un jeton de RAFRAÎCHISSEMENT (opaque, 14 j) déposé dans un cookie
 *    HttpOnly, donc invisible du JavaScript.
 *
 * Ce découpage limite la fenêtre d'exploitation d'un jeton d'accès volé, tout
 * en gardant le jeton de longue durée hors de portée d'une XSS.
 */
final class AuthController
{
    /** Coût bcrypt : compromis entre résistance au bruteforce et latence. */
    private const BCRYPT_COST = 12;

    /**
     * Hash factice utilisé quand l'e-mail est inconnu, afin que la réponse
     * prenne le même temps qu'une vraie vérification. Sans cela, le temps de
     * réponse révèle si un compte existe.
     */
    private const DUMMY_HASH = '$2y$12$vIfUhl/ZUpMLeYTB9rx4LOPCsg..Wea5IgRAFCRxkKwLzXEhTKgMe';

    private UserRepository $users;
    private RefreshTokenService $refreshTokens;
    private ThrottleService $throttle;

    public function __construct()
    {
        $this->users         = new UserRepository();
        $this->refreshTokens = new RefreshTokenService();
        $this->throttle      = new ThrottleService();
    }

    /**
     * POST /api/auth/register
     */
    public function register(Request $request): void
    {
        $validator = new Validator($request->all());
        $fullName  = $validator->string('full_name', min: 2, max: 120, label: 'nom complet');
        $email     = $validator->email();
        $password  = $validator->password();

        if (!$validator->fails() && $request->string('password_confirmation') !== null
            && $request->string('password_confirmation') !== $password
        ) {
            $validator->addError('password_confirmation', 'La confirmation ne correspond pas au mot de passe.');
        }

        // Le consentement est vérifié côté serveur, pas seulement par la case
        // à cocher du formulaire : une requête forgée doit se heurter au même
        // refus, sinon la trace en base ne prouve rien.
        if (!$validator->boolean('terms_accepted')) {
            $validator->addError(
                'terms_accepted',
                'Vous devez accepter les conditions générales pour créer un compte.',
            );
        }

        $validator->check();

        /** @var string $email */
        /** @var string $password */
        /** @var string $fullName */
        if ($this->users->emailExists($email)) {
            throw HttpException::conflict('Un compte existe déjà avec cette adresse e-mail.');
        }

        $user = $this->users->create(
            $email,
            password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]),
            $fullName,
            Terms::CURRENT_VERSION,
        );

        $this->users->touchLastLogin($user['id']);

        // Envoi du lien de confirmation. AccountMailer journalise et absorbe
        // toute erreur SMTP : une panne du serveur de mail ne doit pas
        // annuler une inscription valide, l'utilisateur pourra redemander
        // le lien depuis son espace.
        (new AccountMailer())->sendVerificationLink($user, $request);

        Response::json($this->authPayload($user, $request), 201);
    }

    /**
     * POST /api/auth/login
     */
    public function login(Request $request): void
    {
        $validator = new Validator($request->all());
        $email     = $validator->email();
        $password  = $validator->string('password', min: 1, max: 200, label: 'mot de passe');
        $validator->check();

        /** @var string $email */
        /** @var string $password */
        $ip = $request->ip();

        // Vérifié AVANT toute opération coûteuse (bcrypt).
        $this->throttle->ensureNotLocked($email, $ip);

        $user = $this->users->findByEmailWithPassword($email);

        // Le hash factice garantit un temps de réponse comparable, que le
        // compte existe ou non.
        $hash  = $user['password_hash'] ?? self::DUMMY_HASH;
        $valid = password_verify($password, $hash) && $user !== null;

        if (!$valid) {
            $this->throttle->record($email, $ip, false);

            // Message volontairement identique pour un e-mail inconnu et un
            // mot de passe erroné : on n'énumère pas les comptes.
            throw HttpException::unauthorized('Identifiants incorrects.');
        }

        /** @var array<string, mixed> $user */
        if (!$user['is_active']) {
            $this->throttle->record($email, $ip, false);

            throw HttpException::forbidden('Ce compte est désactivé.');
        }

        // Réhachage transparent si le coût bcrypt a été relevé depuis l'inscription.
        if (password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST])) {
            $this->users->updatePassword(
                $user['id'],
                password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]),
            );
        }

        $this->throttle->record($email, $ip, true);
        $this->users->touchLastLogin($user['id']);

        unset($user['password_hash']);

        Response::json($this->authPayload($user, $request));
    }

    /**
     * POST /api/auth/refresh
     *
     * Échange le cookie de rafraîchissement contre un nouveau jeton d'accès.
     * Le jeton de rafraîchissement est tourné à chaque appel.
     */
    public function refresh(Request $request): void
    {
        $token = $request->cookie(RefreshTokenService::COOKIE_NAME);

        if ($token === null) {
            throw HttpException::unauthorized('Aucune session à rafraîchir.');
        }

        $rotated = $this->refreshTokens->rotate($token, $request);
        $user    = $this->users->findById($rotated['user_id']);

        if ($user === null || !$user['is_active']) {
            $this->refreshTokens->clearCookie();

            throw HttpException::unauthorized('Session invalide.');
        }

        $this->refreshTokens->sendCookie($rotated['token']);

        Response::json([
            'user'         => $user,
            'access_token' => Jwt::issue($user['id'], $user['role']),
            'expires_in'   => Jwt::ttl(),
        ]);
    }

    /**
     * POST /api/auth/logout
     *
     * Révoque la session courante. Répond 204 même sans cookie : une
     * déconnexion est idempotente du point de vue du client.
     */
    public function logout(Request $request): void
    {
        $token = $request->cookie(RefreshTokenService::COOKIE_NAME);

        if ($token !== null) {
            $this->refreshTokens->revoke($token);
        }

        $this->refreshTokens->clearCookie();

        Response::noContent();
    }

    /**
     * GET /api/auth/me  (route protégée)
     *
     * Renvoie l'utilisateur et ses préférences en un seul appel : le front
     * peut restaurer sa session complète au rechargement de la page.
     */
    public function me(Request $request): void
    {
        Response::json([
            'user'     => $request->user(),
            'settings' => SettingsRepository::present((new SettingsRepository())->findOrCreate($request->userId())),
        ]);
    }

    /**
     * Construit la réponse d'authentification et dépose le cookie de refresh.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function authPayload(array $user, Request $request): array
    {
        $this->refreshTokens->sendCookie(
            $this->refreshTokens->issue($user['id'], $request),
        );

        return [
            'user'         => $user,
            'access_token' => Jwt::issue($user['id'], $user['role']),
            'expires_in'   => Jwt::ttl(),
        ];
    }
}
