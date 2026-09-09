<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Ce que chaque type de tâche fait réellement.
 *
 * Le worker ne connaît aucun métier : il réserve, rend le type à ce registre,
 * et acquitte. Ajouter une tâche de fond, c'est ajouter une entrée ici — pas
 * toucher à la boucle.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  CHAQUE GESTIONNAIRE DOIT SUPPORTER D'ÊTRE REJOUÉ                       │
 * │                                                                         │
 * │  La file garantit « au moins une fois », jamais « exactement une        │
 * │  fois » : un worker tué entre le travail et l'acquittement fera         │
 * │  reprendre la tâche. Envoyer deux fois un e-mail de confirmation est    │
 * │  ennuyeux ; ne jamais l'envoyer empêche de créer un compte.             │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
final class JobHandlers
{
    /**
     * Exécute une tâche. Toute exception remonte au worker, qui décidera de
     * la reprogrammer ou de l'abandonner.
     *
     * @param array<string, mixed> $payload
     */
    public static function handle(string $type, array $payload): void
    {
        match ($type) {
            'mail.send'    => self::sendMail($payload),
            'tokens.purge' => self::purgeTokens($payload),
            default        => throw new \RuntimeException("Type de tâche inconnu : « {$type} »."),
        };
    }

    /**
     * Envoi d'e-mail, sorti du chemin de la requête.
     *
     * Jusqu'ici, une inscription attendait le serveur SMTP pour rendre la
     * main : un serveur lent rendait l'inscription lente, un serveur muet la
     * faisait expirer. Le lien de confirmation part désormais d'ici, et la
     * réponse HTTP n'attend plus que la base.
     *
     * @param array<string, mixed> $payload
     */
    private static function sendMail(array $payload): void
    {
        foreach (['to', 'name', 'subject', 'html', 'text'] as $champ) {
            if (!isset($payload[$champ]) || !is_string($payload[$champ])) {
                throw new \RuntimeException("Tâche « mail.send » : champ « {$champ} » manquant.");
            }
        }

        (new Mailer())->send(
            (string) $payload['to'],
            (string) $payload['name'],
            (string) $payload['subject'],
            (string) $payload['html'],
            (string) $payload['text'],
        );
    }

    /**
     * Purge des jetons de rafraîchissement qui ne servent plus.
     *
     * Constat à l'origine : 2 082 lignes accumulées sur une base de
     * développement, dont aucune ne pouvait plus rien ouvrir. La rotation en
     * produit une à chaque rafraîchissement, indéfiniment.
     *
     * On ne garde QUE ce qui pourrait servir à enquêter — un jeton révoqué la
     * semaine dernière dit d'où venait une session suspecte. Au-delà, c'est de
     * l'archive que personne ne lira.
     *
     * @param array<string, mixed> $payload
     */
    private static function purgeTokens(array $payload): void
    {
        $retention = max(1, (int) ($payload['retention_days'] ?? 14));

        $statement = Database::connection()->prepare(
            'DELETE FROM refresh_tokens
              WHERE (revoked_at IS NOT NULL AND revoked_at < NOW() - (:jours || \' days\')::interval)
                 OR (expires_at < NOW() - (:jours || \' days\')::interval)',
        );

        $statement->execute(['jours' => (string) $retention]);
    }
}
