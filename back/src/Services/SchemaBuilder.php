<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use PDO;
use PDOException;

/**
 * Matérialise les schémas décrits dans le module Backend en VRAIES tables.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  CE QUE CE SERVICE CORRIGE                                          │
 * │                                                                     │
 * │  Le module annonce « de quoi prototyper un back complet sans        │
 * │  l'écrire ». Il ne faisait que DÉCRIRE : on posait des colonnes     │
 * │  dans un JSONB, aucun CREATE TABLE n'était jamais émis, rien        │
 * │  n'était interrogeable. La promesse la plus ambitieuse de           │
 * │  l'application était aussi la plus creuse.                          │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * UN SCHÉMA POSTGRESQL PAR COMPTE. Le cloisonnement devient STRUCTUREL et non
 * plus une clause WHERE qu'on peut oublier : les tables d'un compte vivent
 * dans « u_<identifiant> », celles d'un autre ailleurs. Aucune requête ne peut
 * traverser la frontière par accident, parce qu'il n'y a rien à traverser.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  DU DDL PILOTÉ PAR L'UTILISATEUR — LA SURFACE LA PLUS DÉLICATE      │
 * │                                                                     │
 * │  Un nom de table ne peut PAS être passé en paramètre lié : PDO ne   │
 * │  lie que des valeurs, jamais des identifiants. Le nom est donc      │
 * │  concaténé — et c'est exactement là que se logent les injections.   │
 * │                                                                     │
 * │  Deux barrières, et les DEUX sont nécessaires :                     │
 * │                                                                     │
 * │  1. TOUT IDENTIFIANT EST REVALIDÉ ICI contre « ^[a-z_][a-z0-9_]* », │
 * │     même si le contrôleur l'a déjà fait. Ce service est le dernier  │
 * │     endroit avant la base : il ne fait confiance à personne, pas    │
 * │     même au code d'à côté. Un futur appelant qui oublierait la      │
 * │     validation ne pourrait pas nuire.                               │
 * │  2. TOUT TYPE VIENT D'UNE LISTE BLANCHE. Jamais d'une chaîne        │
 * │     fournie, même validée ailleurs.                                 │
 * │                                                                     │
 * │  Un identifiant refusé lève une exception plutôt que d'être         │
 * │  échappé : il n'existe aucun cas légitime où un nom hors de ce      │
 * │  motif devrait passer, donc rien à rattraper.                       │
 * └─────────────────────────────────────────────────────────────────────┘
 */
final class SchemaBuilder
{
    /**
     * Motif des identifiants acceptés. Volontairement plus restrictif que ce
     * que PostgreSQL tolère entre guillemets : pas de majuscule, pas d'espace,
     * pas d'accent. Ce qu'on ne peut pas écrire, on ne peut pas mal citer.
     */
    private const IDENTIFIER = '/^[a-z_][a-z0-9_]{0,62}$/';

    /**
     * Types autorisés, et eux seuls. La clé est ce que l'utilisateur choisit,
     * la valeur ce qui part réellement dans le DDL — l'utilisateur ne compose
     * jamais un fragment de SQL.
     */
    private const TYPES = [
        'uuid'        => 'uuid',
        'text'        => 'text',
        'varchar'     => 'varchar(255)',
        'integer'     => 'integer',
        'bigint'      => 'bigint',
        'numeric'     => 'numeric',
        'boolean'     => 'boolean',
        'date'        => 'date',
        'timestamptz' => 'timestamptz',
        'jsonb'       => 'jsonb',
    ];

    /** Plafond de lecture : un prototype n'a pas à rapatrier un million de lignes. */
    private const MAX_ROWS = 200;

    // -----------------------------------------------------------------------
    //  Identifiants
    // -----------------------------------------------------------------------

