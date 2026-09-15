<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * La journée, vue du tableau de bord : ce qui est parti en production, ce que
 * cela a déclenché, et ce qui revient à la personne qui regarde.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  LA LIGNE DE PRODUCTION RELIE DEUX TABLES QUI NE SE CONNAISSENT PAS     │
 * │                                                                         │
 * │  Déploiements et erreurs vivaient dans deux écrans, avec deux courbes   │
 * │  quotidiennes. Une mise en production qui casse la page panier à        │
 * │  13 h 48 et un pic d'erreurs à 14 h y étaient deux faits séparés,       │
 * │  noyés chacun dans le total de sa journée. Posés sur le même axe,       │
 * │  heure par heure, ils deviennent une cause et son effet — sans que      │
 * │  personne n'ait eu à les relier.                                        │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
final class DashboardDay
{
    /** Tickets de « ma journée » détaillés ; le total est donné à part. */
    public const MY_DAY_LIMIT = 4;

    /**
     * Les dernières heures : mises en production et erreurs par heure.
     *
     * Les tranches sont GLISSANTES et se terminent maintenant, pas des heures
     * d'horloge : la dernière barre compte les soixante dernières minutes. En
     * heures d'horloge, à 14 h 05, elle ne compterait que cinq minutes et le
     * pic qu'on cherche paraîtrait retombé.
     *
     * Les instants sortent en ISO 8601 UTC : le client les place sur l'axe
     * par calcul, et le format natif de PostgreSQL (« 2026-09-14 13:48:00+02 »)
     * n'est pas un format que tous les navigateurs savent lire.
     *
     * Seule la PRODUCTION est tracée. Une branche d'aperçu déployée vingt fois
     * dans l'après-midi ne dit rien de ce que subissent les utilisateurs.
     *
     * @return array{from: string, to: string, hours: list<int>, deployments: list<array{id: string, sha: string, branch: string, status: string, at: string}>}
     */
    public function productionLine(string $organizationId, int $hours = 24): array
    {
        $pdo = Database::connection();

        // NOW() est figé pour toute la transaction : les deux requêtes voient
        // la même fenêtre, et la dernière barre finit exactement à « to ».
        $window = $pdo->prepare(
            "SELECT to_char((NOW() - make_interval(hours => :hours)) AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS depuis,
                    to_char(NOW() AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"')                                  AS jusqua",
        );
        $window->bindValue('hours', $hours, PDO::PARAM_INT);
        $window->execute();

        /** @var array{depuis: string, jusqua: string} $bornes */
        $bornes = $window->fetch();

        $errors = $pdo->prepare(
            'WITH tranches AS (
                 SELECT h,
                        NOW() - make_interval(hours => :hours - h)     AS debut,
                        NOW() - make_interval(hours => :hours - h - 1) AS fin
                   FROM generate_series(0, :hours - 1) AS h
             )
             SELECT t.h, COUNT(e.id) AS total
               FROM tranches t
               LEFT JOIN error_events e
                      ON e.organization_id = :organization_id
                     AND e.occurred_at >= t.debut
                     AND e.occurred_at <  t.fin
              GROUP BY t.h
              ORDER BY t.h',
        );
        $errors->bindValue('hours', $hours, PDO::PARAM_INT);
        $errors->bindValue('organization_id', $organizationId);
        $errors->execute();

        $deployments = $pdo->prepare(
            "SELECT id,
                    branch,
                    LEFT(commit_sha, 7) AS sha,
                    status::text        AS status,
                    to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS at
               FROM deployments
              WHERE organization_id = :organization_id
                AND deleted_at IS NULL
                AND environment = 'production'
                AND created_at >= NOW() - make_interval(hours => :hours)
              ORDER BY created_at
              LIMIT 60",
        );
        $deployments->bindValue('hours', $hours, PDO::PARAM_INT);
        $deployments->bindValue('organization_id', $organizationId);
        $deployments->execute();

        return [
            'from'        => $bornes['depuis'],
            'to'          => $bornes['jusqua'],
            'hours'       => array_map(static fn (array $row): int => (int) $row['total'], $errors->fetchAll()),
            'deployments' => array_map(
                static fn (array $row): array => [
                    'id'     => (string) $row['id'],
                    'sha'    => (string) $row['sha'],
                    'branch' => (string) $row['branch'],
                    'status' => (string) $row['status'],
                    'at'     => (string) $row['at'],
                ],
                $deployments->fetchAll(),
            ),
        ];
    }

    /**
     * Les tickets qui reviennent à cette personne, et combien il y en a.
     *
     * L'échéance d'abord, puis la priorité : un ticket « moyen » pour demain
     * passe avant un ticket « urgent » sans date, parce que le premier a une
     * heure de vérité et pas le second. Les tickets sans échéance ferment la
     * marche plutôt que d'ouvrir la liste — c'est ce que ferait NULL en tri
     * croissant par défaut dans PostgreSQL.
     *
     * @return array{total: int, tickets: list<array{id: string, number: int, title: string, status: string, priority: string, project: ?string, due_date: ?string}>}
     */
    public function myDay(string $organizationId, string $userId): array
    {
        $statement = Database::connection()->prepare(
            "SELECT id, number, title, status::text AS status, priority::text AS priority, project,
                    due_date::text   AS due_date,
                    COUNT(*) OVER () AS total
               FROM tickets
              WHERE organization_id = :organization_id
                AND assigned_to     = :user_id
                AND deleted_at IS NULL
                AND status NOT IN ('done', 'canceled')
              ORDER BY due_date ASC NULLS LAST, priority DESC, created_at DESC
              LIMIT :limit",
        );
        $statement->bindValue('organization_id', $organizationId);
        $statement->bindValue('user_id', $userId);
        $statement->bindValue('limit', self::MY_DAY_LIMIT, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll();

        return [
            // Compté AVANT la limite par la fonction de fenêtre : un seul
            // aller-retour pour les quatre lignes et le total.
            'total'   => $rows === [] ? 0 : (int) $rows[0]['total'],
            'tickets' => array_map(
                static fn (array $row): array => [
                    'id'       => (string) $row['id'],
                    'number'   => (int) $row['number'],
                    'title'    => (string) $row['title'],
                    'status'   => (string) $row['status'],
                    'priority' => (string) $row['priority'],
                    'project'  => $row['project'] === null ? null : (string) $row['project'],
                    'due_date' => $row['due_date'] === null ? null : (string) $row['due_date'],
                ],
                $rows,
            ),
        ];
    }
}
