<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\SignedUrl;

/**
 * Accès à la table users.
 *
 * Toutes les requêtes sont préparées et paramétrées. Le hash du mot de passe
 * n'est chargé QUE par findByEmailWithPassword(), utilisé uniquement lors de
 * la connexion : il ne peut donc pas fuiter dans une réponse d'API par
 * inadvertance.
 */
final class UserRepository
{
    /**
     * Colonnes exposables au client — jamais password_hash.
     */
    private const PUBLIC_COLUMNS =
        'id, email, full_name, avatar_file_id, role, is_active, email_verified_at, last_login_at, created_at, terms_accepted_at, terms_accepted_version, active_organization_id';

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . ' FROM users WHERE id = :id',
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Recherche par e-mail SANS charger le hash du mot de passe.
     * Utilisée par les parcours qui n'ont pas à le manipuler (mot de passe
     * oublié, renvoi de vérification).
     *
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . ' FROM users WHERE email = :email',
        );
        $statement->execute(['email' => $email]);

        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Marque l'adresse comme confirmée. Idempotent : une seconde confirmation
     * ne modifie pas la date d'origine.
     */
    public function markEmailVerified(string $id): void
    {
        Database::connection()
            ->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = :id AND email_verified_at IS NULL')
            ->execute(['id' => $id]);
    }

    /**
     * Charge un compte avec son hash — réservé à la vérification du mot de passe.
     *
     * @return array<string, mixed>|null
     */
    public function findByEmailWithPassword(string $email): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT ' . self::PUBLIC_COLUMNS . ', password_hash FROM users WHERE email = :email',
        );
        $statement->execute(['email' => $email]);

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        $hash = (string) $row['password_hash'];
        $user = $this->hydrate($row);
        $user['password_hash'] = $hash;

        return $user;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdWithPassword(string $id): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT password_hash FROM users WHERE id = :id',
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row === false ? null : ['password_hash' => (string) $row['password_hash']];
    }

    public function emailExists(string $email): bool
    {
        $statement = Database::connection()->prepare('SELECT 1 FROM users WHERE email = :email');
        $statement->execute(['email' => $email]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Crée un compte. Le trigger users_provision_defaults se charge de ses
     * préférences ; les modules, eux, sont attribués à l'ORGANISATION par
     * organizations_provision_modules — c'est une décision d'équipe.
     *
     * @return array<string, mixed>
     */
    public function create(
        string $email,
        string $passwordHash,
        string $fullName,
        string $termsVersion,
    ): array {
        // L'acceptation est enregistrée dans la MÊME requête que la création :
        // il ne peut donc pas exister de compte sans consentement, même si le
        // processus s'interrompt entre deux instructions.
        $statement = Database::connection()->prepare(
            'INSERT INTO users (email, password_hash, full_name, terms_accepted_at, terms_accepted_version)
             VALUES (:email, :password_hash, :full_name, NOW(), :terms_version)
             RETURNING ' . self::PUBLIC_COLUMNS,
        );

        $statement->execute([
            'email'         => $email,
            'password_hash' => $passwordHash,
            'full_name'     => $fullName,
            'terms_version' => $termsVersion,
        ]);

        /** @var array<string, mixed> $row */
        $row = $statement->fetch();

        return $this->hydrate($row);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateProfile(string $id, string $fullName): array
    {
        $statement = Database::connection()->prepare(
            'UPDATE users
                SET full_name = :full_name
              WHERE id = :id
          RETURNING ' . self::PUBLIC_COLUMNS,
        );

        $statement->execute(['id' => $id, 'full_name' => $fullName]);

        /** @var array<string, mixed> $row */
        $row = $statement->fetch();

        return $this->hydrate($row);
    }

    /**
     * Pose, ou retire, la photo du compte — et rend l'identifiant de la
     * précédente, que l'appelant doit supprimer.
     *
     * Lue et remplacée sous verrou : deux envois simultanés ne peuvent pas
     * rendre la même photo précédente, donc la supprimer deux fois et en
     * laisser une autre orpheline.
     */
    public function replaceAvatar(string $id, ?string $fileId): ?string
    {
        return Database::transaction(function () use ($id, $fileId): ?string {
            $pdo = Database::connection();

            $current = $pdo->prepare('SELECT avatar_file_id FROM users WHERE id = :id FOR UPDATE');
            $current->execute(['id' => $id]);
            $previous = $current->fetchColumn();

            $pdo->prepare('UPDATE users SET avatar_file_id = :file_id WHERE id = :id')
                ->execute(['id' => $id, 'file_id' => $fileId]);

            return is_string($previous) ? $previous : null;
        });
    }

    public function updatePassword(string $id, string $passwordHash): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE users SET password_hash = :password_hash WHERE id = :id',
        );

        $statement->execute(['id' => $id, 'password_hash' => $passwordHash]);
    }

    public function touchLastLogin(string $id): void
    {
        Database::connection()
            ->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
            ->execute(['id' => $id]);
    }

    public function delete(string $id): void
    {
        // ON DELETE CASCADE se charge des données liées (items, sessions...).
        Database::connection()
            ->prepare('DELETE FROM users WHERE id = :id')
            ->execute(['id' => $id]);
    }

    /**
     * Normalise les types renvoyés par PostgreSQL.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id'                => (string) $row['id'],
            'email'             => (string) $row['email'],
            'full_name'         => (string) $row['full_name'],
            // Le nom reste « avatar_url » pour le client, mais l'adresse est
            // SIGNÉE et désigne un fichier rangé par l'application — plus jamais
            // une URL tierce que chaque navigateur de l'équipe irait contacter.
            'avatar_url'        => $row['avatar_file_id'] !== null
                ? SignedUrl::forFile((string) $row['avatar_file_id'])
                : null,
            'role'              => (string) $row['role'],
            'is_active'         => Database::toBool($row['is_active']),
            'email_verified_at' => Database::toIso($row['email_verified_at']),
            'last_login_at'     => Database::toIso($row['last_login_at']),
            'created_at'        => Database::toIso($row['created_at']),
            'terms_accepted_at' => Database::toIso($row['terms_accepted_at']),
            'terms_version'     => $row['terms_accepted_version'] !== null
                ? (string) $row['terms_accepted_version']
                : null,
            // Espace de travail affiché. NULL est une valeur NORMALE et non une
            // anomalie : elle veut dire « la plus ancienne appartenance », et
            // c'est OrganizationRepository::activeFor() qui la résout.
            'active_organization_id' => $row['active_organization_id'] !== null
                ? (string) $row['active_organization_id']
                : null,
        ];
    }
}
