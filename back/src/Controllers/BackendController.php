<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\BackendRepository;
use App\Services\Journal;
use App\Services\SchemaBuilder;

/**
 * Module « Backend » : schémas de données et clés d'API.
 *
 * Deux ressources distinctes sous un même module. Elles ne partagent que le
 * contrôleur : un schéma se modifie librement, une clé ne se modifie pas —
 * elle se crée puis se révoque. Exposer un PUT sur une clé donnerait à croire
 * qu'on peut en changer la portée après coup, alors que le secret, lui, a
 * déjà été distribué.
 */
final class BackendController
{
    private const COLUMN_TYPES = [
        'uuid', 'text', 'varchar', 'integer', 'bigint', 'numeric',
        'boolean', 'date', 'timestamptz', 'jsonb',
    ];

    private const KEY_SCOPES = ['anon', 'service'];

    private BackendRepository $backend;

    /**
     * Matérialise les schémas décrits en VRAIES tables PostgreSQL. Sans lui,
     * ce module ne faisait que décrire : rien n'était interrogeable.
     */
    private SchemaBuilder $schema;

    private Journal $journal;

    /**
     * Les champs suivis, et leur nom en français.
     *
     * « columns » en fait partie, et c'est le plus important des quatre :
     * c'est la description d'un schéma, ce qu'on retouche longuement, et le
     * seul endroit de ce module où deux personnes ont vraiment de quoi
     * s'écraser mutuellement.
     */
    private const FIELD_LABELS = [
        'name'         => 'le nom',
        'description'  => 'la description',
        'columns'      => 'les colonnes',
        'rls_enabled'  => 'la sécurité au niveau ligne',
        'row_estimate' => 'le volume estimé',
    ];

    public function __construct()
    {
        $this->backend = new BackendRepository();
        $this->schema  = new SchemaBuilder();
        $this->journal = new Journal('backend', self::FIELD_LABELS);
    }

    // ---------------------------------------------------------------------
    //  Schémas
    // ---------------------------------------------------------------------

    /**
     * GET /api/backend/tables
     */
    public function index(Request $request): void
    {
        $orgId = $request->organizationId();

        $result = $this->backend->searchTables($orgId, [
            'search'    => $request->queryParam('search'),
            'sort'      => $request->queryParam('sort', 'created_at'),
            'direction' => $request->queryParam('direction', 'desc'),
        ]);

        Response::json($result['tables'], 200, [
            'total' => $result['total'],
            'stats' => $this->backend->statsForOrganization($orgId),
            'keys'  => $this->backend->keysForOrganization($orgId),
            'types' => self::COLUMN_TYPES,
        ]);
    }

    /**
     * POST /api/backend/tables
     */
    public function store(Request $request): void
    {
        $orgId = $request->organizationId();
        $table  = $this->backend->createTable($orgId, $request->actorId(), $this->validateTable($request));

        // La table PHYSIQUE suit la description. En cas d'échec du DDL, la
        // description est retirée : laisser une table décrite sans table réelle
        // ferait un écran qui ment sur ce qui existe.
        try {
            $this->schema->sync($orgId, $table['name'], $table['columns']);
        } catch (\Throwable $e) {
            $this->backend->deleteTable($table['id'], $orgId);

            throw $e;
        }

        // Consigné APRÈS la synchronisation physique : une description retirée
        // parce que le DDL a échoué ne doit laisser aucune trace dans le fil.
        $this->journal->record(
            $request,
            'created',
            (string) $table['id'],
            null,
            (string) $table['name'],
            version: (int) $table['version'],
        );

        Response::created($table);
    }

    /**
     * GET /api/backend/tables/{id}
     */
    public function show(Request $request): void
    {
        Response::json($this->findOrFail($request));
    }

