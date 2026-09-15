<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Terms;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\OrganizationRepository;
use App\Models\SettingsRepository;
use App\Models\UserRepository;
use App\Services\AccountMailer;
use App\Services\Jwt;
use App\Services\RateLimiter;
use App\Services\RefreshTokenService;
use App\Services\ThrottleService;
use App\Services\TwoFactor;

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

    /** Cinq minutes pour saisir le code : de quoi sortir son téléphone, pas davantage. */
    private const DEFI_SECONDES = 300;

    /** Au cinquième code erroné, le défi se ferme : on repart du mot de passe. */
    private const DEFI_ESSAIS = 5;

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
        // ┌───────────────────────────────────────────────────────────────────┐
        // │  AVANT TOUTE VALIDATION                                           │
        // │                                                                   │
        // │  Chaque inscription réussie envoie un e-mail, à une adresse que   │
        // │  personne n'a encore prouvé posséder : sans limite, ce formulaire │
        // │  est un canon à courriels. Et le refus « compte existant » dit    │
        // │  qu'une adresse est inscrite : compter aussi les tentatives       │
        // │  refusées borne l'énumération des comptes.                        │
        // │                                                                   │
        // │  Vingt par heure : une famille ou une salle de classe derrière    │
        // │  une même box passe, une boucle non.                              │
        // └───────────────────────────────────────────────────────────────────┘
        (new RateLimiter())->hit('register', $request->ip() ?? 'inconnue', 20, 3600);

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

        // ┌───────────────────────────────────────────────────────────────────┐
        // │  UN COMPTE SANS ESPACE DE TRAVAIL NE PEUT RIEN FAIRE              │
        // │                                                                   │
        // │  AuthMiddleware refuse toute requête d'un compte sans             │
        // │  appartenance : ce n'est donc pas une commodité, c'est ce qui rend │
        // │  le compte utilisable. Créée ici plutôt que par un déclencheur     │
        // │  parce qu'elle porte un NOM, et qu'un nom se choisit — celui de    │
        // │  la personne, faute de mieux, et qu'elle renommera.                │
        // └───────────────────────────────────────────────────────────────────┘
        //
        // L'invitation, si le parcours vient d'un lien, est traitée APRÈS :
        // cf. la lecture de « invitation_token » plus bas. Le compte a de
        // toute façon le sien, dont il reste propriétaire.
        $organizations = new OrganizationRepository();
        $organizations->create($fullName, $user['id']);

        // Un lien d'invitation en poche : on y entre dans la foulée, et c'est
        // cet espace-là qui devient l'espace actif. Personne n'accepte une
        // invitation pour atterrir ailleurs.
        $invitationToken = $request->string('invitation_token');

        if ($invitationToken !== null) {
            $organizations->acceptInvitation($invitationToken, $user['id']);
        }

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

        unset($user['password_hash']);

        // Double authentification : le mot de passe correct n'ouvre qu'un DÉFI,
        // à usage unique et bref. La session ne s'ouvre qu'avec le code (cf.
        // loginTwoFactor) — un mot de passe volé ne suffit plus.
        if ($user['two_factor_enabled']) {
            Response::json([
                'two_factor_required' => true,
                'challenge'           => (new TwoFactor())->openChallenge((string) $user['id'], self::DEFI_SECONDES),
                'expires_in'          => self::DEFI_SECONDES,
            ]);

            return;
        }

        $this->users->touchLastLogin($user['id']);

        Response::json($this->authPayload($user, $request));
    }

    /**
     * POST /api/auth/login/two-factor
     *
     * La seconde étape : le défi ouvert par le mot de passe, et un code de
     * l'application — ou un code de secours. Un défi inconnu ou expiré répond
     * comme un défi fermé : on repart du mot de passe.
     */
    public function loginTwoFactor(Request $request): void
    {
        $validator = new Validator($request->all());
        $jeton     = (string) $validator->string('challenge', min: 64, max: 64, label: 'étape de connexion');
        $code      = trim((string) $request->string('code', ''));
        $secours   = trim((string) $request->string('recovery_code', ''));

        if ($code === '' && $secours === '') {
            $validator->addError('code', 'Saisissez le code de votre application, ou un code de secours.');
        }

        $validator->check();

        $deuxFacteurs = new TwoFactor();
        $defi         = $deuxFacteurs->findChallenge($jeton);
        $user         = $defi !== null ? $this->users->findById($defi['user_id']) : null;

        if ($defi === null || $user === null || !$user['is_active'] || !$user['two_factor_enabled']) {
            throw HttpException::unauthorized('Cette étape de connexion a expiré : saisissez de nouveau votre mot de passe.');
        }

        $email  = (string) $user['email'];
        $ip     = $request->ip();
        $userId = (string) $user['id'];

        $this->throttle->ensureNotLocked($email, $ip, ThrottleService::ACTION_TWO_FACTOR);

        $parSecours = $code === '';
        $valide     = $parSecours ? $deuxFacteurs->useRecoveryCode($userId, $secours) : $deuxFacteurs->verify($userId, $code);

        if (!$valide) {
            $this->throttle->record($email, $ip, false, ThrottleService::ACTION_TWO_FACTOR);

            if ($deuxFacteurs->failChallenge($defi['id']) >= self::DEFI_ESSAIS) {
                $deuxFacteurs->closeChallenge($defi['id']);

                throw HttpException::unauthorized('Trop de codes erronés : saisissez de nouveau votre mot de passe.');
            }

            throw HttpException::validation($parSecours
                ? ['recovery_code' => 'Ce code de secours n\'est pas valable, ou a déjà servi.']
                : ['code' => 'Ce code n\'est pas valable : vérifiez l\'heure de votre téléphone, ou attendez le code suivant.']);
        }

        $deuxFacteurs->closeChallenge($defi['id']);
        $this->throttle->record($email, $ip, true, ThrottleService::ACTION_TWO_FACTOR);
        $this->users->touchLastLogin($userId);

        if ($parSecours) {
            // Un code de secours sert quand le téléphone manque — ou quand un
            // tiers en a trouvé un : le dire hors bande.
            (new AccountMailer())->sendTwoFactorNotice($email, (string) $user['full_name'], 'secours', $deuxFacteurs->remainingRecoveryCodes($userId));
        }

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
            // L'espace courant ET la liste des autres : le sélecteur d'espace
            // est affiché en permanence, il n'a donc pas de moment où aller
            // chercher sa propre garniture.
            'organization'  => $request->organization(),
            'organizations' => (new OrganizationRepository())->forUser($request->userId()),
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

        // Relue depuis la base plutôt que déduite de ce qui vient d'être créé :
        // à la connexion, l'espace actif peut avoir été supprimé ou
        // l'appartenance révoquée depuis la dernière visite, et c'est
        // activeFor() qui sait retomber sur ses pieds.
        $organizations = new OrganizationRepository();

        return [
            'user'          => $user,
            'organization'  => $organizations->activeFor($user['id'], $user['active_organization_id'] ?? null),
            'organizations' => $organizations->forUser($user['id']),
            'access_token'  => Jwt::issue($user['id'], $user['role']),
            'expires_in'    => Jwt::ttl(),
        ];
    }
}
