<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\OrganizationRepository;
use App\Models\TicketRepository;
use App\Services\Journal;

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

    /**
     * Les champs suivis, et leur nom en français.
     *
     * Cette liste sert DEUX fois : elle borne ce que le journal consigne, et
     * elle nomme le champ dans le message de conflit. Une seule liste, donc
     * pas de champ qu'on arbitrerait sans savoir l'appeler.
     */
    private const FIELD_LABELS = [
        'title'       => 'le titre',
        'description' => 'la description',
        'status'      => 'le statut',
        'priority'    => 'la priorité',
        'project'     => 'le projet',
        'labels'      => 'les étiquettes',
        'due_date'    => 'l\'échéance',
        'assigned_to' => 'l\'assignation',
    ];

    private TicketRepository $tickets;
    private Journal $journal;

    public function __construct()
    {
        $this->tickets = new TicketRepository();
        // Le journal sait consigner ET arbitrer, à partir du module et de ses
        // champs suivis. Les quatre autres modules l'instancient de la même
        // façon : c'est ce qui les empêche de diverger (cf. Services/Journal).
        $this->journal = new Journal('tickets', self::FIELD_LABELS);
    }

    /**
     * GET /api/tickets
     *
     * Filtres : ?status=todo&priority=urgent&project=Sécurité&label=bug
     *           &search=texte&overdue=1&assignee=me&sort=priority&direction=desc
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

        $result = $this->tickets->search($request->organizationId(), [
            'status'    => $status,
            'priority'  => $priority,
            'project'   => $request->queryParam('project'),
            'label'     => $request->queryParam('label'),
            'search'    => $request->queryParam('search'),
            'overdue'   => $request->queryParam('overdue') === '1',
            'assignee'  => $this->validateAssigneeFilter($request),
            'sort'      => $request->queryParam('sort', 'created_at'),
            'direction' => $request->queryParam('direction', 'desc'),
        ], 500, $offset);

        Response::json($result['tickets'], 200, [
            'offset'    => $offset,
            'total'    => $result['total'],
            'stats'    => $this->tickets->statsForOrganization(
                $request->organizationId(),
                $request->actorId(),
            ),
            'projects' => $this->tickets->projectsForOrganization($request->organizationId()),
            'labels'   => $this->tickets->labelsForOrganization($request->organizationId()),
        ]);
    }

    /**
     * POST /api/tickets
     */
    public function store(Request $request): void
    {
        $ticket = $this->tickets->create(
            $request->organizationId(),
            $request->actorId(),
            $this->validatePayload($request),
        );

        $this->journal->record(
            $request,
            'created',
            (string) $ticket['id'],
            'TICK-' . $ticket['number'],
            (string) $ticket['title'],
            version: (int) $ticket['version'],
        );

        Response::created($ticket);
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
        $payload  = $this->validatePayload($request, $existing);

        $this->journal->assertNoConflict($request, $existing, 'Ce ticket');

        $updated = $this->tickets->update(
            (string) $request->param('id'),
            $request->organizationId(),
            $payload,
        );

        if ($updated === null) {
            throw HttpException::notFound('Ticket introuvable.');
        }

        $this->journal->record(
            $request,
            'updated',
            (string) $updated['id'],
            'TICK-' . $updated['number'],
            (string) $updated['title'],
            $this->journal->diff($existing, $updated),
            (int) $updated['version'],
        );

        Response::json($updated);
    }

    /**
     * DELETE /api/tickets/{id}
     */
    public function destroy(Request $request): void
    {
        // Relu AVANT la suppression : après, le numéro et le titre ne sont
        // plus lisibles, et le journal afficherait une ligne muette.
        $ticket = $this->findOrFail($request);

        if (!$this->tickets->softDelete((string) $ticket['id'], $request->organizationId())) {
            throw HttpException::notFound('Ticket introuvable.');
        }

        $this->journal->record(
            $request,
            'deleted',
            (string) $ticket['id'],
            'TICK-' . $ticket['number'],
            (string) $ticket['title'],
        );

        Response::noContent();
    }

    /**
     * POST /api/tickets/{id}/restore
     *
     * ┌───────────────────────────────────────────────────────────────────┐
     * │  LA DONNÉE ÉTAIT LÀ, LE CHEMIN DE RETOUR MANQUAIT                 │
     * │                                                                   │
     * │  Toutes les suppressions de l'application sont LOGIQUES : la ligne │
     * │  reste, marquée d'un « deleted_at ». Rien n'était perdu — et       │
     * │  pourtant rien ne permettait de revenir en arrière. Supprimer un   │
     * │  ticket par erreur était définitif du point de vue de celui qui    │
     * │  l'avait fait.                                                     │
     * │                                                                   │
     * │  Aucune limite de temps côté API : un élément supprimé il y a six  │
     * │  mois se restaure aussi bien qu'un élément supprimé il y a dix     │
     * │  secondes. C'est l'INTERFACE qui propose l'annulation pendant      │
     * │  quelques secondes ; le serveur, lui, n'a aucune raison de         │
     * │  refuser plus tard ce qu'il accepte tout de suite.                 │
     * └───────────────────────────────────────────────────────────────────┘
     *
     * Répond 404 sur un identifiant inconnu, appartenant à un autre compte,
     * ou déjà restauré — les trois se traitent pareil, et distinguer le
     * deuxième dirait à un attaquant quels identifiants sont réels.
     */
    public function restore(Request $request): void
    {
        $id = $this->validateId($request);

        if (!$this->tickets->restore($id, $request->organizationId())) {
            throw HttpException::notFound('Ticket introuvable.');
        }

        /** @var array<string, mixed> $ticket */
        $ticket = $this->tickets->find($id, $request->organizationId());

        $this->journal->record(
            $request,
            'restored',
            (string) $ticket['id'],
            'TICK-' . $ticket['number'],
            (string) $ticket['title'],
            version: (int) $ticket['version'],
        );

        Response::json($ticket);
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

        $assignee = $request->has('assigned_to')
            ? $this->validateAssignee($request, $validator)
            : ($existing['assigned_to'] ?? null);

        $validator->check();

        return [
            'title'       => $title,
            'description' => $description,
            'status'      => $status,
            'priority'    => $priority,
            'project'     => $project,
            'labels'      => $labels,
            'due_date'    => $dueDate,
            'assigned_to' => $assignee,
        ];
    }

    /**
     * ┌───────────────────────────────────────────────────────────────────────┐
     * │  LA SEULE RÈGLE DE SÉCURITÉ DE L'ASSIGNATION                          │
     * │                                                                       │
     * │  L'assigné doit être MEMBRE de l'espace. Sans ce test, un identifiant │
     * │  quelconque passerait, et la sous-requête qui résout le nom le        │
     * │  renverrait : on apprendrait le nom complet d'un compte étranger en   │
     * │  devinant son identifiant. Une fuite modeste, mais réelle, et         │
     * │  gratuite à fermer.                                                   │
     * │                                                                       │
     * │  Le rôle n'entre PAS en jeu : dans une équipe, n'importe quel membre  │
     * │  confie un ticket à n'importe quel autre. Une hiérarchie de           │
     * │  l'assignation n'existe dans aucun outil dont on se sert vraiment.    │
     * └───────────────────────────────────────────────────────────────────────┘
     *
     * « null » explicite rend le ticket à la file, et c'est un geste courant :
     * il traverse donc la validation sans y être traité comme une omission.
     */
    private function validateAssignee(Request $request, Validator $validator): ?string
    {
        $assignee = $validator->uuid('assigned_to', required: false);

        if ($assignee === null) {
            return null;
        }

        $role = (new OrganizationRepository())->roleOf($request->organizationId(), $assignee);

        if ($role === null) {
            $validator->addError(
                'assigned_to',
                'Cette personne ne fait pas partie de l\'espace de travail.',
            );

            return null;
        }

        return $assignee;
    }

    /**
     * Le filtre « assigné à », qui accepte trois formes.
     *
     *   ?assignee=me    — les miens. Le raccourci est résolu ICI, côté serveur.
     *   ?assignee=none  — ceux que personne n'a pris.
     *   ?assignee=<id>  — ceux d'un coéquipier.
     *
     * « me » existe pour que l'adresse reste PARTAGEABLE sans être trompeuse :
     * avec un identifiant en clair, un lien « mes tickets » envoyé à un
     * collègue lui aurait montré les vôtres, en lui laissant croire qu'il
     * regardait les siens. Le raccourci, lui, désigne toujours celui qui lit.
     *
     * L'identifiant d'un membre n'est pas vérifié ici : un inconnu ne fait
     * qu'une liste vide, ce qui est la réponse juste. Seule l'ÉCRITURE exige
     * l'appartenance (cf. validateAssignee).
     */
    private function validateAssigneeFilter(Request $request): ?string
    {
        $value = $request->queryParam('assignee');

        if ($value === null || $value === '') {
            return null;
        }

        if ($value === 'me') {
            // Null pour une clé d'API : elle n'est personne. Le filtre retombe
            // alors sur « aucun filtre », faute de pouvoir désigner quiconque.
            return $request->actorId();
        }

        if ($value !== 'none' && preg_match('/^[0-9a-f-]{36}$/i', $value) !== 1) {
            throw HttpException::validation([
                'assignee' => 'Filtre « assigné à » invalide : attendu « me », « none », ou un identifiant.',
            ]);
        }

        return $value;
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
        $ticket = $this->tickets->find($this->validateId($request), $request->organizationId());

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
