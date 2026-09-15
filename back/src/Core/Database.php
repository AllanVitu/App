<?php

declare(strict_types=1);

namespace App\Core;

use App\Config\Env;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Connexion PostgreSQL partagée.
 *
 * Une seule instance PDO par requête HTTP (PHP-FPM recycle le processus).
 * Toutes les requêtes de l'application passent par des requêtes PRÉPARÉES :
 * EMULATE_PREPARES est désactivé, donc la préparation est faite par
 * PostgreSQL lui-même — les paramètres ne peuvent jamais être interprétés
 * comme du SQL.
 */
final class Database
{
    private static ?PDO $connection = null;

    private function __construct()
    {
    }

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            Env::get('DB_HOST', 'db'),
            Env::get('DB_PORT', '5432'),
            Env::get('DB_NAME', 'saas_db'),
        );

        try {
            self::$connection = new PDO(
                $dsn,
                Env::get('DB_USER', ''),
                Env::get('DB_PASSWORD', ''),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Requêtes réellement préparées côté serveur (pas d'émulation)
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                    PDO::ATTR_PERSISTENT         => false,
                ],
            );
        } catch (PDOException $e) {
            // Le message d'origine contiendrait les identifiants de connexion.
            throw new RuntimeException('Connexion à la base de données impossible.', 0, $e);
        }

        return self::$connection;
    }

    /**
     * Exécute une closure dans une transaction, avec rollback automatique.
     *
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();

        // Déjà dans une transaction : c'est l'englobante qui valide ou annule.
        // Supprimer un compte supprime des espaces, qui effacent leur schéma —
        // trois gestes qui réussissent ensemble ou pas du tout. Une exception
        // levée ici remonte jusqu'à elle, qui annule le tout.
        $englobante = $pdo->inTransaction();

        if ($englobante) {
            return $callback($pdo);
        }

        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * PostgreSQL renvoie les booléens sous forme 't'/'f' selon le pilote :
     * normalisation en bool PHP pour l'ensemble des dépôts.
     */
    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, ['t', 'true', '1', 1], true);
    }

    /**
     * Décode une colonne JSONB en tableau associatif.
     *
     * @return array<string, mixed>
     */
    public static function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Normalise un horodatage PostgreSQL en ISO 8601.
     *
     * PostgreSQL renvoie « 2026-08-28 16:02:05.396672+00 » : l'espace de
     * séparation et le décalage sur deux chiffres ne sont pas de l'ISO 8601
     * valide, et les moteurs JavaScript refusent de le parser. La conversion
     * est faite ici pour que l'API expose un format standard, exploitable par
     * n'importe quel client.
     */
    public static function toIso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable((string) $value))->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Version « sortie d'API » de toArray().
     *
     * Un tableau PHP vide se sérialise en `[]`, alors que la colonne contient
     * un objet JSON : le cast en stdClass garantit un `{}` côté client et
     * donc un type stable pour le front.
     */
    public static function toObject(mixed $value): \stdClass
    {
        return (object) self::toArray($value);
    }
}
