<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\DocPageRepository;
use App\Services\Journal;

/**
 * Module Documentation : les pages d'un espace.
 *
 * Tout membre écrit, comme dans Tickets : une procédure se corrige au moment
 * où quelqu'un s'aperçoit qu'elle est fausse, pas quand un administrateur
 * repasse. La suppression est logique et s'annule ; l'historique garde qui a
 * fait quoi.
 */
final class DocController
{
    private const FIELD_LABELS = [
        'title'     => 'le titre',
        'body'      => 'le texte',
        'parent_id' => 'l\'emplacement',
        'position'  => 'l\'ordre',
    ];

    /** Deux cent mille caractères : la contrainte « doc_pages_body_len ». */
    private const BODY_MAX = 200000;

    private DocPageRepository $pages;
    private Journal $journal;

    public function __construct()
    {
        $this->pages   = new DocPageRepository();
        $this->journal = new Journal('documentation', self::FIELD_LABELS);
    }

    /**
     * GET /api/docs?q=
     *
     * Sans terme : l'arbre. Avec un terme d'au moins deux caractères : les
     * pages qui le contiennent, titres d'abord.
     */
    public function index(Request $request): void
    {
        $terme = $request->queryParam('q');

        if ($terme !== null && mb_strlen($terme) >= 2) {
            Response::json($this->pages->search($request->organizationId(), mb_substr($terme, 0, 100)));

            return;
        }

        Response::json($this->pages->treeForOrganization($request->organizationId()));
    }

    /**
     * GET /api/docs/{id}
     */
    public function show(Request $request): void
    {
        $page = $this->findOrFail($request);

        Response::json($page + ['ancestors' => $this->pages->ancestors((string) $page['id'], $request->organizationId())]);
    }

    /**
     * POST /api/docs
     */
    public function store(Request $request): void
    {
        $page = $this->pages->create(
            $request->organizationId(),
            $request->actorId(),
            $this->validatePayload($request),
        );

        $this->journal->record($request, 'created', (string) $page['id'], null, (string) $page['title'], version: (int) $page['version']);

        Response::created($page);
    }

    /**
     * PUT /api/docs/{id}
     */
    public function update(Request $request): void
    {
        $existing = $this->findOrFail($request);

        $this->journal->assertNoConflict($request, $existing, 'Cette page');

        $attributes = $this->validatePayload($request, $existing);
        $page       = $this->pages->update((string) $existing['id'], $request->organizationId(), $request->actorId(), $attributes);

        if ($page === null) {
            throw HttpException::notFound('Page introuvable.');
        }

        $changes = $this->journal->diff($existing, $page);

        // Le journal garde QUE le texte a changé, pas deux copies du texte : une
        // page de deux cent mille caractères enregistrée dix fois ferait quatre
        // mégaoctets d'historique. La clé reste, c'est elle qui arbitre les
        // conflits (cf. Journal::assertNoConflict).
        if (isset($changes['body'])) {
            $changes['body'] = [
                mb_strlen((string) $existing['body']) . ' caractères',
                mb_strlen((string) $page['body']) . ' caractères',
            ];
        }

        if ($changes !== []) {
            $this->journal->record($request, 'updated', (string) $page['id'], null, (string) $page['title'], $changes, (int) $page['version']);
        }

        Response::json($page);
    }

    /**
     * DELETE /api/docs/{id}
     */
    public function destroy(Request $request): void
    {
        $page = $this->findOrFail($request);

        if ($this->pages->hasChildren((string) $page['id'], $request->organizationId())) {
            throw HttpException::validation([
                'parent_id' => 'Cette page a des sous-pages : déplacez-les ou supprimez-les d\'abord.',
            ]);
        }

        $this->pages->softDelete((string) $page['id'], $request->organizationId());

        $this->journal->record($request, 'deleted', (string) $page['id'], null, (string) $page['title']);

        Response::noContent();
    }

    /**
     * POST /api/docs/{id}/restore
     */
    public function restore(Request $request): void
    {
        $id = $this->validateId($request);

        if (!$this->pages->restore($id, $request->organizationId())) {
            throw HttpException::notFound('Page introuvable.');
        }

        /** @var array<string, mixed> $page */
        $page = $this->pages->find($id, $request->organizationId());

        $this->journal->record($request, 'restored', $id, null, (string) $page['title']);

        Response::json($page);
    }

    /**
     * @param array<string, mixed>|null $existing
     *
     * @return array{title: string, body: string, parent_id: ?string, position: int}
     */
    private function validatePayload(Request $request, ?array $existing = null): array
    {
        $validator = new Validator($request->all());

        $title = ($existing === null || $request->has('title'))
            ? $validator->string('title', min: 1, max: 160, label: 'titre')
            : (string) $existing['title'];

        $body = (string) ($existing['body'] ?? '');

        if ($request->has('body')) {
            $valeur = $request->input('body');

            if (!is_string($valeur)) {
                $validator->addError('body', 'Le texte doit être une chaîne de caractères.');
            } elseif (mb_strlen($valeur) > self::BODY_MAX) {
                $validator->addError('body', 'La page dépasse deux cent mille caractères : découpez-la en sous-pages.');
            } else {
                // Tel qu'il a été écrit, blancs compris : un bloc de code
                // indenté ne se « nettoie » pas.
                $body = $valeur;
            }
        }

        $parent = $existing === null || $request->has('parent_id')
            ? $validator->uuid('parent_id', required: false)
            : ($existing['parent_id'] ?? null);

        $position = $validator->integer(
            'position',
            min: 0,
            max: 100000,
            default: isset($existing['position']) ? (int) $existing['position'] : 0,
            label: 'ordre',
        );

        $validator->check();

        if ($parent !== null) {
            // Un parent d'un autre espace répond « introuvable » comme un
            // parent qui n'existe pas : la réponse ne dit rien de ce qui vit
            // ailleurs.
            if ($this->pages->find($parent, $request->organizationId()) === null) {
                throw HttpException::validation(['parent_id' => 'La page parente est introuvable.']);
            }

            if ($existing !== null && $this->pages->isInSubtree($parent, (string) $existing['id'], $request->organizationId())) {
                throw HttpException::validation([
                    'parent_id' => 'Une page ne peut pas se ranger sous elle-même ni sous l\'une de ses sous-pages.',
                ]);
            }
        }

        return [
            'title'     => (string) $title,
            'body'      => $body,
            'parent_id' => $parent === null ? null : (string) $parent,
            'position'  => (int) $position,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function findOrFail(Request $request): array
    {
        $page = $this->pages->find($this->validateId($request), $request->organizationId());

        if ($page === null) {
            throw HttpException::notFound('Page introuvable.');
        }

        return $page;
    }

    private function validateId(Request $request): string
    {
        $validator = new Validator(['id' => $request->param('id')]);
        $id        = $validator->uuid('id');
        $validator->check();

        return (string) $id;
    }
}
