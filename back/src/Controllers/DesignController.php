<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\DesignRepository;
use App\Services\FileStorage;
use App\Services\Journal;
use App\Services\RateLimiter;
use Throwable;

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

    /** Images par heure et par compte : de quoi itérer sur une maquette, pas remplir un disque. */
    private const UPLOADS_PER_HOUR = 60;

    /**
     * Les champs suivis, et leur nom en français.
     *
     * Cette liste sert DEUX fois : elle borne ce que le journal consigne, et
     * elle nomme le champ dans le message de conflit.
     */
    private const FIELD_LABELS = [
        'name'        => 'le nom',
        'kind'        => 'le type',
        'description' => 'la description',
        'accent'      => 'la couleur',
    ];

    private DesignRepository $design;
    private Journal $journal;

    public function __construct()
    {
        $this->design  = new DesignRepository();
        $this->journal = new Journal('design', self::FIELD_LABELS);
    }

    /**
     * GET /api/design/files
     */
    public function index(Request $request): void
    {
        $userId = $request->organizationId();

        // Décalage borné : au-delà du plafond, la page demandée n'existe pas.
        $offset = $request->queryInt('offset', 0, 0, 100000);

        $result = $this->design->search($userId, [
            'kind'      => $this->validateFilter($request, 'kind', self::KINDS),
            'search'    => $request->queryParam('search'),
            'sort'      => $request->queryParam('sort', 'updated_at'),
            'direction' => $request->queryParam('direction', 'desc'),
        ], 200, $offset);

        Response::json($result['files'], 200, [
            'offset' => $offset,
            'total' => $result['total'],
            'stats' => $this->design->statsForOrganization($userId),
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
        $file = $this->design->createFile(
            $request->organizationId(),
            $request->actorId(),
            $this->validatePayload($request),
        );

        $this->journal->record(
            $request,
            'created',
            (string) $file['id'],
            null,
            (string) $file['name'],
            version: (int) $file['version'],
        );

        Response::created($file);
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
        $payload  = $this->validatePayload($request, $existing);

        $this->journal->assertNoConflict($request, $existing, 'Ce fichier');

        $updated = $this->design->updateFile(
            (string) $request->param('id'),
            $request->organizationId(),
            $payload,
        );

        if ($updated === null) {
            throw HttpException::notFound('Fichier introuvable.');
        }

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
     * DELETE /api/design/files/{id}
     */
    public function destroy(Request $request): void
    {
        // Relu AVANT la suppression : après, le nom n'est plus lisible, et le
        // fil afficherait une ligne muette.
        $file = $this->findOrFail($request);

        if (!$this->design->deleteFile((string) $file['id'], $request->organizationId())) {
            throw HttpException::notFound('Fichier introuvable.');
        }

        $this->journal->record(
            $request,
            'deleted',
            (string) $file['id'],
            null,
            (string) $file['name'],
        );

        Response::noContent();
    }

    /**
     * POST /api/design/files/{id}/restore — cf. TicketController::restore().
     */
    public function restore(Request $request): void
    {
        $id = $this->validateId($request);

        if (!$this->design->restoreFile($id, $request->organizationId())) {
            throw HttpException::notFound('Fichier introuvable.');
        }

        /** @var array<string, mixed> $file */
        $file = $this->design->find($id, $request->organizationId());

        $this->journal->record(
            $request,
            'restored',
            (string) $file['id'],
            null,
            (string) $file['name'],
            version: (int) $file['version'],
        );

        Response::json($file);
    }

    /**
     * POST /api/design/files/{id}/versions
     *
     * Le numéro est attribué par la base, par fichier : il n'est ni fourni
     * ni modifiable.
     *
     * JSON pour une version qui ne consigne qu'une note, multipart (champ
     * « file ») pour une version qui porte son image. Une seule route : une
     * version reste une version, qu'elle documente une décision ou une maquette.
     */
    public function storeVersion(Request $request): void
    {
        $file = $this->findOrFail($request);

        $validator = new Validator($request->all());
        $label = $validator->string('label', required: false, max: 120, label: 'intitulé');
        $notes = $validator->string('notes', required: false, max: 5000, label: 'notes');
        $validator->check();

        // Le fichier n'est rangé qu'une fois le reste validé : un intitulé trop
        // long ne doit pas laisser dix mégaoctets derrière lui.
        $upload  = $request->file('file');
        $storage = new FileStorage();
        $asset   = null;

        if ($upload !== null) {
            (new RateLimiter())->hit('design-upload', $request->userId(), self::UPLOADS_PER_HOUR, 3600);

            $asset = $storage->store($upload, 'design', $request->organizationId(), null, $request->actorId());
        }

        try {
            $version = $this->design->addVersion(
                (string) $file['id'],
                $request->organizationId(),
                $request->actorId(),
                ['label' => $label, 'notes' => $notes, 'asset_id' => $asset['id'] ?? null],
            );
        } catch (Throwable $e) {
            // Sans sa version, le fichier rangé ne serait montré nulle part, et
            // compterait pourtant dans le quota de l'espace.
            if ($asset !== null) {
                $storage->delete((string) $asset['id']);
            }

            throw $e;
        }

        // Consigné sur le FICHIER, pas sur la version : c'est le fichier que
        // l'écran affiche et que le fil doit pouvoir ouvrir. Une entrée
        // pointant vers une version mènerait à un identifiant que rien ne sait
        // résoudre.
        $this->journal->record(
            $request,
            'versioned',
            (string) $file['id'],
            'v' . $version['number'],
            (string) $file['name'],
        );

        Response::created($version);
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
        $file = $this->design->find($this->validateId($request), $request->organizationId());

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
