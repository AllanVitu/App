<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * Recherche transverse : trouver quelque chose sans savoir où il vit.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  LE MANQUE QUE CE SERVICE COMBLE                                    │
 * │                                                                     │
 * │  Chaque module a sa recherche, et chacune ne voit que sa propre     │
 * │  table. Pour retrouver « refresh token », il fallait DÉJÀ SAVOIR    │
 * │  s'il s'agissait d'un ticket, d'un déploiement ou d'une erreur —    │
 * │  et essayer les trois sinon.                                        │
 * │                                                                     │
 * │  C'est précisément la question qu'on ne peut pas se poser : on se   │
 * │  souvient d'un mot, pas d'un module.                                 │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * UNE SEULE REQUÊTE, par UNION ALL, comme le fil d'activité. Cinq requêtes
 * séparées obligeraient à trier et à tronquer en PHP, donc à rapatrier N
 * lignes par module pour n'en garder que N au total.
 *
 * Chaque branche projette la MÊME FORME — module, identifiant, référence
 * courte, titre, sous-titre, date — pour que le client affiche n'importe quel
 * résultat sans rien savoir du module dont il provient.
 *
 * LES ACCENTS SONT REPLIÉS DES DEUX CÔTÉS de la comparaison, colonne et
 * motif : « systeme » doit trouver « Système ». Personne ne tape les accents
 * dans un champ de recherche, et ILIKE ne les replie pas de lui-même. Replier
 * un seul côté ne servirait à rien — « Système » replié ne correspondrait
 * toujours pas à un motif qui, lui, ne l'est pas.
 *
 * LE CLOISONNEMENT EST DANS CHAQUE BRANCHE, pas dans un filtre global posé
 * après coup : une branche qui oublierait `organization_id` livrerait les données
 * d'autrui, et aucun test de module ne le verrait.
 */
