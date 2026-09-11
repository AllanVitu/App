<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\ModuleItemRepository;
use App\Services\Journal;

/**
 * CRUD des enregistrements d'un module.
 *
 * Les quatre modules partagent ces endpoints : la structure est générique
 * (titre, statut, échéance + charge utile JSONB). Un module qui aurait besoin
 * de règles métier propres pourra spécialiser ce contrôleur sans toucher aux
 * autres.
 */
final class ItemController
{
    private const STATUSES = ['draft', 'active', 'archived'];

    /**
     * Les champs suivis, et leur nom en français.
     *
     * Cette liste sert DEUX fois : elle borne ce que le journal consigne, et
     * elle nomme le champ dans le message de conflit.
     */
    private const FIELD_LABELS = [
        'title'       => 'le titre',
        'description' => 'la description',
        'status'      => 'le statut',
        'data'        => 'les données',
        'due_date'    => 'l\'échéance',
    ];

    private ModuleItemRepository $items;

    public function __construct()
    {
        $this->items = new ModuleItemRepository();
    }

    /**
     * Le journal du module concerné.
     *
     * Construit à la demande, et non une fois dans le constructeur : ce
     * contrôleur sert les CINQ modules, et « /api/items/{id} » ne porte aucun
     * slug. C'est la ligne elle-même qui dit d'où elle vient — d'où le
     * « module_slug » remonté par le dépôt.
     */
    private function journal(string $slug): Journal
    {
        return new Journal($slug, self::FIELD_LABELS);
    }

    /**
     * GET /api/modules/{slug}/items
     *
     * Filtres : ?status=active&search=texte&sort=created_at&direction=desc
     * Pagination : ?page=1&per_page=20
     */
    public function index(Request $request): void
    {
        $module  = ModuleController::resolveModule($request);
        $page    = $request->queryInt('page', 1, 1, 10000);
        $perPage = $request->queryInt('per_page', 20, 1, 100);

        $status = $request->queryParam('status');

        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            throw HttpException::validation(['status' => 'Statut de filtre inconnu.']);
        }

        $result = $this->items->paginate(
            $request->organizationId(),
            $module['id'],
            [
                'status'    => $status,
                'search'    => $request->queryParam('search'),
                'sort'      => $request->queryParam('sort', 'created_at'),
                'direction' => $request->queryParam('direction', 'desc'),
            ],
            $page,
            $perPage,
        );

        Response::json($result['items'], 200, [
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $result['total'],
            'total_pages' => (int) ceil($result['total'] / $perPage),
            'module'      => ['slug' => $module['slug'], 'name' => $module['name']],
        ]);
    }

    /**
     * POST /api/modules/{slug}/items
     */
    public function store(Request $request): void
    {
        $module     = ModuleController::resolveModule($request);
        $attributes = $this->validatePayload($request);

        $item = $this->items->create(
            $request->organizationId(),
            $request->actorId(),
            $module['id'],
            $attributes,
        );

        $this->journal((string) $item['module_slug'])->record(
            $request,
            'created',
            (string) $item['id'],
            null,
            (string) $item['title'],
            version: (int) $item['version'],
        );

        Response::created($item);
    }

    /**
     * GET /api/items/{id}
     */
    public function show(Request $request): void
    {
        Response::json($this->findOrFail($request));
    }

    /**
     * PUT /api/items/{id}
     */
    public function update(Request $request): void
    {
        $existing   = $this->findOrFail($request);
        $attributes = $this->validatePayload($request, $existing);
        $journal    = $this->journal((string) $existing['module_slug']);

        $journal->assertNoConflict($request, $existing, 'Cet élément');

        $updated = $this->items->update((string) $request->param('id'), $request->organizationId(), $attributes);

        if ($updated === null) {
            throw HttpException::notFound('Élément introuvable.');
        }

        $journal->record(
            $request,
            'updated',
            (string) $updated['id'],
            null,
            (string) $updated['title'],
            $journal->diff($existing, $updated),
            (int) $updated['version'],
        );

        Response::json($updated);
    }

    /**
     * DELETE /api/items/{id}
     */
    public function destroy(Request $request): void
    {
        // Relu AVANT la suppression : après, le titre et le module d'origine
        // ne sont plus lisibles, et le fil afficherait une ligne muette.
        $item = $this->findOrFail($request);

        if (!$this->items->softDelete((string) $item['id'], $request->organizationId())) {
            throw HttpException::notFound('Élément introuvable.');
        }

        $this->journal((string) $item['module_slug'])->record(
            $request,
            'deleted',
            (string) $item['id'],
            null,
            (string) $item['title'],
        );

        Response::noContent();
    }

    /**
     * POST /api/items/{id}/restore — cf. TicketController::restore().
     */
    public function restore(Request $request): void
    {
        $id = $this->validateId($request);

        if (!$this->items->restore($id, $request->organizationId())) {
            throw HttpException::notFound('Élément introuvable.');
        }

        /** @var array<string, mixed> $item */
        $item = $this->items->find($id, $request->organizationId());

        $this->journal((string) $item['module_slug'])->record(
            $request,
            'restored',
            (string) $item['id'],
            null,
            (string) $item['title'],
            version: (int) $item['version'],
        );

        Response::json($item);
    }

    /**
     * Valide le corps d'une création ou d'une mise à jour.
     * En mise à jour, les valeurs existantes servent de défaut : le client
     * peut n'envoyer que les champs modifiés.
     *
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?array $existing = null): array
    {
        $validator = new Validator($request->all());

        // Sémantique de mise à jour partielle : un champ ABSENT conserve sa
        // valeur, un champ PRÉSENT est validé. Un titre explicitement vidé est
        // donc rejeté, et non silencieusement ignoré.
        $title = ($existing === null || $request->has('title'))
            ? $validator->string('title', min: 1, max: 200, label: 'titre')
            : $existing['title'];

        $description = $request->has('description')
            ? $validator->string('description', required: false, max: 5000, label: 'description')
            : ($existing['description'] ?? null);

        $status = $validator->enum(
            'status',
            self::STATUSES,
            required: false,
            default: $existing['status'] ?? 'draft',
        );

        $dueDate = $request->has('due_date')
            ? $validator->date('due_date')
            : ($existing['due_date'] ?? null);

        // Le dépôt expose « data » en stdClass (pour que le JSON sortant soit
        // un objet et non un tableau vide) : on repasse en tableau associatif,
        // seule forme manipulable par le validateur et json_encode côté SQL.
        $existingData = isset($existing['data']) ? (array) $existing['data'] : [];

        $data = $request->has('data')
            ? $validator->jsonObject('data', $existingData)
            : $existingData;

        $validator->check();

        return [
            'title'       => $title,
            'description' => $description,
            'status'      => $status,
            'due_date'    => $dueDate,
            'data'        => $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function findOrFail(Request $request): array
    {
        $item = $this->items->find($this->validateId($request), $request->organizationId());

        if ($item === null) {
            throw HttpException::notFound('Élément introuvable.');
        }

        return $item;
    }

    /**
     * Un identifiant malformé est rejeté avant d'atteindre PostgreSQL :
     * la colonne est de type UUID, une valeur invalide y lèverait une
     * erreur SQL au lieu d'un 404 propre.
     */
    private function validateId(Request $request): string
    {
        $validator = new Validator(['id' => $request->param('id')]);
        $id = $validator->uuid('id');
        $validator->check();

        return (string) $id;
    }
}