    /**
     * PUT /api/backend/tables/{id}
     */
    public function update(Request $request): void
    {
        $existing = $this->findOrFail($request);
        $payload  = $this->validateTable($request, $existing);

        $this->journal->assertNoConflict($request, $existing, 'Cette table');

        $orgId   = $request->organizationId();
        $updated = $this->backend->updateTable(
            (string) $request->param('id'),
            $orgId,
            $payload,
        );

        if ($updated === null) {
            throw HttpException::notFound('Table introuvable.');
        }

        // Le renommage vient AVANT la synchronisation des colonnes : ALTER
        // COLUMN s'adresse à la table par son nom, donc au nouveau.
        $this->schema->rename($orgId, $existing['name'], $updated['name']);
        $this->schema->sync($orgId, $updated['name'], $updated['columns'], $existing['columns']);

        $this->journal->record(
            $request,
            'updated',
            (string) $updated['id'],
            null,
            (string) $updated['name'],
            $this->journal->diff($existing, $updated),
            (int) $updated['version'],
        );

        Response::json($updated);
    }

    /**
     * DELETE /api/backend/tables/{id}
     */
    public function destroy(Request $request): void
    {
        $existing = $this->findOrFail($request);
        $orgId   = $request->organizationId();

        if (!$this->backend->deleteTable($this->validateId($request), $orgId)) {
            throw HttpException::notFound('Table introuvable.');
        }

        // La DESCRIPTION garde son « deleted_at » ; la table physique, elle,
        // est réellement supprimée. Conserver des données inatteignables
        // consommerait de l'espace en laissant croire qu'on peut revenir en
        // arrière. L'interface le dit avant d'agir.
        $this->schema->drop($orgId, $existing['name']);

        $this->journal->record(
            $request,
            'deleted',
            (string) $existing['id'],
            null,
            (string) $existing['name'],
        );

        Response::noContent();
    }

    // ---------------------------------------------------------------------
    //  Clés d'API
    // ---------------------------------------------------------------------

    /**
     * POST /api/backend/keys
     *
     * La réponse contient la clé EN CLAIR. C'est la seule et unique fois :
     * la base n'en garde que l'empreinte. Le client doit donc l'afficher
     * immédiatement en prévenant qu'elle ne réapparaîtra pas.
     */
    public function storeKey(Request $request): void
    {
        $validator = new Validator($request->all());

        $label = $validator->string('label', min: 1, max: 60, label: 'nom');
        $scope = $validator->enum('scope', self::KEY_SCOPES, required: false, default: 'anon');

        $validator->check();

        $created = $this->backend->createKey($request->organizationId(), $request->actorId(), (string) $label, (string) $scope);

        // Une clé émise et une clé révoquée sont des faits de SÉCURITÉ : ce
        // sont précisément ceux qu'on cherche dans un fil, longtemps après.
        // Le jeton, lui, n'y figure évidemment pas — seulement son nom.
        $this->journal->record(
            $request,
            'key.created',
            (string) $created['key']['id'],
            (string) $created['key']['token_prefix'],
            (string) $created['key']['label'],
        );

        Response::created([
            'key'   => $created['key'],
            'token' => $created['token'],
            'notice' => 'Cette clé ne sera plus affichée : conservez-la maintenant.',
        ]);
    }

