<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\TicketRepository;

/**
 * Module « Tickets ».
 *
 * Contrôleur DÉDIÉ, là où les cinq modules partageaient ItemController et son
 * modèle générique. Un ticket a des règles que « titre + statut + JSONB » ne
 * sait pas porter : une priorité ordonnée, un cycle de vie, des étiquettes
 * normalisées, un numéro stable.
 *
 * Le numéro et la date de clôture ne figurent nulle part dans les données
 * acceptées : ils sont produits par la base (cf. 06_tickets.sql). Les laisser
 * entrer par l'API permettrait à un client de fabriquer des numéros en double
 * ou des dates de clôture incohérentes avec le statut.
 */
final class TicketController
{
    private const STATUSES   = ['backlog', 'todo', 'in_progress', 'done', 'canceled'];
    private const PRIORITIES = ['none', 'low', 'medium', 'high', 'urgent'];

    private TicketRepository $tickets;

    public function __construct()
    {
        $this->tickets = new TicketRepository();
    }

    /**
     * GET /api/tickets
     *
     * Filtres : ?status=todo&priority=urgent&project=Sécurité&label=bug
     *           &search=texte&overdue=1&sort=priority&direction=desc
     *
     * La réponse embarque les indicateurs, les projets et les étiquettes
     * connus : l'écran se construit en un seul aller-retour, ce qui est la
     * condition d'une interface au clavier — attendre trois requêtes avant
     * de pouvoir filtrer ruinerait l'intérêt.
     */
    public function index(Request $request): void
    {
        $status   = $this->validateFilter($request, 'status', self::STATUSES);
        $priority = $this->validateFilter($request, 'priority', self::PRIORITIES);

        // Décalage borné : au-delà du plafond, la page demandée n'existe pas.
        $offset = $request->queryInt('offset', 0, 0, 100000);

        $result = $this->tickets->search($request->userId(), [
            'status'    => $status,
            'priority'  => $priority,
            'project'   => $request->queryParam('project'),
            'label'     => $request->queryParam('label'),
            'search'    => $request->queryParam('search'),
            'overdue'   => $request->queryParam('overdue') === '1',
            'sort'      => $request->queryParam('sort', 'created_at'),
            'direction' => $request->queryParam('direction', 'desc'),
        ], 500, $offset);

        Response::json($result['tickets'], 200, [
            'offset'    => $offset,
            'total'    => $result['total'],
            'stats'    => $this->tickets->statsForUser($request->userId()),
            'projects' => $this->tickets->projectsForUser($request->userId()),
            'labels'   => $this->tickets->labelsForUser($request->userId()),
        ]);
    }

    /**
     * POST /api/tickets
     */
    public function store(Request $request): void
    {
        Response::created(
            $this->tickets->create($request->userId(), $this->validatePayload($request)),
        );
    }

    /**
     * GET /api/tickets/{id}
     */
    public function show(Request $request): void
    {
        Response::json($this->findOrFail($request));
    }

    /**
     * PUT /api/tickets/{id}
     *
     * Mise à jour partielle : c'est ce qui rend les raccourcis clavier
     * possibles. Changer la priorité d'un ticket envoie {"priority":"urgent"}
     * et rien d'autre — le client n'a pas à renvoyer un ticket complet qu'il
     * risquerait d'écraser avec une version périmée.
     */
    public function update(Request $request): void
    {
        $existing = $this->findOrFail($request);

        $updated = $this->tickets->update(
            (string) $request->param('id'),
            $request->userId(),
            $this->validatePayload($request, $existing),
        );

        if ($updated === null) {
            throw HttpException::notFound('Ticket introuvable.');
        }

        Response::json($updated);
    }

    /**
     * DELETE /api/tickets/{id}
     */
    public function destroy(Request $request): void
    {
        if (!$this->tickets->softDelete($this->validateId($request), $request->userId())) {
            throw HttpException::notFound('Ticket introuvable.');
        }

        Response::noContent();
    }

    /**
     * Valide le corps d'une création ou d'une mise à jour.
     *
     * Sémantique de mise à jour partielle : un champ ABSENT conserve sa
     * valeur, un champ PRÉSENT est validé. Un titre explicitement vidé est
     * donc rejeté, et non silencieusement ignoré.
     *
     * @param  array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?array $existing = null): array
    {
        $validator = new Validator($request->all());

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
            default: $existing['status'] ?? 'todo',
        );

        $priority = $validator->enum(
            'priority',
            self::PRIORITIES,
            required: false,
            default: $existing['priority'] ?? 'none',
        );

        $project = $request->has('project')
            ? $validator->string('project', required: false, max: 60, label: 'projet')
            : ($existing['project'] ?? null);

        $labels = $request->has('labels')
            ? $validator->stringList('labels', label: 'étiquettes')
            : ($existing['labels'] ?? []);

        $dueDate = $request->has('due_date')
            ? $validator->date('due_date')
            : ($existing['due_date'] ?? null);

        $validator->check();

        return [
            'title'       => $title,
            'description' => $description,
            'status'      => $status,
            'priority'    => $priority,
            'project'     => $project,
            'labels'      => $labels,
            'due_date'    => $dueDate,
        ];
    }

    /**
     * Un filtre de requête hors liste blanche est une ERREUR, pas un filtre
     * ignoré : renvoyer la liste entière alors que l'utilisateur croit l'avoir
     * restreinte est le plus trompeur des deux comportements.
     *
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
        $ticket = $this->tickets->find($this->validateId($request), $request->userId());

        if ($ticket === null) {
            throw HttpException::notFound('Ticket introuvable.');
        }

        return $ticket;
    }

    /**
     * Un identifiant malformé est rejeté avant d'atteindre PostgreSQL : la
     * colonne est de type UUID, une valeur invalide y lèverait une erreur SQL
     * au lieu d'un 404 propre.
     */
    private function validateId(Request $request): string
    {
        $validator = new Validator(['id' => $request->param('id')]);
        $id = $validator->uuid('id');
        $validator->check();

        return (string) $id;
    }
}
