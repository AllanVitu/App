<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Le journal d'activité — et le flux qui le suit.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  UNE SOURCE, DEUX LECTURES                                              │
 * │                                                                         │
 * │  « recent() » lit à l'envers pour le fil du tableau de bord.            │
 * │  « since() » lit à l'endroit pour le flux temps réel.                   │
 * │                                                                         │
 * │  La même table, donc les mêmes faits. Diffuser d'un côté et journaliser │
 * │  de l'autre aurait fait deux chemins qui divergeraient au premier oubli.│
 * └─────────────────────────────────────────────────────────────────────────┘
 */
final class ActivityRepository
{
    /** Au-delà, on cesse de rattraper : le client recharge (cf. since()). */
    public const RETARD_MAX = 200;

    /**
     * Enregistre un fait.
     *
     * Appelée DANS la transaction qui écrit la donnée, jamais après : un
     * journal qui pourrait manquer une ligne validée ne serait pas un
     * journal, et le flux qui le suit manquerait le même événement.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changes { champ: [avant, après] }
     */
    public function record(
        string $organizationId,
        ?string $actorId,
        ?string $actorName,
        string $module,
        string $action,
        string $subjectId,
        ?string $subjectRef = null,
        ?string $subjectTitle = null,
        array $changes = [],
        ?int $subjectVersion = null,
    ): void {
        Database::connection()->prepare(
            'INSERT INTO activity
                 (organization_id, actor_id, actor_name, module, action,
                  subject_id, subject_ref, subject_title, changes, subject_version)
             VALUES (:org, :actor_id, :actor_name, :module, :action,
                     :subject_id, :subject_ref, :subject_title, :changes::jsonb, :subject_version)',
        )->execute([
            'org'           => $organizationId,
            'actor_id'      => $actorId,
            // Recopié plutôt que joint : le journal doit rester lisible après
            // le départ de son auteur (cf. la migration).
            'actor_name'    => $actorName,
            'module'        => $module,
            'action'        => $action,
            'subject_id'    => $subjectId,
            'subject_ref'   => $subjectRef,
            'subject_title' => $subjectTitle !== null ? mb_substr($subjectTitle, 0, 200) : null,
            'changes'       => json_encode($changes, JSON_UNESCAPED_UNICODE),
            // La version APRÈS ce fait : c'est elle qui permet de répondre
            // « depuis ta version, voilà ce qui a bougé ».
            'subject_version' => $subjectVersion,
        ]);
    }