    /**
     * DELETE /api/backend/keys/{id}
     */
    public function revokeKey(Request $request): void
    {
        $id = $this->validateId($request);

        if (!$this->backend->revokeKey($id, $request->organizationId())) {
            // 404 aussi bien pour une clé inexistante que pour une clé déjà
            // révoquée : dans les deux cas, il n'y a rien à révoquer.
            throw HttpException::notFound('Clé introuvable ou déjà révoquée.');
        }

        $this->journal->record($request, 'key.revoked', $id);

        Response::noContent();
    }

    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function validateTable(Request $request, ?array $existing = null): array
    {
        $validator = new Validator($request->all());

        $name = ($existing === null || $request->has('name'))
            ? $validator->string('name', min: 1, max: 63, label: 'nom')
            : $existing['name'];

        // Le format est aussi contraint par la base (CHECK) : la règle est
        // vérifiée ici pour rendre un 422 lisible plutôt qu'une violation de
        // contrainte en 500, mais la base reste la garantie.
        if (is_string($name) && preg_match('/^[a-z_][a-z0-9_]*$/', $name) !== 1) {
            $validator->addError(
                'name',
                'Le nom doit être en minuscules, sans espace ni accent (lettres, chiffres, « _ »).',
            );
        }

        $description = $request->has('description')
            ? $validator->string('description', required: false, max: 2000, label: 'description')
            : ($existing['description'] ?? null);

        $columns = $request->has('columns')
            ? $this->validateColumns($validator, $request->input('columns'))
            : ($existing['columns'] ?? []);

        $rlsEnabled = $request->has('rls_enabled')
            ? $validator->boolean('rls_enabled', true)
            : (bool) ($existing['rls_enabled'] ?? true);

        $rowEstimate = $request->has('row_estimate')
            ? $validator->integer('row_estimate', 0, 1_000_000_000, 0, 'nombre de lignes')
            : (int) ($existing['row_estimate'] ?? 0);

        $validator->check();

        return [
            'name'         => $name,
            'description'  => $description,
            'columns'      => $columns,
            'rls_enabled'  => $rlsEnabled,
            'row_estimate' => $rowEstimate,
        ];
    }

    /**
     * Colonnes : liste ORDONNÉE d'objets {name, type, nullable}.
     *
     * Validée champ par champ plutôt qu'acceptée telle quelle : un JSONB
     * accepte n'importe quoi, et une colonne dont le type n'existe pas
     * rendrait le schéma inexploitable sans que rien ne l'ait signalé.
     *
     * @param  mixed $value
     * @return list<array<string, mixed>>
     */
    private function validateColumns(Validator $validator, mixed $value): array
    {
        if (!is_array($value)) {
            $validator->addError('columns', 'Les colonnes doivent être une liste.');

            return [];
        }

        if (count($value) > 60) {
            $validator->addError('columns', 'Une table est limitée à 60 colonnes.');

            return [];
        }

        $clean = [];
        $seen  = [];

        foreach (array_values($value) as $index => $column) {
            if (!is_array($column)) {
                $validator->addError('columns', 'Chaque colonne doit être un objet.');

                return [];
            }

            $name = is_string($column['name'] ?? null) ? trim($column['name']) : '';
            $type = is_string($column['type'] ?? null) ? $column['type'] : '';

            if (preg_match('/^[a-z_][a-z0-9_]*$/', $name) !== 1) {
                $validator->addError('columns', sprintf('Colonne %d : nom invalide.', $index + 1));

                return [];
            }

            if (!in_array($type, self::COLUMN_TYPES, true)) {
                $validator->addError(
                    'columns',
                    sprintf('Colonne « %s » : type inconnu (attendu : %s).', $name, implode(', ', self::COLUMN_TYPES)),
                );

                return [];
            }

            // Deux colonnes de même nom rendraient le schéma ambigu, et la
            // base ne peut pas l'interdire à l'intérieur d'un JSONB.
            if (in_array($name, $seen, true)) {
                $validator->addError('columns', sprintf('La colonne « %s » est déclarée deux fois.', $name));

                return [];
            }

            $seen[]  = $name;
            $clean[] = [
                'name'     => $name,
                'type'     => $type,
                'nullable' => (bool) ($column['nullable'] ?? true),
            ];
        }

        return $clean;
    }

    /**
     * @return array<string, mixed>
     */
    private function findOrFail(Request $request): array
    {
        $table = $this->backend->findTable($this->validateId($request), $request->organizationId());

        if ($table === null) {
            throw HttpException::notFound('Table introuvable.');
        }

        return $table;
    }

    private function validateId(Request $request): string
    {
        $validator = new Validator(['id' => $request->param('id')]);
        $id = $validator->uuid('id');
        $validator->check();

        return (string) $id;
    }
}
