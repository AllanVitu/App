<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\OrganizationRepository;
use App\Models\UserRepository;
use App\Services\AccountMailer;

/**
 * Espaces de travail : membres, rôles et invitations.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  TROIS RÈGLES QUI NE SONT PAS DANS LES MIDDLEWARES                      │
 * │                                                                         │
 * │  RequireAdmin et RequireOwner disent qui peut ENTRER dans la route.     │
 * │  Trois règles supplémentaires dépendent de la CIBLE, et ne peuvent donc │
 * │  se vérifier qu'ici :                                                   │
 * │                                                                         │
 * │   1. Un administrateur ne touche pas à un propriétaire. Sinon le rang   │
 * │      supérieur ne veut rien dire — n'importe quel admin s'y hisserait.  │
 * │                                                                         │
 * │   2. On ne se rétrograde ni ne s'exclut soi-même par ces routes. Quitter │
 * │      un espace est un geste distinct, qui a sa propre route.            │
 * │                                                                         │
 * │   3. L'espace garde toujours un propriétaire, et le compte garde        │
 * │      toujours un espace. Ces deux invariants ne sont vérifiés que dans  │
 * │      leave() et destroy() — les SEULS chemins qui peuvent les rompre.   │
 * │                                                                         │
 * │      Les vérifier aussi dans updateMember() et removeMember() aurait    │
 * │      semblé plus sûr, et ne l'aurait pas été : toucher à un             │
 * │      propriétaire y demande déjà d'en être un autre (règle 1), donc     │
 * │      qu'il en reste un. Le test ne se serait jamais déclenché, tout en  │
 * │      donnant l'apparence d'une protection.                              │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
final class OrganizationController
{
    private OrganizationRepository $organizations;

    public function __construct()
    {
        $this->organizations = new OrganizationRepository();
    }

    // -----------------------------------------------------------------------
    //  Espaces
    // -----------------------------------------------------------------------

    /**
     * GET /api/organizations
     */
    public function index(Request $request): void
    {
        Response::json($this->organizations->forUser($request->userId()), 200, [
            'current' => $request->organization(),
        ]);
    }

    /**
     * POST /api/organizations
     *
     * Ouvert à tous : créer son propre espace n'enlève rien à personne. Le
     * créateur en est propriétaire, et bascule dessus dans la foulée — on ne
     * crée pas un espace pour rester dans un autre.
     */
    public function store(Request $request): void
    {
        $validator = new Validator($request->all());
        $name      = $validator->string('name', min: 2, max: 120, label: 'nom de l\'espace');
        $validator->check();

        /** @var string $name */
        $organization = $this->organizations->create($name, $request->userId());

        $this->organizations->setActive($request->userId(), $organization['id']);

        Response::created($organization);
    }

    /**
     * PUT /api/organizations/{id}   [RequireAdmin]
     */
    public function update(Request $request): void
    {
        $this->assertCurrent($request);

        $validator = new Validator($request->all());
        $name      = $validator->string('name', min: 2, max: 120, label: 'nom de l\'espace');
        $validator->check();

        /** @var string $name */
        $this->organizations->rename($request->organizationId(), $name);

        Response::json(['id' => $request->organizationId()] + $request->organization() + ['name' => $name]);
    }

    /**
     * DELETE /api/organizations/{id}   [RequireOwner]
     *
     * Emporte TOUT le contenu de l'espace, par cascade. Refusé sur le dernier
     * espace du compte : un compte sans appartenance ne peut plus rien faire
     * (cf. AuthMiddleware), le laisser se mettre dans cet état serait lui
     * fermer la porte de l'extérieur.
     */
    public function destroy(Request $request): void
    {
        $this->assertCurrent($request);

        if (count($this->organizations->forUser($request->userId())) < 2) {
            throw HttpException::conflict(
                'Vous ne pouvez pas supprimer votre dernier espace de travail. '
                . 'Créez-en un autre d\'abord.',
            );
        }

        $this->organizations->delete($request->organizationId());

        Response::noContent();
    }

    /**
     * POST /api/organizations/{id}/activate
     *
     * LE SEUL GESTE QUI CHANGE LE CLOISONNEMENT. L'appartenance est vérifiée
     * ici même : sans ce test, l'identifiant d'un espace suffirait à en lire
     * le contenu au prochain appel.
     */
    public function activate(Request $request): void
    {
        $id = $request->param('id') ?? '';

        if ($this->organizations->roleOf($id, $request->userId()) === null) {
            // 404 et non 403 : répondre « interdit » confirmerait que cet
            // espace existe. Même règle que pour les sessions d'un autre
            // compte.
            throw HttpException::notFound('Espace de travail introuvable.');
        }

        $this->organizations->setActive($request->userId(), $id);

        Response::json($this->organizations->activeFor($request->userId(), $id));
    }

    // -----------------------------------------------------------------------
    //  Membres
    // -----------------------------------------------------------------------

    /**
     * GET /api/organizations/members
     *
     * Visible de TOUS les membres : savoir avec qui l'on travaille n'est pas
     * un privilège d'administrateur.
     */
    public function members(Request $request): void
    {
        Response::json($this->organizations->members($request->organizationId()), 200, [
            'invitations' => $this->canManage($request)
                // Les invitations en attente, elles, ne regardent que ceux qui
                // peuvent les émettre et les révoquer.
                ? $this->organizations->pendingInvitations($request->organizationId())
                : [],
            'role'        => $request->organizationRole(),
        ]);
    }

    /**
     * PUT /api/organizations/members/{id}   [RequireAdmin]
     */
    public function updateMember(Request $request): void
    {
        $targetId = $request->param('id') ?? '';

        $validator = new Validator($request->all());
        $role      = $validator->enum('role', ['owner', 'admin', 'member']);
        $validator->check();

        /** @var string $role */
        $current = $this->requireMember($request, $targetId);

        if ($targetId === $request->userId()) {
            throw HttpException::conflict('Vous ne pouvez pas modifier votre propre rôle.');
        }

        // Règle 1 : promouvoir quelqu'un propriétaire, ou toucher à un
        // propriétaire, demande de l'être soi-même.
        //
        // ET C'EST CE QUI REND LE COMPTAGE INUTILE ICI. Rétrograder un
        // propriétaire suppose d'en être un autre — donc qu'il y en ait au
        // moins deux. Un compteur à cet endroit ne se déclencherait jamais, et
        // ferait croire à une protection là où c'est la règle 2 qui protège.
        // Le seul chemin qui mène réellement au dernier propriétaire est le
        // départ volontaire : cf. leave().
        if (($current === 'owner' || $role === 'owner')
            && !OrganizationRepository::allows($request->organizationRole(), 'owner')
        ) {
            throw HttpException::forbidden(
                'Seul un propriétaire peut nommer ou rétrograder un propriétaire.',
            );
        }

        $this->organizations->setRole($request->organizationId(), $targetId, $role);

        Response::json(['id' => $targetId, 'role' => $role]);
    }

    /**
     * DELETE /api/organizations/members/{id}   [RequireAdmin]
     */
    public function removeMember(Request $request): void
    {
        $targetId = $request->param('id') ?? '';
        $current  = $this->requireMember($request, $targetId);

        // Règle 2 : partir est un autre geste, qui a sa propre route.
        if ($targetId === $request->userId()) {
            throw HttpException::conflict(
                'Pour partir vous-même, utilisez « Quitter cet espace ».',
            );
        }

        // Même raisonnement que dans updateMember : exclure un propriétaire
        // suppose d'en être un autre, donc qu'il en reste un après le départ.
        if ($current === 'owner' && !OrganizationRepository::allows($request->organizationRole(), 'owner')) {
            throw HttpException::forbidden('Seul un propriétaire peut exclure un propriétaire.');
        }

        $this->organizations->removeMember($request->organizationId(), $targetId);

        Response::noContent();
    }

    /**
     * POST /api/organizations/leave
     *
     * Le pendant volontaire de l'exclusion. Les deux mêmes garde-fous
     * s'appliquent : l'espace garde un propriétaire, et le compte garde une
     * appartenance.
     */
    public function leave(Request $request): void
    {
        if (count($this->organizations->forUser($request->userId())) < 2) {
            throw HttpException::conflict(
                'Vous ne pouvez pas quitter votre dernier espace de travail.',
            );
        }

        if ($request->organizationRole() === 'owner'
            && $this->organizations->ownerCount($request->organizationId()) < 2
        ) {
            throw HttpException::conflict(
                'Vous êtes le dernier propriétaire de cet espace. '
                . 'Nommez un successeur avant de partir.',
            );
        }

        $this->organizations->removeMember($request->organizationId(), $request->userId());

        Response::noContent();
    }

    // -----------------------------------------------------------------------
    //  Invitations
    // -----------------------------------------------------------------------

    /**
     * POST /api/organizations/invitations   [RequireAdmin]
     */
    public function invite(Request $request): void
    {
        $validator = new Validator($request->all());
        $email     = $validator->email();
        $role      = $validator->enum('role', ['admin', 'member'], required: false, default: 'member') ?? 'member';
        $validator->check();

        /** @var string $email */
        // Un membre déjà entré : le dire franchement plutôt que d'envoyer un
        // e-mail qui n'apprendrait rien à personne.
        $existing = (new UserRepository())->findByEmail($email);

        if ($existing !== null
            && $this->organizations->roleOf($request->organizationId(), $existing['id']) !== null
        ) {
            throw HttpException::conflict('Cette personne fait déjà partie de l\'espace.');
        }

        $invitation = $this->organizations->invite(
            $request->organizationId(),
            $email,
            $role,
            $request->userId(),
        );

        // L'envoi est déposé en file : une panne SMTP ne doit pas annuler une
        // invitation valide, qui reste révocable et renvoyable depuis l'écran.
        (new AccountMailer())->sendInvitation(
            $email,
            $request->organization()['name'],
            (string) $request->user()['full_name'],
            $invitation['token'],
        );

        Response::created([
            'id'    => $invitation['id'],
            'email' => $email,
            'role'  => $role,
        ]);
    }

    /**
     * DELETE /api/organizations/invitations/{id}   [RequireAdmin]
     */
    public function revokeInvitation(Request $request): void
    {
        $id = $request->param('id') ?? '';

        if (!$this->organizations->revokeInvitation($request->organizationId(), $id)) {
            throw HttpException::notFound('Invitation introuvable.');
        }

        Response::noContent();
    }

    /**
     * GET /api/invitations/{token}   (publique)
     *
     * L'écran d'accueil du lien. Publique À DESSEIN : celui qui la consulte
     * n'a le plus souvent pas encore de compte, et doit savoir à quoi il est
     * invité AVANT d'en créer un.
     *
     * Elle ne divulgue que ce que le porteur du lien sait déjà : le nom de
     * l'espace et l'adresse invitée. Ni la liste des membres, ni rien du
     * contenu.
     */
    public function showInvitation(Request $request): void
    {
        $invitation = $this->organizations->findInvitationByToken($request->param('token') ?? '');

        if ($invitation === null) {
            throw HttpException::notFound('Cette invitation est invalide, expirée, ou a déjà été acceptée.');
        }

        Response::json($invitation);
    }

    /**
     * POST /api/invitations/{token}/accept   (route protégée)
     *
     * Le compte doit exister : c'est l'inscription qui prend le relais si le
     * lien mène quelqu'un qui n'en a pas, en repassant le jeton (cf.
     * AuthController::register).
     */
    public function acceptInvitation(Request $request): void
    {
        $organization = $this->organizations->acceptInvitation(
            $request->param('token') ?? '',
            $request->userId(),
        );

        if ($organization === null) {
            throw HttpException::notFound('Cette invitation est invalide, expirée, ou a déjà été acceptée.');
        }

        Response::json($organization);
    }

    // -----------------------------------------------------------------------

    private function canManage(Request $request): bool
    {
        return OrganizationRepository::allows($request->organizationRole(), 'admin');
    }

    /**
     * Refuse une route qui désignerait un AUTRE espace que l'espace actif.
     *
     * L'identifiant est dans l'URL pour que celle-ci se lise, mais le
     * cloisonnement vient de l'attribut posé par le middleware. Sans ce test,
     * les deux pourraient diverger et l'URL l'emporterait sur le contrôle de
     * rôle déjà effectué.
     */
    private function assertCurrent(Request $request): void
    {
        if (($request->param('id') ?? '') !== $request->organizationId()) {
            throw HttpException::notFound('Espace de travail introuvable.');
        }
    }

    /**
     * Le rôle de la cible, ou 404 si elle n'est pas de cet espace.
     */
    private function requireMember(Request $request, string $userId): string
    {
        $role = $this->organizations->roleOf($request->organizationId(), $userId);

        if ($role === null) {
            throw HttpException::notFound('Ce membre ne fait pas partie de l\'espace.');
        }

        return $role;
    }
}
