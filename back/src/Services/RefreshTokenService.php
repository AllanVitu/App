<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;

/**
 * Gestion des jetons de rafraîchissement.
 *
 * Principes de sécurité :
 *  - le jeton est une valeur aléatoire de 32 octets, sans structure lisible ;
 *  - seul son SHA-256 est stocké : une fuite de la base ne permet pas de
 *    rejouer les sessions ;
 *  - il transite dans un cookie HttpOnly (inaccessible au JavaScript, donc
 *    hors de portée d'une injection XSS) ;
 *  - il est tourné à chaque rafraîchissement : un jeton déjà consommé est
 *    définitivement révoqué.
 */
final class RefreshTokenService
{
    public const COOKIE_NAME = 'refresh_token';

    /**
     * Sessions actives conservées par compte, les plus récentes.
     *
     * ┌─────────────────────────────────────────────────────────────────────┐
     * │  SANS PLAFOND, UNE SESSION N'EST PAS UN APPAREIL : C'EST UNE        │
     * │  CONNEXION                                                          │
     * │                                                                     │
     * │  Chaque connexion émet un jeton valable quatorze jours, et rien ne  │
     * │  fermait les précédents. Se connecter tous les matins depuis le     │
     * │  même poste produisait donc quatorze « sessions actives » du même   │
     * │  appareil — une liste illisible, et une table qui ne cesse de       │
     * │  croître.                                                           │
     * │                                                                     │
     * │  Constaté, pas supposé : le compte de démonstration en comptait     │
     * │  1 038, accumulées par les seules exécutions de la suite de tests.  │
     * │  Le défaut existait depuis le premier jour et personne ne pouvait   │
     * │  le voir — c'est l'écran qui liste les sessions qui l'a rendu       │
     * │  visible, avant même d'être livré.                                  │
     * │                                                                     │
     * │  La ROTATION, elle, n'y est pour rien : elle révoque l'ancien jeton │
     * │  avant d'en émettre un neuf, le compte reste stable.                │
     * └─────────────────────────────────────────────────────────────────────┘
     *
     * Dix : au-delà, on ne reconnaît plus ses propres appareils dans la
     * liste, et c'est précisément à ce moment-là qu'on vient la consulter.
     */
    private const MAX_ACTIVE = 10;

    /**
     * Crée un jeton et l'enregistre (haché) pour l'utilisateur.
     *
     * @return string Le jeton en clair — à transmettre au client, jamais à journaliser
     */
    public function issue(string $userId, Request $request): string
    {
        $token = bin2hex(random_bytes(32));
        $ttl   = Env::int('JWT_REFRESH_TTL', 1209600);

        $statement = Database::connection()->prepare(
            'INSERT INTO refresh_tokens (user_id, token_hash, expires_at, user_agent, ip_address)
             VALUES (:user_id, :token_hash, NOW() + (:ttl || \' seconds\')::interval, :user_agent, :ip)',
        );

        $statement->execute([
            'user_id'    => $userId,
            'token_hash' => $this->hash($token),
            'ttl'        => (string) $ttl,
            'user_agent' => $request->userAgent(),
            'ip'         => $request->ip(),
        ]);

        $this->pruneExcess($userId);

        return $token;
    }

    /**
     * Ferme les sessions actives au-delà des MAX_ACTIVE plus récentes.
     *
     * La plus ancienne part la première : c'est la moins susceptible d'être
     * l'appareil qu'on a sous la main, et la seule règle qui ne demande à
     * l'utilisateur aucun arbitrage.
     */
    private function pruneExcess(string $userId): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE refresh_tokens SET revoked_at = NOW()
              WHERE id IN (
                    SELECT id FROM refresh_tokens
                     WHERE user_id = :user_id
                       AND revoked_at IS NULL
                       AND expires_at > NOW()
                     ORDER BY created_at DESC
                    OFFSET :keep
              )',
        );

        $statement->bindValue('user_id', $userId);
        // OFFSET n'accepte pas de paramètre de type chaîne : sans le typage
        // explicite, PostgreSQL reçoit « '10' » et refuse la requête.
        $statement->bindValue('keep', self::MAX_ACTIVE, \PDO::PARAM_INT);
        $statement->execute();
    }