    /**
     * Nom du schéma d'une organisation.
     *
     * L'identifiant est un UUID : ses tirets sont retirés et un préfixe
     * alphabétique est ajouté, car un identifiant SQL ne peut pas commencer
     * par un chiffre.
     *
     * LE PRÉFIXE EST PASSÉ DE « u_ » À « o_ » avec le cloisonnement par
     * organisation. Ce n'est pas cosmétique : ce nom désigne un schéma qui
     * contient de vraies lignes. La migration renomme donc les schémas
     * existants dans le même mouvement — sinon l'écran listerait des tables
     * dont les données seraient devenues introuvables.
     */
    public function schemaFor(string $organizationId): string
    {
        return 'o_' . str_replace('-', '', $organizationId);
    }

    /**
     * Cite un identifiant APRÈS l'avoir validé.
     *
     * L'ordre compte : citer d'abord et valider ensuite laisserait passer un
     * nom que les guillemets rendent inoffensif en SQL mais qui reste
     * illisible partout ailleurs.
     */
    private function quote(string $identifier): string
    {
        if (preg_match(self::IDENTIFIER, $identifier) !== 1) {
            // 500 et non 422 : le contrôleur a déjà refusé ce cas en 422 avec
            // un message utile. Arriver ici signifie qu'un appelant a contourné
            // la validation — c'est un défaut de programmation, pas une saisie.
            throw new \RuntimeException("Identifiant SQL refusé : « {$identifier} »");
        }

        return '"' . $identifier . '"';
    }

    /** Nom pleinement qualifié, prêt à être concaténé. */
    private function qualified(string $organizationId, string $table): string
    {
        return $this->quote($this->schemaFor($organizationId)) . '.' . $this->quote($table);
    }

    private function sqlType(string $type): string
    {
        return self::TYPES[$type]
            ?? throw new \RuntimeException("Type SQL refusé : « {$type} »");
    }

    // -----------------------------------------------------------------------
    //  Structure
    // -----------------------------------------------------------------------

    /** Le schéma du compte, créé au premier besoin. */
    public function ensureSchema(string $organizationId): void
    {
        Database::connection()->exec(
            'CREATE SCHEMA IF NOT EXISTS ' . $this->quote($this->schemaFor($organizationId)),
        );
    }

    /**
     * Fragment de définition d'une colonne.
     *
     * « id uuid » reçoit une valeur par défaut et devient la clé primaire :
     * c'est la colonne que le module crée d'office avec chaque table, et sans
     * elle une ligne ne serait ni adressable ni supprimable. Le faire ici
     * plutôt que de le demander à l'utilisateur lui épargne une décision dont
     * il n'a aucune raison de s'occuper.
     *
     * @param array<string, mixed> $column
     */
    private function columnDefinition(array $column): string
    {
        $name = (string) $column['name'];
        $type = (string) $column['type'];

        $sql = $this->quote($name) . ' ' . $this->sqlType($type);

        if ($name === 'id' && $type === 'uuid') {
            return $sql . ' PRIMARY KEY DEFAULT gen_random_uuid()';
        }

        if (($column['nullable'] ?? true) === false) {
            $sql .= ' NOT NULL';
        }

        return $sql;
    }

    /**
     * Aligne la table physique sur sa description.
     *
     * Crée la table si elle n'existe pas — ce qui rattrape aussi les schémas
     * décrits AVANT l'existence de ce service, sans migration à écrire.
     *
     * @param list<array<string, mixed>> $columns
     * @param list<array<string, mixed>> $previous colonnes avant modification
     */
    public function sync(string $organizationId, string $table, array $columns, array $previous = []): void
    {
        $this->ensureSchema($organizationId);

        $connection = Database::connection();
        $qualified  = $this->qualified($organizationId, $table);

        if (!$this->tableExists($organizationId, $table)) {
            $definitions = array_map($this->columnDefinition(...), $columns);

            $connection->exec("CREATE TABLE {$qualified} (" . implode(', ', $definitions) . ')');

            return;
        }

        foreach ($this->alterations($columns, $previous) as $alteration) {
            $connection->exec("ALTER TABLE {$qualified} {$alteration}");
        }
    }

