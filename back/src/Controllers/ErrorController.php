<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\ErrorRepository;
use App\Services\Journal;

/**
 * Module « Supervision » : erreurs de production.
 *
 * Ce module n'expose PAS de CRUD complet, et c'est délibéré. Une erreur n'est
 * pas saisie à la main : elle est REÇUE. On peut donc l'enregistrer (POST),
 * changer son statut de traitement, ou la faire disparaître — mais pas
 * réécrire son message ni sa pile d'appels. Un outil de supervision dont on
 * peut retoucher les faits ne supervise plus rien.
 *
 * Le nombre d'occurrences et la date de dernière vue sont entretenus par
 * trigger : ils n'apparaissent dans aucune donnée acceptée.
 */
final class ErrorController
{
    private const LEVELS   = ['warning', 'error', 'fatal'];
    private const STATUSES = ['unresolved', 'resolved', 'ignored'];

    /**
     * Les champs suivis — un SEUL, et c'est exact.
     *
     * Une erreur est REÇUE, pas saisie : son titre, son origine et son niveau
     * viennent de l'application supervisée et ne s'éditent pas. Seul le statut
     * de traitement relève d'une décision humaine, donc d'un journal et d'un
     * arbitrage.
     */
    private const FIELD_LABELS = ['status' => 'le statut'];

    private ErrorRepository $errors;
    private Journal $journal;

    public function __construct()
    {
        $this->errors  = new ErrorRepository();
        $this->journal = new Journal('supervision', self::FIELD_LABELS);
    }

    /**
     * GET /api/errors
     *
     * Filtres : ?status=unresolved&level=fatal&search=texte
     */
    public function index(Request $request): void
    {
        $userId = $request->organizationId();

        // Décalage borné : au-delà du plafond, la page demandée n'existe pas.
        $offset = $request->queryInt('offset', 0, 0, 100000);

        $result = $this->errors->search($userId, [
            'status'    => $this->validateFilter($request, 'status', self::STATUSES),
            'level'     => $this->validateFilter($request, 'level', self::LEVELS),
            'search'    => $request->queryParam('search'),
            'sort'      => $request->queryParam('sort', 'last_seen_at'),
            'direction' => $request->queryParam('direction', 'desc'),
        ], 200, $offset);

        Response::json($result['groups'], 200, [
            'offset'  => $offset,
            'total'  => $result['total'],
            'stats'  => $this->errors->statsForOrganization($userId),
            // Courbe des 14 derniers jours : une erreur qui se répète et une
            // erreur qui vient d'apparaître demandent des réactions
            // différentes, et seul l'historique les distingue.
            'daily'  => $this->errors->dailyCounts($userId, 14),
            'levels' => self::LEVELS,
        ]);
    }

    /**
     * GET /api/errors/{id}
     */
    public function show(Request $request): void
    {
        Response::json($this->findOrFail($request));
    }

    /**
     * POST /api/errors
     *
     * Enregistre une occurrence. Si son empreinte est déjà connue, elle
     * rejoint le groupe existant plutôt que d'en créer un second : c'est le
     * regroupement qui fait l'intérêt du module.
     */
    public function store(Request $request): void
    {
        $validator = new Validator($request->all());

        $fingerprint = $validator->string('fingerprint', min: 4, max: 64, label: 'empreinte');
        $title       = $validator->string('title', min: 1, max: 200, label: 'titre');
        $culprit     = $validator->string('culprit', required: false, max: 200, label: 'origine');
        $level       = $validator->enum('level', self::LEVELS, required: false, default: 'error');
        $message     = $validator->string('message', min: 1, max: 5000, label: 'message');
        $stack       = $validator->string('stack', required: false, max: 20000, label: 'pile d\'appels');
        $context     = $validator->jsonObject('context');

        $validator->check();

        $groupe = $this->errors->record($request->organizationId(), [
            'fingerprint' => $fingerprint,
            'title'       => $title,
            'culprit'     => $culprit,
            'level'       => $level,
            'message'     => $message,
            'stack'       => $stack,
            'context'     => $context,
        ]);

        // ┌───────────────────────────────────────────────────────────────────┐
        // │  SEULE LA PREMIÈRE OCCURRENCE FAIT UN ÉVÉNEMENT                   │
        // │                                                                   │
        // │  Une erreur en production se répète — des centaines de fois par   │
        // │  minute pour les plus bruyantes. Consigner chaque occurrence      │
        // │  noierait le fil de toute l'équipe sous une seule panne, et       │
        // │  rendrait le flux temps réel inutilisable pour tout le reste.     │
        // │                                                                   │
        // │  Le compteur d'occurrences, lui, monte : l'information n'est pas  │
        // │  perdue, elle est simplement à sa place.                          │
        // └───────────────────────────────────────────────────────────────────┘
        if ((int) $groupe['occurrences'] === 1) {
            $this->journal->record(
                $request,
                'created',
                (string) $groupe['id'],
                null,
                (string) $groupe['title'],
                version: (int) $groupe['version'],
            );
        }

        Response::created($groupe);
    }

    /**
     * PUT /api/errors/{id}
     *
     * Seul le statut se modifie. Un groupe rouvert automatiquement par une
     * nouvelle occurrence (cf. trigger) peut ainsi être re-résolu à la main.
     */
    public function update(Request $request): void
    {
        $existing = $this->findOrFail($request);

        $validator = new Validator($request->all());
        $status = $validator->enum('status', self::STATUSES);
        $validator->check();

        $this->journal->assertNoConflict($request, $existing, 'Cette erreur');

        $updated = $this->errors->updateStatus(
            (string) $request->param('id'),
            $request->organizationId(),
            (string) $status,
        );

        if ($updated === null) {
            throw HttpException::notFound('Erreur introuvable.');
        }

        $this->journal->record(
            $request,
            'updated',
            (string) $updated['id'],
            null,
            (string) $updated['title'],
            $this->journal->diff($existing, $updated),
            (int) $updated['version'],
        );

        Response::json($updated);
    }

    /**
     * DELETE /api/errors/{id}
     */
    public function destroy(Request $request): void
    {
        // Relu AVANT la suppression : après, le titre n'est plus lisible.
        $groupe = $this->findOrFail($request);

        if (!$this->errors->softDelete((string) $groupe['id'], $request->organizationId())) {
            throw HttpException::notFound('Erreur introuvable.');
        }

        $this->journal->record(
            $request,
            'deleted',
            (string) $groupe['id'],
            null,
            (string) $groupe['title'],
        );

        Response::noContent();
    }

    /**
     * POST /api/errors/{id}/restore — cf. TicketController::restore().
     */
    public function restore(Request $request): void
    {
        $id = $this->validateId($request);

        if (!$this->errors->restore($id, $request->organizationId())) {
            throw HttpException::notFound('Erreur introuvable.');
        }

        /** @var array<string, mixed> $groupe */
        $groupe = $this->errors->find($id, $request->organizationId());

        $this->journal->record(
            $request,
            'restored',
            (string) $groupe['id'],
            null,
            (string) $groupe['title'],
            version: (int) $groupe['version'],
        );

        Response::json($groupe);
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
        $group = $this->errors->find($this->validateId($request), $request->organizationId());

        if ($group === null) {
            throw HttpException::notFound('Erreur introuvable.');
        }

        return $group;
    }

    private function validateId(Request $request): string
    {
        $validator = new Validator(['id' => $request->param('id')]);
        $id = $validator->uuid('id');
        $validator->check();

        return (string) $id;
    }
}