    /**
     * Ce qui s'est passé depuis un curseur — le flux.
     *
     * ┌───────────────────────────────────────────────────────────────────────┐
     * │  LA CLAUSE QUI EMPÊCHE UN ÉVÉNEMENT DE SE PERDRE                     │
     * │                                                                       │
     * │  Une séquence attribue son numéro à l'INSERTION, pas à la validation. │
     * │  Deux écritures concurrentes prennent 5 et 6 ; si 6 valide en premier, │
     * │  un lecteur naïf avance son curseur à 6 et ne verra jamais le 5.       │
     * │                                                                       │
     * │  « xact_id < xmin » ne retient donc que les transactions plus          │
     * │  anciennes que la plus vieille encore en cours : passé cette borne,    │
     * │  plus rien ne peut s'intercaler derrière le curseur.                   │
     * │                                                                       │
     * │  Le coût : un événement attend la fin des transactions déjà en vol.    │
     * │  Ici elles durent quelques millisecondes. Une transaction longue —     │
     * │  un export, une migration — retarderait le flux d'autant, ce qui est   │
     * │  visible mais jamais faux.                                             │
     * │                                                                       │
     * │  Le double transtypage « ::text::bigint » n'est pas décoratif : xid8   │
     * │  ne se compare pas à un bigint, et PostgreSQL refuse la requête sans   │
     * │  lui.                                                                  │
     * └───────────────────────────────────────────────────────────────────────┘
     *
     * @return array{events: list<array<string, mixed>>, cursor: int, distanced: bool}
     */
    public function since(string $organizationId, ?int $cursor, int $limit = 100): array
    {
        $pdo = Database::connection();

        // Le curseur de tête, borné par la même règle de visibilité. Il est lu
        // AVANT les lignes : le client repart avec une borne qu'aucune des
        // lignes suivantes ne dépasse, jamais l'inverse.
        $borne = $pdo->prepare(
            'SELECT COALESCE(MAX(id), 0)
               FROM activity
              WHERE organization_id = :org
                AND xact_id < pg_snapshot_xmin(pg_current_snapshot())::text::bigint',
        );
        $borne->execute(['org' => $organizationId]);
        $tete = (int) $borne->fetchColumn();

        // ┌───────────────────────────────────────────────────────────────────┐
        // │  « PREMIER APPEL » ET « DEPUIS LE DÉBUT » NE SONT PAS LA MÊME     │
        // │  CHOSE, ET LES CONFONDRE PERD UN ÉVÉNEMENT                        │
        // │                                                                   │
        // │  Le repère de départ est l'ABSENCE de curseur, jamais la valeur   │
        // │  zéro. Un espace neuf a une tête à 0 : son client mémorise 0, le  │
        // │  renvoie au sondage suivant — et si zéro voulait dire « premier   │
        // │  appel », le tout premier événement de cet espace serait sauté.   │
        // │                                                                   │
        // │  C'est exactement la disparition silencieuse que ce jalon existe  │
        // │  pour empêcher. Null est donc le seul repère de départ.           │
        // └───────────────────────────────────────────────────────────────────┘
        //
        // Un écran qui s'ouvre vient de charger son état complet : lui rejouer
        // l'histoire lui ferait appliquer des changements qu'il affiche déjà.
        if ($cursor === null) {
            return ['events' => [], 'cursor' => $tete, 'distanced' => false];
        }

        // Trop loin derrière : rattraper coûterait plus que recharger, et
        // appliquer mille changements un par un ferait clignoter l'écran.
        // Le client est prévenu et relit tout.
        if ($tete - $cursor > self::RETARD_MAX) {
            return ['events' => [], 'cursor' => $tete, 'distanced' => true];
        }

        $statement = $pdo->prepare(
            'SELECT id, actor_id, actor_name, module, action,
                    subject_id, subject_ref, subject_title, changes, happened_at
               FROM activity
              WHERE organization_id = :org
                AND id > :cursor
                AND id <= :tete
              ORDER BY id
              LIMIT :limit',
        );

        $statement->bindValue('org', $organizationId);
        $statement->bindValue('cursor', $cursor, PDO::PARAM_INT);
        $statement->bindValue('tete', $tete, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $events = array_map($this->hydrate(...), $statement->fetchAll());

        return [
            'events' => $events,
            // La borne lue au départ, et non l'identifiant du dernier
            // événement reçu : si la limite a tronqué, le prochain appel doit
            // reprendre où celui-ci s'est arrêté.
            'cursor' => $events === [] ? $tete : (int) $events[array_key_last($events)]['id'],
            'distanced' => false,
        ];
    }

    /**
     * Les derniers faits, à l'envers — le fil du tableau de bord.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(string $organizationId, int $limit = 20): array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, actor_id, actor_name, module, action,
                    subject_id, subject_ref, subject_title, changes, happened_at
               FROM activity
              WHERE organization_id = :org
              ORDER BY happened_at DESC, id DESC
              LIMIT :limit',
        );

        $statement->bindValue('org', $organizationId);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map($this->hydrate(...), $statement->fetchAll());
    }

    /**
     * Ce que d'AUTRES ont changé sur ce sujet depuis une version donnée.
     *
     * ┌───────────────────────────────────────────────────────────────────────┐
     * │  CE QUI SÉPARE UN CONFLIT PRÉCIS D'UN CONFLIT GROSSIER               │
     * │                                                                       │
     * │  La réponse banale à une écriture périmée est « la ressource a        │
     * │  changé, rechargez » — et l'on perd le paragraphe qu'on était en      │
     * │  train d'écrire, pour un conflit qui portait sur la priorité.         │
     * │                                                                       │
     * │  Cette méthode répond autre chose : QUELS champs, et par QUI. Le      │
     * │  contrôleur peut alors laisser passer ce qui ne se croise pas — le    │
     * │  cas le plus fréquent — et ne demander d'arbitrer que sur le champ    │
     * │  réellement disputé.                                                  │
     * │                                                                       │
     * │  « d'autres » : les faits de l'appelant lui-même sont exclus. Deux    │
     * │  onglets d'une même personne se marchent dessus, mais lui demander    │
     * │  d'arbitrer contre lui-même n'aiderait personne.                      │
     * └───────────────────────────────────────────────────────────────────────┘
     *
     * @return array<string, string> { champ: nom de qui l'a changé en dernier }
     */
    public function changedSince(string $subjectId, int $version, ?string $exceptActorId = null): array
    {
        $statement = Database::connection()->prepare(
            'SELECT actor_id, actor_name, changes
               FROM activity
              WHERE subject_id = :subject
                AND subject_version IS NOT NULL
                AND subject_version > :version
              ORDER BY id DESC',
        );

        $statement->execute(['subject' => $subjectId, 'version' => $version]);

        $auteurs = [];

        foreach ($statement->fetchAll() as $row) {
            if ($exceptActorId !== null && (string) $row['actor_id'] === $exceptActorId) {
                continue;
            }

            $changes = json_decode((string) $row['changes'], true);

            if (!is_array($changes)) {
                continue;
            }

            foreach (array_keys($changes) as $field) {
                // Le PREMIER trouvé en descendant est le plus récent : les
                // entrées plus anciennes ne doivent pas l'écraser.
                $auteurs[$field] ??= $row['actor_name'] !== null
                    ? (string) $row['actor_name']
                    : 'quelqu\'un';
            }
        }

        return $auteurs;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $changes = json_decode((string) $row['changes'], true);

        return [
            'id'            => (int) $row['id'],
            'actor_id'      => $row['actor_id'] !== null ? (string) $row['actor_id'] : null,
            'actor_name'    => $row['actor_name'] !== null ? (string) $row['actor_name'] : null,
            'module'        => (string) $row['module'],
            'action'        => (string) $row['action'],
            'subject_id'    => (string) $row['subject_id'],
            'subject_ref'   => $row['subject_ref'] !== null ? (string) $row['subject_ref'] : null,
            'subject_title' => $row['subject_title'] !== null ? (string) $row['subject_title'] : null,
            'changes'       => is_array($changes) ? $changes : [],
            'happened_at'   => Database::toIso($row['happened_at']),
        ];
    }
}