final class SearchService
{
    /**
     * Résultats par module, pour qu'un module bavard n'écrase pas les autres.
     *
     * Sans cette borne, chercher « a » remonterait cent tickets et pas une
     * seule erreur — alors que c'est justement l'erreur qu'on cherchait.
     */
    private const PAR_MODULE = 5;

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $organizationId, string $terme, int $limit = 20): array
    {
        $terme = trim($terme);

        // Un seul caractère remonterait presque tout : ce n'est pas une
        // recherche, c'est un listing.
        if (mb_strlen($terme) < 2) {
            return [];
        }

        $statement = Database::connection()->prepare(
            "WITH resultats AS (
                 (SELECT 'tickets'::text AS module,
                         t.id,
                         '#' || t.number   AS ref,
                         t.title           AS title,
                         COALESCE(t.project, t.status::text) AS subtitle,
                         t.updated_at      AS happened_at
                    FROM tickets t
                   WHERE t.organization_id = :organization_id AND t.deleted_at IS NULL
                     AND (unaccent(t.title) ILIKE unaccent(:terme)
                          OR unaccent(t.description) ILIKE unaccent(:terme)
                          OR unaccent(t.project) ILIKE unaccent(:terme)
                          OR t.number::text = :exact)
                   ORDER BY t.updated_at DESC
                   LIMIT :par_module)

                 UNION ALL

                 (SELECT 'backend',
                         b.id,
                         b.name,
                         b.name,
                         COALESCE(b.description, 'Schéma de données'),
                         b.updated_at
                    FROM backend_tables b
                   WHERE b.organization_id = :organization_id AND b.deleted_at IS NULL
                     AND (unaccent(b.name) ILIKE unaccent(:terme) OR unaccent(b.description) ILIKE unaccent(:terme))
                   ORDER BY b.updated_at DESC
                   LIMIT :par_module)

                 UNION ALL

                 (SELECT 'deploiement',
                         d.id,
                         d.branch || '@' || left(d.commit_sha, 7),
                         COALESCE(d.commit_message, 'Déploiement'),
                         d.status::text,
                         d.created_at
                    FROM deployments d
                   WHERE d.organization_id = :organization_id AND d.deleted_at IS NULL
                     AND (unaccent(d.branch) ILIKE unaccent(:terme)
                          OR unaccent(d.commit_message) ILIKE unaccent(:terme)
                          OR d.commit_sha ILIKE :terme)
                   ORDER BY d.created_at DESC
                   LIMIT :par_module)

                 UNION ALL

                 (SELECT 'supervision',
                         g.id,
                         '×' || g.occurrences,
                         g.title,
                         COALESCE(g.culprit, g.level::text),
                         g.last_seen_at
                    FROM error_groups g
                   WHERE g.organization_id = :organization_id AND g.deleted_at IS NULL
                     AND (unaccent(g.title) ILIKE unaccent(:terme) OR unaccent(g.culprit) ILIKE unaccent(:terme))
                   ORDER BY g.last_seen_at DESC
                   LIMIT :par_module)

                 UNION ALL

                 (SELECT 'design',
                         f.id,
                         f.kind::text,
                         f.name,
                         COALESCE(f.description, f.kind::text),
                         f.updated_at
                    FROM design_files f
                   WHERE f.organization_id = :organization_id AND f.deleted_at IS NULL
                     AND (unaccent(f.name) ILIKE unaccent(:terme) OR unaccent(f.description) ILIKE unaccent(:terme))
                   ORDER BY f.updated_at DESC
                   LIMIT :par_module)

                 UNION ALL

                 -- L'hôte en référence : c'est ce qu'on reconnaît d'une sonde.
                 (SELECT 'disponibilite',
                         p.id,
                         split_part(split_part(p.url, '://', 2), '/', 1),
                         p.name,
                         CASE WHEN p.is_paused THEN 'en pause' ELSE p.url END,
                         p.updated_at
                    FROM probes p
                   WHERE p.organization_id = :organization_id AND p.deleted_at IS NULL
                     AND (unaccent(p.name) ILIKE unaccent(:terme) OR p.url ILIKE :terme)
                   ORDER BY p.updated_at DESC
                   LIMIT :par_module)

                 UNION ALL

                 -- Le TEXTE des pages aussi : on cherche une procédure par ce
                 -- qu'elle dit, rarement par son titre exact.
                 (SELECT 'documentation',
                         dp.id,
                         'page',
                         dp.title,
                         COALESCE(
                             (SELECT parent.title FROM doc_pages parent
                               WHERE parent.id = dp.parent_id AND parent.deleted_at IS NULL),
                             'Documentation'
                         ),
                         dp.updated_at
                    FROM doc_pages dp
                   WHERE dp.organization_id = :organization_id AND dp.deleted_at IS NULL
                     AND (unaccent(dp.title) ILIKE unaccent(:terme) OR unaccent(dp.body) ILIKE unaccent(:terme))
                   ORDER BY dp.updated_at DESC
                   LIMIT :par_module)
             )
             SELECT module, id::text AS id, ref, title, subtitle, happened_at
               FROM resultats
              ORDER BY happened_at DESC
              LIMIT :limit",
        );

        $motif = '%' . $this->escapeLike($terme) . '%';

        $statement->bindValue('organization_id', $organizationId);
        $statement->bindValue('terme', $motif);
        // Le numéro de ticket se cherche à l'IDENTIQUE : « 12 » doit trouver
        // le ticket 12, pas les ticket 120 à 129 en plus.
        $statement->bindValue('exact', $terme);
        $statement->bindValue('par_module', self::PAR_MODULE, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): array => [
                'module'      => (string) $row['module'],
                'id'          => (string) $row['id'],
                'ref'         => (string) $row['ref'],
                'title'       => (string) $row['title'],
                'subtitle'    => (string) $row['subtitle'],
                'happened_at' => Database::toIso($row['happened_at']),
            ],
            $statement->fetchAll(),
        );
    }

    /**
     * Neutralise les caractères que LIKE interprète. Sans cela, chercher
     * « 100% » remonterait tout, et « _ » n'importe quoi.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
