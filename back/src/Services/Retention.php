<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Les durées de conservation, appliquées.
 *
 * Chaque méthode correspond à une ligne de la politique de confidentialité
 * (front/src/utils/legal.js) et à une tâche planifiée (migration
 * « conformite »). Une durée publiée sans purge est une promesse fausse ; une
 * purge sans durée publiée est une décision cachée. Les deux ne vont pas l'une
 * sans l'autre.
 *
 * Toutes renvoient le nombre de lignes effacées : le worker le journalise, et
 * les tests s'en servent.
 */
final class Retention
{
    /** Tentatives de connexion : de quoi détecter une attaque, pas un historique. */
    public function loginAttempts(int $days): int
    {
        return $this->effacer('DELETE FROM login_attempts WHERE attempted_at < NOW() - make_interval(days => :jours)', $days);
    }

    /** L'historique d'un espace : un an, le temps de répondre à « qui a fait ça ? ». */
    public function activity(int $days): int
    {
        return $this->effacer('DELETE FROM activity WHERE happened_at < NOW() - make_interval(days => :jours)', $days);
    }

    /**
     * Les occurrences d'erreurs reçues : leur contexte peut contenir des
     * données des utilisateurs de l'équipe. Le groupe, lui, reste — avec son
     * compteur — tant qu'il n'est pas supprimé.
     */
    public function errorEvents(int $days): int
    {
        return $this->effacer('DELETE FROM error_events WHERE occurred_at < NOW() - make_interval(days => :jours)', $days);
    }

    /**
     * Les tâches en échec. Une tâche réussie est déjà effacée par la file ;
     * une tâche en échec garde sa charge — un e-mail, son destinataire, parfois
     * un lien — le temps de comprendre l'échec, pas davantage.
     */
    public function failedJobs(int $days): int
    {
        return $this->effacer(
            'DELETE FROM jobs WHERE failed_at IS NOT NULL AND failed_at < NOW() - make_interval(days => :jours)',
            $days,
        );
    }

    /** Une invitation acceptée ou expirée ne sert plus qu'à garder une adresse e-mail. */
    public function invitations(int $days): int
    {
        return $this->effacer(
            'DELETE FROM invitations
              WHERE (accepted_at IS NOT NULL AND accepted_at < NOW() - make_interval(days => :jours))
                 OR (accepted_at IS NULL AND expires_at < NOW() - make_interval(days => :jours))',
            $days,
        );
    }

    /** Liens de confirmation et de réinitialisation, une fois utilisés ou expirés. */
    public function userTokens(int $days): int
    {
        return $this->effacer(
            'DELETE FROM user_tokens
              WHERE (used_at IS NOT NULL AND used_at < NOW() - make_interval(days => :jours))
                 OR expires_at < NOW() - make_interval(days => :jours)',
            $days,
        );
    }

    /** « Qui regarde quoi » n'a de sens que dans l'instant. */
    public function presence(): int
    {
        $statement = Database::connection()->prepare("DELETE FROM presence WHERE seen_at < NOW() - INTERVAL '1 day'");
        $statement->execute();

        return $statement->rowCount();
    }

    /**
     * La corbeille : ce qui a été supprimé il y a plus de N jours part pour de
     * bon.
     *
     * Une seule transaction : la corbeille se vide entière ou pas du tout.
     *
     * L'ordre compte. Les fichiers des versions de Design d'abord — la base ne
     * les suit pas en cascade (la version perd son lien, le fichier resterait
     * sur le disque) ; leur suppression pose une pierre tombale que la purge
     * des fichiers efface ensuite du disque. Les pages de documentation par
     * les feuilles, parce qu'une page parente est protégée tant qu'elle a des
     * enfants (ON DELETE RESTRICT).
     */
    public function trash(int $days): int
    {
        $total = Database::transaction(function () use ($days): int {
            $total = $this->effacer(
                'DELETE FROM stored_files
                  WHERE id IN (
                      SELECT v.asset_id
                        FROM design_versions v
                        JOIN design_files f ON f.id = v.file_id
                       WHERE v.asset_id IS NOT NULL
                         AND f.deleted_at < NOW() - make_interval(days => :jours)
                  )',
                $days,
            );

            // Les commentaires d'abord : ceux d'un ticket purgé partiraient en
            // cascade de toute façon, mais un commentaire retiré sous un ticket
            // vivant doit partir lui aussi.
            foreach (['ticket_comments', 'tickets', 'deployments', 'error_groups', 'module_items', 'design_files', 'probes', 'backend_tables'] as $table) {
                $total += $this->effacer(
                    "DELETE FROM {$table} WHERE deleted_at < NOW() - make_interval(days => :jours)",
                    $days,
                );
            }

            // Par les feuilles, jusqu'à ce qu'il n'y ait plus rien à effacer.
            // La profondeur d'un arbre de documentation se compte en unités :
            // cent passes sont une borne de sûreté, pas une attente.
            for ($passe = 0; $passe < 100; $passe++) {
                $effacees = $this->effacer(
                    'DELETE FROM doc_pages p
                      WHERE p.deleted_at < NOW() - make_interval(days => :jours)
                        AND NOT EXISTS (SELECT 1 FROM doc_pages enfant WHERE enfant.parent_id = p.id)',
                    $days,
                );

                if ($effacees === 0) {
                    break;
                }

                $total += $effacees;
            }

            return $total;
        });

        if ($total > 0) {
            Queue::push('storage.purge');
        }

        return (int) $total;
    }

    /** Un défi de connexion expiré ne sert plus à rien. */
    public function twoFactorChallenges(): int
    {
        $statement = Database::connection()->prepare('DELETE FROM two_factor_challenges WHERE expires_at < NOW()');
        $statement->execute();

        return $statement->rowCount();
    }

    private function effacer(string $sql, int $days): int
    {
        $statement = Database::connection()->prepare($sql);
        $statement->bindValue('jours', max(1, $days), \PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }
}
