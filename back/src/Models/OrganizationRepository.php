<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\SchemaBuilder;
use App\Services\SignedUrl;

/**
 * Organisations, appartenances et invitations.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  LE SEUL DÉPÔT QUI NE SE FILTRE PAS SUR « organization_id »             │
 * │                                                                         │
 * │  Tous les autres partent d'une organisation connue et n'en sortent      │
 * │  jamais. Celui-ci répond à la question d'AVANT : « à quelles            │
 * │  organisations ce compte appartient-il, et à quel titre ? ». Il part    │
 * │  donc d'un identifiant de COMPTE, et c'est normal.                      │
 * │                                                                         │
 * │  Conséquence directe : chaque méthode qui touche une organisation       │
 * │  précise vérifie l'appartenance elle-même, plutôt que de faire          │
 * │  confiance à un cloisonnement qui n'existe pas à ce niveau.             │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
final class OrganizationRepository
{
    /**
     * Hiérarchie des rôles, du plus faible au plus fort.
     *
     * Un entier plutôt qu'une suite de conditions : « au moins admin » se lit
     * alors comme une comparaison, et ajouter un rôle intermédiaire ne demande
     * pas de relire chaque test.
     */
    private const RANGS = ['member' => 1, 'admin' => 2, 'owner' => 3];

    /** Durée de vie d'une invitation, en jours. */
    public const INVITATION_TTL_DAYS = 7;

    public static function rank(string $role): int
    {
        return self::RANGS[$role] ?? 0;
    }

    public static function allows(string $role, string $minimum): bool
    {
        return self::rank($role) >= self::rank($minimum);
    }

    // -----------------------------------------------------------------------
    //  Lecture
    // -----------------------------------------------------------------------

    /**
     * L'organisation dans laquelle le compte travaille, avec son rôle.
     *
     * Le repli est ce qui rend « active_organization_id » sûr : l'espace
     * choisi peut avoir été supprimé, ou l'appartenance révoquée depuis. Dans
     * les deux cas on retombe sur la plus ancienne appartenance restante
     * plutôt que de refuser l'accès à un compte parfaitement valide.
     *
     * @return array{id: string, name: string, slug: string, kind: string, role: string}|null
     */
    public function activeFor(string $userId, ?string $prefere): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT o.id, o.name, o.slug, o.kind, m.role
               FROM memberships m
               JOIN organizations o ON o.id = m.organization_id
              WHERE m.user_id = :user_id
           ORDER BY (o.id = :prefere::uuid) DESC, m.created_at, o.id
              LIMIT 1',
        );

        $statement->execute(['user_id' => $userId, 'prefere' => $prefere]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrateOrganization($row);
    }

    /**
     * Une organisation par son identifiant, sans notion d'appartenance.
     *
     * Réservée aux appelants qui ont déjà prouvé leur droit AUTREMENT — la
     * clé d'API de l'ingestion, qui désigne l'espace sans passer par une
     * personne. Le « role » est laissé à l'appelant, faute de membre.
     *
     * @return array{id: string, name: string, slug: string, kind: string}|null
     */
    public function findById(string $organizationId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, name, slug, kind FROM organizations WHERE id = :id',
        );

        $statement->execute(['id' => $organizationId]);
        $row = $statement->fetch();

        return $row === false ? null : [
            'id'   => (string) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'kind' => (string) $row['kind'],
        ];
    }

    /**
     * L'espace de l'instance, créé s'il n'existe pas encore.
     *
     * La création vit dans la base (instance_organization()) et non ici : elle
     * doit aussi y faire entrer les administrateurs en place, et la course
     * entre deux pannes simultanées s'y tranche par un index unique plutôt
     * que par un verrou applicatif.
     */
    public function instanceId(): string
    {
        return (string) Database::connection()->query('SELECT instance_organization()')->fetchColumn();
    }

    /**
     * Toutes les organisations du compte — ce que montre le sélecteur d'espace.
     *
     * L'espace de l'instance vient en dernier : on y passe quand quelque chose
     * a cassé, pas en ouvrant le menu pour aller travailler.
     *
     * @return list<array{id: string, name: string, slug: string, kind: string, role: string, members: int}>
     */
    public function forUser(string $userId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT o.id, o.name, o.slug, o.kind, m.role,
                    (SELECT COUNT(*) FROM memberships x WHERE x.organization_id = o.id) AS members
               FROM memberships m
               JOIN organizations o ON o.id = m.organization_id
              WHERE m.user_id = :user_id
           ORDER BY (o.kind = \'instance\'), o.name',
        );

        $statement->execute(['user_id' => $userId]);

        return array_map(
            fn (array $row): array => $this->hydrateOrganization($row) + ['members' => (int) $row['members']],
            $statement->fetchAll(),
        );
    }

    /**
     * Rôle du compte dans cette organisation, ou null s'il n'en est pas.
     */
    public function roleOf(string $organizationId, string $userId): ?string
    {
        $statement = Database::connection()->prepare(
            'SELECT role FROM memberships WHERE organization_id = :org AND user_id = :user_id',
        );

        $statement->execute(['org' => $organizationId, 'user_id' => $userId]);
        $role = $statement->fetchColumn();

        return $role === false ? null : (string) $role;
    }

    /**
     * Les membres, tels que les montre l'écran Équipe.
     *
     * @return list<array<string, mixed>>
     */
    public function members(string $organizationId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT u.id, u.full_name, u.email, u.avatar_file_id, u.last_login_at,
                    m.role, m.created_at AS joined_at
               FROM memberships m
               JOIN users u ON u.id = m.user_id
              WHERE m.organization_id = :org
           ORDER BY CASE m.role WHEN \'owner\' THEN 0 WHEN \'admin\' THEN 1 ELSE 2 END, u.full_name',
        );

        $statement->execute(['org' => $organizationId]);

        return array_map(
            static fn (array $row): array => [
                'id'            => (string) $row['id'],
                'full_name'     => (string) $row['full_name'],
                'email'         => (string) $row['email'],
                // Une adresse signée : l'écran Équipe est justement ce qui
                // donne le droit de voir la photo d'un coéquipier.
                'avatar_url'    => $row['avatar_file_id'] !== null
                    ? SignedUrl::forFile((string) $row['avatar_file_id'])
                    : null,
                'role'          => (string) $row['role'],
                'joined_at'     => Database::toIso($row['joined_at']),
                'last_login_at' => Database::toIso($row['last_login_at']),
            ],
            $statement->fetchAll(),
        );
    }

    /**
     * Combien de propriétaires — la question qui garde l'organisation vivante.
     */
    public function ownerCount(string $organizationId): int
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM memberships WHERE organization_id = :org AND role = \'owner\'',
        );

        $statement->execute(['org' => $organizationId]);

        return (int) $statement->fetchColumn();
    }

    // -----------------------------------------------------------------------
    //  Écriture
    // -----------------------------------------------------------------------

    /**
     * Crée une organisation et son premier propriétaire.
     *
     * En transaction, parce qu'une organisation sans membre est irrécupérable :
     * plus personne ne peut y entrer, ni la supprimer.
     *
     * @return array{id: string, name: string, slug: string, kind: string, role: string}
     */
    public function create(string $name, string $ownerId): array
    {
        return Database::transaction(function () use ($name, $ownerId): array {
            $pdo = Database::connection();

            $statement = $pdo->prepare(
                'INSERT INTO organizations (name, slug) VALUES (:name, :slug) RETURNING id, name, slug',
            );
            $statement->execute(['name' => $name, 'slug' => $this->uniqueSlug($name)]);

            /** @var array<string, mixed> $row */
            $row = $statement->fetch();

            $pdo->prepare(
                'INSERT INTO memberships (organization_id, user_id, role) VALUES (:org, :user_id, \'owner\')',
            )->execute(['org' => $row['id'], 'user_id' => $ownerId]);

            return $this->hydrateOrganization($row + ['role' => 'owner']);
        });
    }

    public function rename(string $organizationId, string $name): void
    {
        Database::connection()
            ->prepare('UPDATE organizations SET name = :name WHERE id = :id')
            ->execute(['id' => $organizationId, 'name' => $name]);
    }

    public function delete(string $organizationId): void
    {
        // ON DELETE CASCADE emporte les données de l'espace — c'est le sens
        // même de la suppression, et la raison pour laquelle seul un
        // propriétaire y a droit.
        //
        // La cascade emporte des LIGNES, jamais un schéma : sans le DROP, les
        // tables Backend de l'espace et les données qu'elles ont reçues lui
        // survivraient, inatteignables et pourtant conservées.
        Database::transaction(function () use ($organizationId): void {
            (new SchemaBuilder())->dropSchema($organizationId);

            Database::connection()
                ->prepare('DELETE FROM organizations WHERE id = :id')
                ->execute(['id' => $organizationId]);
        });
    }

    public function setActive(string $userId, string $organizationId): void
    {
        Database::connection()
            ->prepare('UPDATE users SET active_organization_id = :org WHERE id = :user_id')
            ->execute(['org' => $organizationId, 'user_id' => $userId]);
    }

    public function setRole(string $organizationId, string $userId, string $role): void
    {
        Database::connection()
            ->prepare(
                'UPDATE memberships SET role = :role::membership_role
                  WHERE organization_id = :org AND user_id = :user_id',
            )
            ->execute(['org' => $organizationId, 'user_id' => $userId, 'role' => $role]);
    }

    public function removeMember(string $organizationId, string $userId): void
    {
        Database::transaction(function () use ($organizationId, $userId): void {
            $pdo = Database::connection();

            $pdo->prepare('DELETE FROM memberships WHERE organization_id = :org AND user_id = :user_id')
                ->execute(['org' => $organizationId, 'user_id' => $userId]);

            // Sans cela, le compte exclu resterait pointé sur un espace qu'il
            // ne peut plus voir : activeFor() le rattraperait, mais autant ne
            // pas laisser traîner une préférence devenue fausse.
            $pdo->prepare(
                'UPDATE users SET active_organization_id = NULL
                  WHERE id = :user_id AND active_organization_id = :org',
            )->execute(['org' => $organizationId, 'user_id' => $userId]);
        });
    }

    // -----------------------------------------------------------------------
    //  Invitations
    // -----------------------------------------------------------------------

    /**
     * Crée une invitation et renvoie le jeton EN CLAIR.
     *
     * Ce jeton ne doit apparaître que dans le lien envoyé par courriel : la
     * base n'en garde que l'empreinte, exactement comme UserTokenService.
     *
     * Une invitation en cours pour la même adresse est remplacée plutôt que
     * refusée : réinviter quelqu'un est un geste normal, et le lien précédent
     * doit cesser de fonctionner.
     *
     * @return array{id: string, token: string}
     */
    public function invite(string $organizationId, string $email, string $role, string $invitedBy): array
    {
        $token = bin2hex(random_bytes(32));

        $id = Database::transaction(function () use ($organizationId, $email, $role, $invitedBy, $token): string {
            $pdo = Database::connection();

            $pdo->prepare(
                'DELETE FROM invitations
                  WHERE organization_id = :org AND email = :email AND accepted_at IS NULL',
            )->execute(['org' => $organizationId, 'email' => $email]);

            $statement = $pdo->prepare(
                'INSERT INTO invitations (organization_id, email, role, token_hash, invited_by, expires_at)
                 VALUES (:org, :email, :role::membership_role, :hash, :invited_by,
                         NOW() + (:ttl || \' days\')::interval)
              RETURNING id',
            );

            $statement->execute([
                'org'        => $organizationId,
                'email'      => $email,
                'role'       => $role,
                'hash'       => hash('sha256', $token),
                'invited_by' => $invitedBy,
                'ttl'        => (string) self::INVITATION_TTL_DAYS,
            ]);

            return (string) $statement->fetchColumn();
        });

        return ['id' => $id, 'token' => $token];
    }

    /**
     * Les invitations en attente d'une organisation.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingInvitations(string $organizationId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT i.id, i.email, i.role, i.expires_at, i.created_at, u.full_name AS invited_by
               FROM invitations i
          LEFT JOIN users u ON u.id = i.invited_by
              WHERE i.organization_id = :org AND i.accepted_at IS NULL
           ORDER BY i.created_at DESC',
        );

        $statement->execute(['org' => $organizationId]);

        return array_map(
            static fn (array $row): array => [
                'id'         => (string) $row['id'],
                'email'      => (string) $row['email'],
                'role'       => (string) $row['role'],
                'invited_by' => $row['invited_by'] !== null ? (string) $row['invited_by'] : null,
                'expires_at' => Database::toIso($row['expires_at']),
                'created_at' => Database::toIso($row['created_at']),
                // Une invitation périmée reste affichée, marquée comme telle :
                // la faire disparaître laisserait croire qu'elle n'a jamais
                // été envoyée.
                'expired'    => strtotime((string) $row['expires_at']) < time(),
            ],
            $statement->fetchAll(),
        );
    }

    public function revokeInvitation(string $organizationId, string $invitationId): bool
    {
        $statement = Database::connection()->prepare(
            'DELETE FROM invitations
              WHERE id = :id AND organization_id = :org AND accepted_at IS NULL',
        );

        $statement->execute(['id' => $invitationId, 'org' => $organizationId]);

        return $statement->rowCount() > 0;
    }

    /**
     * Lit une invitation à partir de son jeton, SANS la consommer.
     *
     * Sert à l'écran d'accueil du lien : il annonce l'organisation avant de
     * demander à qui que ce soit de créer un compte.
     *
     * @return array<string, mixed>|null
     */
    public function findInvitationByToken(string $token): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT i.id, i.organization_id, i.email, i.role, i.expires_at,
                    o.name AS organization_name, o.slug AS organization_slug
               FROM invitations i
               JOIN organizations o ON o.id = i.organization_id
              WHERE i.token_hash = :hash AND i.accepted_at IS NULL AND i.expires_at > NOW()',
        );

        $statement->execute(['hash' => hash('sha256', $token)]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id'                => (string) $row['id'],
            'organization_id'   => (string) $row['organization_id'],
            'organization_name' => (string) $row['organization_name'],
            'organization_slug' => (string) $row['organization_slug'],
            'email'             => (string) $row['email'],
            'role'              => (string) $row['role'],
            'expires_at'        => Database::toIso($row['expires_at']),
        ];
    }

    /**
     * Consomme l'invitation et fait entrer le compte.
     *
     * @return array{id: string, name: string, slug: string, kind: string, role: string}|null
     *         L'organisation rejointe, ou null si le jeton ne vaut plus rien.
     */
    public function acceptInvitation(string $token, string $userId): ?array
    {
        return Database::transaction(function () use ($token, $userId): ?array {
            $pdo = Database::connection();

            // FOR UPDATE : deux clics sur le même lien ne peuvent pas produire
            // deux acceptations. La seconde trouvera « accepted_at » posé.
            $statement = $pdo->prepare(
                'SELECT i.id, i.organization_id, i.role, o.name, o.slug, o.kind
                   FROM invitations i
                   JOIN organizations o ON o.id = i.organization_id
                  WHERE i.token_hash = :hash AND i.accepted_at IS NULL AND i.expires_at > NOW()
                    FOR UPDATE OF i',
            );

            $statement->execute(['hash' => hash('sha256', $token)]);
            $invitation = $statement->fetch();

            if ($invitation === false) {
                return null;
            }

            $pdo->prepare('UPDATE invitations SET accepted_at = NOW() WHERE id = :id')
                ->execute(['id' => $invitation['id']]);

            // ON CONFLICT plutôt qu'un test préalable : quelqu'un peut être
            // invité alors qu'il est déjà entré autrement. Son rôle actuel est
            // alors conservé — une invitation ne doit pas rétrograder un
            // propriétaire.
            $pdo->prepare(
                'INSERT INTO memberships (organization_id, user_id, role)
                 VALUES (:org, :user_id, :role::membership_role)
                 ON CONFLICT (organization_id, user_id) DO NOTHING',
            )->execute([
                'org'     => $invitation['organization_id'],
                'user_id' => $userId,
                'role'    => $invitation['role'],
            ]);

            $pdo->prepare('UPDATE users SET active_organization_id = :org WHERE id = :user_id')
                ->execute(['org' => $invitation['organization_id'], 'user_id' => $userId]);

            return [
                'id'   => (string) $invitation['organization_id'],
                'name' => (string) $invitation['name'],
                'slug' => (string) $invitation['slug'],
                'kind' => (string) $invitation['kind'],
                'role' => (string) $invitation['role'],
            ];
        });
    }

    // -----------------------------------------------------------------------

    /**
     * Un slug lisible, dérivé du nom, rendu unique par un suffixe si besoin.
     *
     * La boucle s'arrête à la première tentative libre. Elle ne remplace pas
     * la contrainte d'unicité de la base : deux créations simultanées peuvent
     * choisir le même slug, et c'est alors PostgreSQL qui tranche — la boucle
     * évite simplement que le cas courant produise une erreur.
     */
    private function uniqueSlug(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', $this->foldAccents($name)) ?? '', '-');
        $base = $base === '' ? 'espace' : substr($base, 0, 40);

        $pdo       = Database::connection();
        $statement = $pdo->prepare('SELECT 1 FROM organizations WHERE slug = :slug');

        $candidat = $base;

        for ($essai = 2; $essai <= 50; ++$essai) {
            $statement->execute(['slug' => $candidat]);

            if ($statement->fetchColumn() === false) {
                return $candidat;
            }

            $candidat = $base . '-' . $essai;
        }

        // Au-delà, on cesse d'insister : quatre octets d'aléa règlent la
        // question définitivement.
        return $base . '-' . bin2hex(random_bytes(4));
    }

    private function foldAccents(string $value): string
    {
        $translitere = iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return strtolower($translitere === false ? $value : $translitere);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, name: string, slug: string, kind: string, role: string}
     */
    private function hydrateOrganization(array $row): array
    {
        return [
            'id'   => (string) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            // Absente des lignes fraîchement créées par create() : un espace
            // créé par l'API est toujours un espace d'équipe.
            'kind' => (string) ($row['kind'] ?? 'team'),
            'role' => (string) $row['role'],
        ];
    }
}