    /**
     * Différence entre deux descriptions, traduite en ALTER.
     *
     * ┌─────────────────────────────────────────────────────────────────┐
     * │  LE RENOMMAGE EST DÉDUIT DE LA POSITION, pas du nom.            │
     * │                                                                 │
     * │  Une colonne n'a pas d'identifiant stable : renommer « mail »   │
     * │  en « email » ressemble, vu de la liste, à une suppression      │
     * │  suivie d'un ajout — et cela DÉTRUIRAIT la colonne et tout ce   │
     * │  qu'elle contient.                                              │
     * │                                                                 │
     * │  L'écran modifie les colonnes SUR PLACE : le rang est donc      │
     * │  stable, et un nom qui change au même rang est un renommage.    │
     * │  La limite est assumée : réordonner ET renommer dans le même    │
     * │  enregistrement serait mal interprété. L'interface ne permet    │
     * │  pas de réordonner.                                             │
     * └─────────────────────────────────────────────────────────────────┘
     *
     * @param list<array<string, mixed>> $columns
     * @param list<array<string, mixed>> $previous
     * @return list<string>
     */
    private function alterations(array $columns, array $previous): array
    {
        $alterations = [];
        $renamed     = [];

        foreach ($columns as $index => $column) {
            $before = $previous[$index] ?? null;

            if ($before !== null && $before['name'] !== $column['name']
                && $this->stillPresent($previous, $column['name']) === false
                && $this->stillPresent($columns, (string) $before['name']) === false) {
                $alterations[] = 'RENAME COLUMN ' . $this->quote((string) $before['name'])
                    . ' TO ' . $this->quote((string) $column['name']);
                $renamed[(string) $before['name']] = (string) $column['name'];
            }
        }

        $avant = array_column($previous, 'name');
        $apres = array_column($columns, 'name');

        // Les renommages sont retirés des deux côtés : ils ont déjà leur ALTER.
        $avant = array_values(array_diff($avant, array_keys($renamed)));
        $apres = array_values(array_diff($apres, array_values($renamed)));

        foreach (array_diff($apres, $avant) as $name) {
            $column = $this->columnNamed($columns, $name);

            if ($column !== null) {
                $alterations[] = 'ADD COLUMN IF NOT EXISTS ' . $this->columnDefinition($column);
            }
        }

        foreach (array_diff($avant, $apres) as $name) {
            $alterations[] = 'DROP COLUMN IF EXISTS ' . $this->quote((string) $name);
        }

        return $alterations;
    }

    /**
     * @param list<array<string, mixed>> $columns
     */
    private function stillPresent(array $columns, string $name): bool
    {
        return in_array($name, array_column($columns, 'name'), true);
    }

    /**
     * @param list<array<string, mixed>> $columns
     * @return array<string, mixed>|null
     */
    private function columnNamed(array $columns, string $name): ?array
    {
        foreach ($columns as $column) {
            if (($column['name'] ?? null) === $name) {
                return $column;
            }
        }

        return null;
    }

    public function rename(string $organizationId, string $from, string $to): void
    {
        if ($from === $to || !$this->tableExists($organizationId, $from)) {
            return;
        }

        Database::connection()->exec(
            'ALTER TABLE ' . $this->qualified($organizationId, $from) . ' RENAME TO ' . $this->quote($to),
        );
    }

    /**
     * Supprime la table ET ses données.
     *
     * Pas de suppression douce ici, contrairement à la DESCRIPTION de la table
     * qui, elle, garde son « deleted_at ». Conserver la table physique d'un
     * schéma qu'on ne voit plus consommerait de l'espace pour des données
     * inatteignables — et laisserait croire qu'on peut revenir en arrière.
     * L'interface doit donc le dire avant d'agir.
     */
    public function drop(string $organizationId, string $table): void
    {
        Database::connection()->exec('DROP TABLE IF EXISTS ' . $this->qualified($organizationId, $table));
    }

