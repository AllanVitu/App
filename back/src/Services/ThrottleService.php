<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

/**
 * Limitation de débit des actions sensibles (anti-bruteforce, anti-abus).
 *
 * Le comptage porte à la fois sur la cible (e-mail) et sur l'adresse IP :
 *  - par e-mail, pour protéger un compte précis ;
 *  - par IP, pour freiner le balayage de nombreux comptes depuis une machine.
 *
 * Le même journal couvre plusieurs actions (colonne `action`) : connexion,
 * demande de réinitialisation, renvoi de vérification. Il sert aussi
 * d'historique de sécurité consultable.
 */
final class ThrottleService
{
    public const ACTION_LOGIN              = 'login';
    public const ACTION_PASSWORD_RESET     = 'password_reset';
    public const ACTION_EMAIL_VERIFICATION = 'email_verification';

    /**
     * Quotas par action : [max par e-mail, max par IP, fenêtre en minutes].
     *
     * Les demandes d'e-mail sont plus strictement limitées que la connexion :
     * chacune déclenche un envoi réel, donc un coût et un risque de nuisance
     * pour le destinataire.
     *
     * @var array<string, array{0: int, 1: int, 2: int}>
     */
    private const QUOTAS = [
        self::ACTION_LOGIN              => [5, 20, 15],
        self::ACTION_PASSWORD_RESET     => [3, 10, 15],
        self::ACTION_EMAIL_VERIFICATION => [3, 10, 15],
    ];

    /**
     * Bloque la requête si le quota de l'action est atteint.
     *
     * @throws HttpException 429
     */
    public function ensureNotLocked(string $email, ?string $ip, string $action = self::ACTION_LOGIN): void
    {
        [$maxPerEmail, $maxPerIp, $window] = self::QUOTAS[$action] ?? self::QUOTAS[self::ACTION_LOGIN];

        $statement = Database::connection()->prepare(
            'SELECT
                 count(*) FILTER (WHERE email = :email)   AS by_email,
                 count(*) FILTER (WHERE ip_address = :ip) AS by_ip
               FROM login_attempts
              WHERE successful = FALSE
                AND action = :action
                AND attempted_at > NOW() - (:window || \' minutes\')::interval',
        );

        $statement->execute([
            'email'  => $email,
            'ip'     => $ip,
            'action' => $action,
            'window' => (string) $window,
        ]);

        $counts = $statement->fetch() ?: ['by_email' => 0, 'by_ip' => 0];

        if ((int) $counts['by_email'] >= $maxPerEmail || (int) $counts['by_ip'] >= $maxPerIp) {
            throw HttpException::tooManyRequests(
                "Trop de tentatives. Réessayez dans {$window} minutes.",
            );
        }
    }

    /**
     * Journalise une tentative.
     *
     * Un succès purge les échecs récents pour cette action et cet e-mail :
     * un utilisateur légitime qui s'est trompé de frappe ne reste pas pénalisé.
     */
    public function record(
        string $email,
        ?string $ip,
        bool $successful,
        string $action = self::ACTION_LOGIN,
    ): void {
        $pdo = Database::connection();

        $pdo->prepare(
            'INSERT INTO login_attempts (email, ip_address, successful, action)
             VALUES (:email, :ip, :ok, :action)',
        )->execute([
            'email'  => $email,
            'ip'     => $ip,
            'ok'     => $successful ? 'true' : 'false',
            'action' => $action,
        ]);

        if ($successful) {
            $window = (self::QUOTAS[$action] ?? self::QUOTAS[self::ACTION_LOGIN])[2];

            $pdo->prepare(
                'DELETE FROM login_attempts
                  WHERE email = :email
                    AND action = :action
                    AND successful = FALSE
                    AND attempted_at > NOW() - (:window || \' minutes\')::interval',
            )->execute([
                'email'  => $email,
                'action' => $action,
                'window' => (string) $window,
            ]);
        }
    }

    /**
     * Purge le journal au-delà de 30 jours : il n'a d'intérêt qu'à court terme
     * et contient des adresses e-mail, donc des données personnelles.
     */
    public function purgeOld(): void
    {
        Database::connection()->exec(
            "DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL '30 days'",
        );
    }
}
