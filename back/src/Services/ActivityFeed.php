<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * Les CHIFFRES du tableau de bord, tous modules confondus.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  CE QUI A QUITTÉ CETTE CLASSE, ET POURQUOI                              │
 * │                                                                         │
 * │  Elle assemblait aussi le fil d'activité, par UNION ALL sur les cinq    │
 * │  tables métier. Ce fil ne pouvait montrer que des CRÉATIONS : une table │
 * │  de données ne garde aucune trace de ce qui l'a modifiée, ni de qui.    │
 * │                                                                         │
 * │  Depuis qu'un vrai journal existe, deux sources décrivaient les mêmes   │
 * │  faits sans pouvoir s'accorder. La lecture du fil revient donc à        │
 * │  ActivityRepository, et cette classe garde ce qu'elle seule sait faire :│
 * │  compter et agréger dans le temps.                                      │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Ce qui reste ne LIT PAS le journal, et c'est délibéré : un compte de
 * tickets ouverts est un ÉTAT, pas une somme d'événements. Le déduire du
 * journal obligerait à rejouer l'histoire pour obtenir un chiffre que la
 * table métier donne directement — et se tromperait sur toute donnée
 * antérieure au journal.
 */
final class ActivityFeed
{
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