    public function tableExists(string $organizationId, string $table): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT 1 FROM information_schema.tables
              WHERE table_schema = :schema AND table_name = :name',
        );

        $statement->execute(['schema' => $this->schemaFor($organizationId), 'name' => $table]);

        return $statement->fetchColumn() !== false;
    }

    // -----------------------------------------------------------------------
    //  Données
    // -----------------------------------------------------------------------

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function rows(string $organizationId, string $table, int $limit = self::MAX_ROWS): array
    {
        $this->assertUsable($organizationId, $table);

        $qualified = $this->qualified($organizationId, $table);

        $total = (int) Database::connection()
            ->query("SELECT COUNT(*) FROM {$qualified}")
            ->fetchColumn();

        $statement = Database::connection()->prepare("SELECT * FROM {$qualified} LIMIT :limit");
        $statement->bindValue('limit', min($limit, self::MAX_ROWS), PDO::PARAM_INT);
        $statement->execute();

        return ['rows' => $statement->fetchAll(), 'total' => $total];
    }

    /**
     * Insère une ligne.
     *
     * Les COLONNES sont validées contre le schéma réel, les VALEURS sont liées.
     * Un champ inconnu est refusé plutôt qu'ignoré : ignorer silencieusement
     * une clé mal orthographiée ferait croire à un enregistrement réussi.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function insert(string $organizationId, string $table, array $values): array
    {
        $this->assertUsable($organizationId, $table);

        $known = $this->physicalColumns($organizationId, $table);
        $names = [];

        foreach (array_keys($values) as $name) {
            if (!in_array($name, $known, true)) {
                throw HttpException::validation(
                    ['columns' => sprintf('Colonnes disponibles : %s.', implode(', ', $known))],
                    sprintf('Colonne inconnue : « %s ».', is_string($name) ? $name : '?'),
                );
            }

            $names[] = (string) $name;
        }

        if ($names === []) {
            throw HttpException::validation(
                ['row' => 'Le corps de la requête ne contient aucune colonne connue.'],
                'Aucune valeur à enregistrer.',
            );
        }

        $colonnes    = implode(', ', array_map($this->quote(...), $names));
        $parametres  = implode(', ', array_map(static fn (string $n): string => ':' . $n, $names));
        $qualified   = $this->qualified($organizationId, $table);

        $statement = Database::connection()->prepare(
            "INSERT INTO {$qualified} ({$colonnes}) VALUES ({$parametres}) RETURNING *",
        );

        foreach ($names as $name) {
            $value = $values[$name];

            $statement->bindValue(
                $name,
                is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value,
            );
        }

        try {
            $statement->execute();
        } catch (PDOException $e) {
            // Une contrainte violée est une SAISIE fautive, pas une panne :
            // elle doit revenir en 422 avec le message de la base, qui est
            // souvent le plus précis dont on dispose.
            throw HttpException::validation(
                ['row' => $e->errorInfo[2] ?? $e->getMessage()],
                'La base a refusé cette ligne.',
            );
        }

        /** @var array<string, mixed> $row */
        $row = $statement->fetch();

        return $row;
    }

    public function delete(string $organizationId, string $table, string $id): bool
    {
        $this->assertUsable($organizationId, $table);

        if (!in_array('id', $this->physicalColumns($organizationId, $table), true)) {
            throw HttpException::validation(
                ['id' => 'Ajoutez une colonne « id » de type uuid pour rendre les lignes adressables.'],
                'Cette table n\'a pas de colonne « id » : ses lignes ne sont pas adressables.',
            );
        }

        $statement = Database::connection()->prepare(
            'DELETE FROM ' . $this->qualified($organizationId, $table) . ' WHERE id = :id',
        );

        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }

    /**
     * @return list<string>
     */
    public function physicalColumns(string $organizationId, string $table): array
    {
        $statement = Database::connection()->prepare(
            'SELECT column_name FROM information_schema.columns
              WHERE table_schema = :schema AND table_name = :name
              ORDER BY ordinal_position',
        );

        $statement->execute(['schema' => $this->schemaFor($organizationId), 'name' => $table]);

        return array_map(static fn ($row): string => (string) $row['column_name'], $statement->fetchAll());
    }

    /**
     * Refuse tôt et clairement plutôt que de laisser partir une requête sur
     * une table absente : l'erreur PostgreSQL serait exacte mais illisible.
     */
    private function assertUsable(string $organizationId, string $table): void
    {
        if (!$this->tableExists($organizationId, $table)) {
            throw HttpException::notFound(
                sprintf('La table « %s » n\'existe pas dans votre espace.', $table),
            );
        }
    }
}
