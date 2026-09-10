<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * Fil d'activité, tous modules confondus.
 *
 * Chaque module ayant sa propre table, l'activité récente ne peut plus se
 * lire dans module_items : elle y montrerait des lignes qu'aucun écran
 * n'affiche plus, et cliquer dessus mènerait à un module où elles n'existent
 * pas.
 *
 * UNE seule requête, par UNION ALL, plutôt que cinq requêtes triées en PHP :
 * il faut les N plus récents TOUS MODULES CONFONDUS, ce qui obligerait sinon
 * à rapatrier N lignes par module pour n'en garder que N au total.
 *
 * Chaque branche projette la même forme — module, identifiant, référence
 * courte, titre, date — pour que le client affiche n'importe quelle ligne
 * sans rien savoir du module dont elle provient.
 */
final class ActivityFeed
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forOrganization(string $organizationId, int $limit = 8): array
    {
        $statement = Database::connection()->prepare(
            "SELECT module, id::text AS id, ref, title, happened_at
               FROM (
                   SELECT 'tickets'::text AS module,
                          t.id,
                          '#' || t.number   AS ref,
                          t.title           AS title,
                          t.updated_at      AS happened_at
                     FROM tickets t
                    WHERE t.organization_id = :organization_id AND t.deleted_at IS NULL

                   UNION ALL

                   SELECT 'backend',
                          b.id,
                          b.name,
                          COALESCE(b.description, 'Schéma de données'),
                          b.updated_at
                     FROM backend_tables b
                    WHERE b.organization_id = :organization_id AND b.deleted_at IS NULL

                   UNION ALL

                   SELECT 'deploiement',
                          d.id,
                          d.branch || '@' || left(d.commit_sha, 7),
                          COALESCE(d.commit_message, 'Déploiement'),
                          d.updated_at
                     FROM deployments d
                    WHERE d.organization_id = :organization_id AND d.deleted_at IS NULL

                   UNION ALL

                   -- last_seen_at et non updated_at : pour une erreur, ce qui
                   -- constitue l'événement est sa dernière occurrence, pas la
                   -- dernière fois qu'on a changé son statut.
                   SELECT 'supervision',
                          g.id,
                          '×' || g.occurrences,
                          g.title,
                          g.last_seen_at
                     FROM error_groups g
                    WHERE g.organization_id = :organization_id AND g.deleted_at IS NULL

                   UNION ALL

                   SELECT 'design',
                          f.id,
                          'v' || COALESCE((SELECT MAX(number) FROM design_versions WHERE file_id = f.id), 1),
                          f.name,
                          f.updated_at
                     FROM design_files f
                    WHERE f.organization_id = :organization_id AND f.deleted_at IS NULL
               ) AS activite
              ORDER BY happened_at DESC
              LIMIT :limit",
        );

        $statement->bindValue('organization_id', $organizationId);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): array => [
                'module'      => (string) $row['module'],
                'id'          => (string) $row['id'],
                'ref'         => (string) $row['ref'],
                'title'       => (string) $row['title'],
                'happened_at' => Database::toIso($row['happened_at']),
            ],
            $statement->fetchAll(),
        );
    }

    /**
     * Activité quotidienne, pour les deux courbes du tableau de bord.
     *
     * DEUX SÉRIES, DEUX GRAPHIQUES — jamais deux axes sur un même cadre. Un
     * déploiement par jour et quarante erreurs par jour n'ont pas d'unité
     * commune : superposés sur une échelle unique, la série basse est écrasée
     * contre l'axe ; sur deux échelles, le point de croisement des courbes ne
     * veut plus rien dire, et le lecteur y lit pourtant quelque chose.
     *
     * La grille de dates est produite par generate_series, PAS par les
     * données : un jour sans déploiement doit valoir zéro et occuper sa
     * place. Sans cela, la courbe relie le 3 au 7 par un trait droit et
     * raconte une régularité qui n'a pas existé.
     *
     * Une seule requête, et un seul balayage par table : les agrégats sont
     * calculés à part puis rattachés à la grille. La variante « une
     * sous-requête corrélée par jour » lisait chaque table quatorze fois.
     *
     * @return list<array{date: string, deployments: int, errors: int}>
     */
    public function dailySeries(string $organizationId, int $days = 14): array
    {
        $statement = Database::connection()->prepare(
            "WITH jours AS (
                 SELECT generate_series(
                            CURRENT_DATE - make_interval(days => :days - 1),
                            CURRENT_DATE,
                            INTERVAL '1 day'
                        )::date AS jour
             ),
             -- Borne dérivée de la grille : une seule source pour la fenêtre.
             bornes AS (SELECT MIN(jour) AS depuis FROM jours),
             deploiements AS (
                 SELECT created_at::date AS jour, COUNT(*) AS total
                   FROM deployments
                  WHERE organization_id = :organization_id
                    AND deleted_at IS NULL
                    AND created_at >= (SELECT depuis FROM bornes)
                  GROUP BY 1
             ),
             erreurs AS (
                 SELECT occurred_at::date AS jour, COUNT(*) AS total
                   FROM error_events
                  WHERE organization_id = :organization_id
                    AND occurred_at >= (SELECT depuis FROM bornes)
                  GROUP BY 1
             )
             SELECT j.jour::text          AS date,
                    COALESCE(d.total, 0)  AS deployments,
                    COALESCE(e.total, 0)  AS errors
               FROM jours j
               LEFT JOIN deploiements d ON d.jour = j.jour
               LEFT JOIN erreurs      e ON e.jour = j.jour
              ORDER BY j.jour",
        );

        $statement->bindValue('organization_id', $organizationId);
        $statement->bindValue('days', $days, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): array => [
                'date'        => (string) $row['date'],
                'deployments' => (int) $row['deployments'],
                'errors'      => (int) $row['errors'],
            ],
            $statement->fetchAll(),
        );
    }

    /**
     * Les quatre chiffres de tête, et leur comparaison à la période
     * précédente.
     *
     * TROIS SUR QUATRE seulement portent une comparaison, et c'est délibéré :
     * les tickets ouverts sont un ÉTAT, pas un flux. « 12 tickets ouverts,
     * +3 » laisserait croire qu'il s'en est créé trois, alors que le chiffre
     * peut avoir monté parce qu'on en a fermé moins. Un état se compare à un
     * seuil, pas à la semaine dernière.
     *
     * @return array<string, mixed>
     */
    public function summary(string $organizationId, int $days = 7): array
    {
        $statement = Database::connection()->prepare(
            "WITH fenetre AS (
                 SELECT (NOW() - make_interval(days => :days))     AS debut,
                        (NOW() - make_interval(days => :days * 2)) AS debut_precedent
             )
             SELECT
                 (SELECT COUNT(*) FROM deployments, fenetre
                   WHERE organization_id = :organization_id AND deleted_at IS NULL
                     AND created_at >= fenetre.debut)                      AS deployments,
                 (SELECT COUNT(*) FROM deployments, fenetre
                   WHERE organization_id = :organization_id AND deleted_at IS NULL
                     AND created_at >= fenetre.debut_precedent
                     AND created_at <  fenetre.debut)                      AS deployments_before,
                 (SELECT COUNT(*) FROM deployments, fenetre
                   WHERE organization_id = :organization_id AND deleted_at IS NULL
                     AND status = 'error'
                     AND created_at >= fenetre.debut)                      AS failed,
                 (SELECT COUNT(*) FROM deployments, fenetre
                   WHERE organization_id = :organization_id AND deleted_at IS NULL
                     AND status = 'error'
                     AND created_at >= fenetre.debut_precedent
                     AND created_at <  fenetre.debut)                      AS failed_before,
                 (SELECT COUNT(*) FROM error_events, fenetre
                   WHERE organization_id = :organization_id
                     AND occurred_at >= fenetre.debut)                     AS errors,
                 (SELECT COUNT(*) FROM error_events, fenetre
                   WHERE organization_id = :organization_id
                     AND occurred_at >= fenetre.debut_precedent
                     AND occurred_at <  fenetre.debut)                     AS errors_before,
                 -- État, pas flux : aucune fenêtre, aucune comparaison.
                 (SELECT COUNT(*) FROM tickets
                   WHERE organization_id = :organization_id AND deleted_at IS NULL
                     AND status <> 'done')                                 AS open_tickets",
        );

        $statement->bindValue('organization_id', $organizationId);
        $statement->bindValue('days', $days, PDO::PARAM_INT);
        $statement->execute();

        /** @var array<string, mixed> $row */
        $row = $statement->fetch();

        $deployments  = (int) $row['deployments'];
        $failed       = (int) $row['failed'];
        $before       = (int) $row['deployments_before'];
        $failedBefore = (int) $row['failed_before'];

        return [
            'days'        => $days,
            'deployments' => ['value' => $deployments, 'previous' => $before],
            // Taux de RÉUSSITE et non d'échec : la barre monte quand la
            // situation s'améliore. Null plutôt que 100 % quand il n'y a eu
            // aucun déploiement — « tout a réussi » sur zéro tentative est
            // une phrase vide.
            'success_rate' => [
                'value'    => $deployments > 0
                    ? (int) round(100 * ($deployments - $failed) / $deployments)
                    : null,
                'previous' => $before > 0
                    ? (int) round(100 * ($before - $failedBefore) / $before)
                    : null,
            ],
            'errors'       => ['value' => (int) $row['errors'], 'previous' => (int) $row['errors_before']],
            'open_tickets' => ['value' => (int) $row['open_tickets'], 'previous' => null],
        ];
    }
}