    /**
     * Valide un jeton et le remplace par un nouveau (rotation).
     *
     * @return array{user_id: string, token: string}
     * @throws HttpException 401 si le jeton est inconnu, révoqué ou expiré
     */
    public function rotate(string $token, Request $request): array
    {
        return Database::transaction(function () use ($token, $request): array {
            $pdo = Database::connection();

            // FOR UPDATE : deux rafraîchissements concurrents ne peuvent pas
            // consommer le même jeton.
            $statement = $pdo->prepare(
                'SELECT id, user_id
                   FROM refresh_tokens
                  WHERE token_hash = :hash
                    AND revoked_at IS NULL
                    AND expires_at > NOW()
                  FOR UPDATE',
            );
            $statement->execute(['hash' => $this->hash($token)]);
            $row = $statement->fetch();

            if ($row === false) {
                throw HttpException::unauthorized('Session expirée, veuillez vous reconnecter.');
            }

            $pdo->prepare('UPDATE refresh_tokens SET revoked_at = NOW() WHERE id = :id')
                ->execute(['id' => $row['id']]);

            return [
                'user_id' => (string) $row['user_id'],
                'token'   => $this->issue((string) $row['user_id'], $request),
            ];
        });
    }

    /**
     * Révoque un jeton précis (déconnexion de l'appareil courant).
     */
    public function revoke(string $token): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE refresh_tokens SET revoked_at = NOW()
              WHERE token_hash = :hash AND revoked_at IS NULL',
        );

        $statement->execute(['hash' => $this->hash($token)]);
    }

    /**
     * Révoque toutes les sessions d'un utilisateur.
     * Appelé après un changement de mot de passe : les appareils déjà
     * connectés doivent se réauthentifier.
     */
    public function revokeAllForUser(string $userId): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE refresh_tokens SET revoked_at = NOW()
              WHERE user_id = :user_id AND revoked_at IS NULL',
        );

        $statement->execute(['user_id' => $userId]);
    }

    /**
     * Sessions actives de l'utilisateur, la plus récente d'abord.
     *
     * ┌─────────────────────────────────────────────────────────────────────┐
     * │  CES COLONNES ÉTAIENT REMPLIES DEPUIS LE DÉBUT ET LUES PAR PERSONNE │
     * │                                                                     │
     * │  « user_agent » et « ip_address » sont enregistrés à chaque         │
     * │  émission de jeton. Aucun écran ne les montrait : l'utilisateur ne  │
     * │  pouvait donc ni savoir sur quels appareils sa session était        │
     * │  ouverte, ni en fermer une à distance — le geste qu'on fait quand   │
     * │  on a laissé un ordinateur connecté ailleurs.                       │
     * └─────────────────────────────────────────────────────────────────────┘
     *
     * Le jeton en clair n'est JAMAIS renvoyé, ni même son empreinte : on ne
     * compare que pour marquer la session courante. Un identifiant de ligne
     * suffit ensuite à la révoquer.
     *
     * @return list<array<string, mixed>>
     */
    public function activeSessions(string $userId, ?string $currentToken = null): array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, user_agent, ip_address, created_at, expires_at,
                    token_hash = :current AS is_current
               FROM refresh_tokens
              WHERE user_id = :user_id
                AND revoked_at IS NULL
                AND expires_at > NOW()
              ORDER BY created_at DESC',
        );

        // Une chaîne vide ne peut correspondre à aucune empreinte SHA-256 :
        // sans cookie, aucune ligne n'est marquée courante, ce qui est exact.
        $statement->execute([
            'user_id' => $userId,
            'current' => $currentToken === null ? '' : $this->hash($currentToken),
        ]);

        return array_map(
            static fn (array $row): array => [
                'id'         => (string) $row['id'],
                'user_agent' => $row['user_agent'],
                'ip_address' => $row['ip_address'],
                // PostgreSQL rend « 2026-09-09 19:42:48.87+00 » : l'espace au
                // lieu du T, et surtout un décalage à deux chiffres que
                // « new Date() » refuse — la date s'affichait « — ». Toutes
                // les autres requêtes du projet passent par ici ; celle-ci
                // l'avait oublié.
                'created_at' => Database::toIso($row['created_at']),
                'expires_at' => Database::toIso($row['expires_at']),
                'current'    => (bool) $row['is_current'],
            ],
            $statement->fetchAll(),
        );
    }

    /**
     * Révoque une session désignée par son identifiant.
     *
     * Le « user_id » est dans la clause WHERE et non vérifié après coup :
     * c'est ce qui empêche de fermer la session d'un autre compte en devinant
     * un identifiant. La requête ne trouve simplement rien.
     *
     * @return bool false si la session n'existe pas, appartient à quelqu'un
     *              d'autre, ou était déjà révoquée
     */
    public function revokeById(string $id, string $userId): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE refresh_tokens SET revoked_at = NOW()
              WHERE id = :id AND user_id = :user_id AND revoked_at IS NULL',
        );

        $statement->execute(['id' => $id, 'user_id' => $userId]);

        return $statement->rowCount() > 0;
    }

    /**
     * Révoque toutes les sessions SAUF celle d'où vient la demande.
     *
     * Distinct de « revokeAllForUser », qui coupe tout y compris l'appelant —
     * ce que l'on veut après un changement de mot de passe, et surtout pas
     * ici : « se déconnecter partout ailleurs » ne doit pas déconnecter
     * l'appareil sur lequel on vient de cliquer.
     *
     * @return int nombre de sessions fermées
     */
    public function revokeOthers(string $userId, ?string $currentToken = null): int
    {
        $statement = Database::connection()->prepare(
            'UPDATE refresh_tokens SET revoked_at = NOW()
              WHERE user_id = :user_id
                AND revoked_at IS NULL
                AND token_hash <> :current',
        );

        $statement->execute([
            'user_id' => $userId,
            'current' => $currentToken === null ? '' : $this->hash($currentToken),
        ]);

        return $statement->rowCount();
    }

    /**
     * Dépose le cookie HttpOnly contenant le jeton.
     */
    public function sendCookie(string $token): void
    {
        setcookie(self::COOKIE_NAME, $token, $this->cookieOptions(Env::int('JWT_REFRESH_TTL', 1209600)));
    }

    /**
     * Supprime le cookie côté navigateur (déconnexion).
     */
    public function clearCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', $this->cookieOptions(-3600));
    }

    /**
     * @return array<string, mixed>
     */
    private function cookieOptions(int $lifetime): array
    {
        // En déploiement, front et API sont servis par le même Nginx, donc sur
        // la même origine : « Strict » est alors le réglage le plus sûr.
        // Si l'API est hébergée sur un domaine distinct du front, il faut
        // COOKIE_SAMESITE=None — ce qui impose obligatoirement HTTPS.
        $sameSite = Env::get('COOKIE_SAMESITE', Env::isProduction() ? 'Strict' : 'Lax') ?? 'Lax';
        $sameSite = in_array($sameSite, ['Strict', 'Lax', 'None'], true) ? $sameSite : 'Lax';

        return [
            'expires'  => time() + $lifetime,
            // Restreint l'envoi du cookie aux seules routes d'authentification.
            'path'     => '/api/auth',
            'domain'   => '',
            // Hors développement, le cookie ne doit jamais transiter en clair.
            // SameSite=None est de toute façon ignoré sans l'attribut Secure.
            'secure'   => Env::bool('COOKIE_SECURE', Env::isProduction()) || $sameSite === 'None',
            'httponly' => true,
            'samesite' => $sameSite,
        ];
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
