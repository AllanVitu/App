<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\DesignRepository;

/**
 * Module « Design » : fichiers et historique de versions.
 *
 * Une version ne se modifie ni ne se supprime : elle s'ajoute. C'est ce qui
 * fait d'un historique un historique. Pouvoir réécrire une version passée
 * reviendrait à pouvoir effacer une décision de conception après coup, et
 * l'intérêt du module — voir comment le travail a évolué — disparaîtrait.
 */
final class DesignController
{
    private const KINDS = ['maquette', 'prototype', 'systeme'];

    private DesignRepository $design;

    public function __construct()
    {
        $this->design = new DesignRepository();
    }

    /**
     * GET /api/design/files
     */
    public function index(Request $request): void
    {
        $userId = $request->userId();

        $result = $this->design->search($userId, [
            'kind'      => $this->validateFilter($request, 'kind', self::KINDS),
            'search'    => $request->queryParam('search'),
            'sort'      => $request->queryParam('sort', 'updated_at'),
            'direction' => $request->queryParam('direction', 'desc'),
        ]);

        Response::json($result['files'], 200, [
            'total' => $result['total'],
            'stats' => $this->design->statsForUser($userId),
            'kinds' => self::KINDS,
        ]);
    }

    /**
     * POST /api/design/files
     *
     * Le fichier naît avec sa version 1 : un fichier sans aucune version ne
     * documenterait rien.
     */
    public function store(Request $request): void
    {
        Response::created(
            $this->design->createFile($request->userId(), $this->validatePayload($request)),
        );
    }

    /**
     * GET /api/design/files/{id}
     */
    public function show(Request $request): void
    {
        Response::json($this->findOrFail($request));
    }

    /**
     * PUT /api/design/files/{id}
     */
    public function update(Request $request): void
    {
        $existing = $this->findOrFail($request);

        $updated = $this->design->updateFile(
            (string) $request->param('id'),
            $request->userId(),
            $this->validatePayload($request, $existing),
        );

        if ($updated === null) {
            throw HttpException::notFound('Fichier introuvable.');
        }

        Response::json($updated);
    }

    /**
     * DELETE /api/design/files/{id}
     */
    public function destroy(Request $request): void
    {
        if (!$this->design->deleteFile($this->validateId($request), $request->userId())) {
            throw HttpException::notFound('Fichier introuvable.');
        }

        Response::noContent();
    }

    /**
     * POST /api/design/files/{id}/versions
     *
     * Le numéro est attribué par la base, par fichier : il n'est ni fourni
     * ni modifiable.
     */
    public function storeVersion(Request $request): void
    {
        $file = $this->findOrFail($request);

        $validator = new Validator($request->all());
        $label = $validator->string('label', required: false, max: 120, label: 'intitulé');
        $notes = $validator->string('notes', required: false, max: 5000, label: 'notes');
        $validator->check();

        Response::created($this->design->addVersion(
            (string) $file['id'],
            $request->userId(),
            ['label' => $label, 'notes' => $notes],
        ));
    }

    /**
     * @param  array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?array $existing = null): array
    {
        $validator = new Validator($request->all());

        $name = ($existing === null || $request->has('name'))
            ? $validator->string('name', min: 1, max: 120, label: 'nom')
            : $existing['name'];

        $kind = $validator->enum(
            'kind',
            self::KINDS,
            required: false,
            default: $existing['kind'] ?? 'maquette',
        );

        $description = $request->has('description')
            ? $validator->string('description', required: false, max: 2000, label: 'description')
            : ($existing['description'] ?? null);

        $accent = $request->has('accent')
            ? $validator->string('accent', required: false, min: 7, max: 7, label: 'couleur')
            : ($existing['accent'] ?? '#7ee2a8');

        // Le format est aussi contraint par la base : vérifié ici pour rendre
        // un 422 lisible plutôt qu'une violation de contrainte en 500.
        if (is_string($accent) && preg_match('/^#[0-9a-fA-F]{6}$/', $accent) !== 1) {
            $validator->addError('accent', 'La couleur doit être au format hexadécimal, par exemple #7ee2a8.');
        }

        $validator->check();

        return [
            'name'        => $name,
            'kind'        => $kind,
            'description' => $description,
            'accent'      => $accent,
        ];
    }

    /**
     * @param list<string> $allowed
     */
    private function validateFilter(Request $request, string $key, array $allowed): ?string
    {
        $value = $request->queryParam($key);

        if ($value !== null && !in_array($value, $allowed, true)) {
            throw HttpException::validation([$key => "Valeur de filtre inconnue pour « {$key} »."]);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function findOrFail(Request $request): array
    {
        $file = $this->design->find($this->validateId($request), $request->userId());

        if ($file === null) {
            throw HttpException::notFound('Fichier introuvable.');
        }

        return $file;
    }

    private function validateId(Request $request): string
    {
        $validator = new Validator(['id' => $request->param('id')]);
        $id = $validator->uuid('id');
        $validator->check();

        return (string) $id;
    }
}
