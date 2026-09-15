<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\DeploymentRepository;
use App\Services\Journal;

/**
 * Module « Déploiement ».
 *
 * « finished_at » et « duration_ms » n'apparaissent dans aucune donnée
 * acceptée : ils sont dérivés du statut par la base (cf. 07_modules.sql).
 * Les laisser entrer permettrait de déclarer un déploiement terminé en trois
 * millisecondes, ou terminé sans l'être.
 */
final class DeploymentController
{
    private const ENVIRONMENTS = ['preview', 'production'];
    private const STATUSES     = ['queued', 'building', 'ready', 'error', 'canceled'];

    /**
     * Les champs suivis, et leur nom en français.
     *
     * Cette liste sert DEUX fois : elle borne ce que le journal consigne, et
     * elle nomme le champ dans le message de conflit. Une seule liste, donc
     * pas de champ qu'on arbitrerait sans savoir l'appeler.
     */
    private const FIELD_LABELS = [
        'environment'    => 'l\'environnement',
        'branch'         => 'la branche',
        'commit_sha'     => 'l\'empreinte du commit',
        'commit_message' => 'le message du commit',
        'status'         => 'le statut',
        'url'            => 'l\'adresse',
        'log'            => 'le journal',
    ];

    private DeploymentRepository $deployments;
    private Journal $journal;

    public function __construct()
    {
        $this->deployments = new DeploymentRepository();
        $this->journal     = new Journal('deploiement', self::FIELD_LABELS);
    }

    /**
     * La référence courte d'un déploiement, telle qu'elle apparaît à l'écran.
     *
     * « branche@empreinte » plutôt qu'un identifiant : c'est ce qu'on cherche
     * du regard dans un fil d'activité, et ce qu'on cite à l'oral.
     *
     * @param array<string, mixed> $deployment
     */
    private static function ref(array $deployment): string
    {
        return $deployment['branch'] . '@' . substr((string) $deployment['commit_sha'], 0, 7);
    }

    /**
     * GET /api/deployments
     *
     * Filtres : ?environment=production&status=error&branch=main&search=texte
     */
    public function index(Request $request): void
    {
        $userId = $request->organizationId();

        // Décalage borné : au-delà du plafond, la page demandée n'existe pas.
        $offset = $request->queryInt('offset', 0, 0, 100000);

        $result = $this->deployments->search($userId, [
            'environment' => $this->validateFilter($request, 'environment', self::ENVIRONMENTS),
            'status'      => $this->validateFilter($request, 'status', self::STATUSES),
            'branch'      => $request->queryParam('branch'),
            'search'      => $request->queryParam('search'),
            'sort'        => $request->queryParam('sort', 'created_at'),
            'direction'   => $request->queryParam('direction', 'desc'),
        ], 200, $offset);

        Response::json($result['deployments'], 200, [
            'offset'    => $offset,
            'total'    => $result['total'],
            'stats'    => $this->deployments->statsForOrganization($userId),
            'branches' => $this->deployments->branchesForOrganization($userId),
        ]);
    }

    /**
     * POST /api/deployments
     */
    public function store(Request $request): void
    {
        $deployment = $this->deployments->create(
            $request->organizationId(),
            $request->actorId(),
            $this->validatePayload($request),
        );

        $this->journal->record(
            $request,
            'created',
            (string) $deployment['id'],
            self::ref($deployment),
            $deployment['commit_message'] !== null ? (string) $deployment['commit_message'] : null,
            version: (int) $deployment['version'],
        );

        Response::created($deployment);
    }

    /**
     * GET /api/deployments/{id}
     */
    public function show(Request $request): void
    {
        Response::json($this->findOrFail($request));
    }

    /**
     * GET /api/deployments/{id}/errors
     *
     * « Qu'est-ce que ce déploiement a cassé ? » (cf. DeploymentRepository::errorsSince).
     */
    public function errors(Request $request): void
    {
        $deployment = $this->findOrFail($request);

        Response::json($this->deployments->errorsSince((string) $deployment['id'], $request->organizationId()));
    }

    /**
     * PUT /api/deployments/{id}
     */
    public function update(Request $request): void
    {
        $existing = $this->findOrFail($request);
        $payload  = $this->validatePayload($request, $existing);

        $this->journal->assertNoConflict($request, $existing, 'Ce déploiement');

        $updated = $this->deployments->update(
            (string) $request->param('id'),
            $request->organizationId(),
            $payload,
        );

        if ($updated === null) {
            throw HttpException::notFound('Déploiement introuvable.');
        }

        $this->journal->record(
            $request,
            'updated',
            (string) $updated['id'],
            self::ref($updated),
            $updated['commit_message'] !== null ? (string) $updated['commit_message'] : null,
            $this->journal->diff($existing, $updated),
            (int) $updated['version'],
        );

        Response::json($updated);
    }

    /**
     * DELETE /api/deployments/{id}
     */
    public function destroy(Request $request): void
    {
        // Relu AVANT la suppression : après, la référence et le message ne
        // sont plus lisibles, et le fil afficherait une ligne muette.
        $deployment = $this->findOrFail($request);

        if (!$this->deployments->softDelete((string) $deployment['id'], $request->organizationId())) {
            throw HttpException::notFound('Déploiement introuvable.');
        }

        $this->journal->record(
            $request,
            'deleted',
            (string) $deployment['id'],
            self::ref($deployment),
            $deployment['commit_message'] !== null ? (string) $deployment['commit_message'] : null,
        );

        Response::noContent();
    }

    /**
     * POST /api/deployments/{id}/restore — cf. TicketController::restore().
     */
    public function restore(Request $request): void
    {
        $id = $this->validateId($request);

        if (!$this->deployments->restore($id, $request->organizationId())) {
            throw HttpException::notFound('Déploiement introuvable.');
        }

        /** @var array<string, mixed> $deployment */
        $deployment = $this->deployments->find($id, $request->organizationId());

        $this->journal->record(
            $request,
            'restored',
            (string) $deployment['id'],
            self::ref($deployment),
            $deployment['commit_message'] !== null ? (string) $deployment['commit_message'] : null,
            version: (int) $deployment['version'],
        );

        Response::json($deployment);
    }

    /**
     * Sémantique de mise à jour partielle : un champ ABSENT conserve sa
     * valeur, un champ PRÉSENT est validé.
     *
     * @param  array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?array $existing = null): array
    {
        $validator = new Validator($request->all());

        $branch = ($existing === null || $request->has('branch'))
            ? $validator->string('branch', min: 1, max: 120, label: 'branche')
            : $existing['branch'];

        $sha = ($existing === null || $request->has('commit_sha'))
            ? $validator->string('commit_sha', min: 7, max: 40, label: 'empreinte du commit')
            : $existing['commit_sha'];

        // Le format est aussi contraint par la base : vérifié ici pour rendre
        // un 422 lisible plutôt qu'une violation de contrainte en 500.
        if (is_string($sha) && preg_match('/^[0-9a-f]{7,40}$/', $sha) !== 1) {
            $validator->addError(
                'commit_sha',
                "L'empreinte du commit doit être hexadécimale (7 à 40 caractères).",
            );
        }

        $message = $request->has('commit_message')
            ? $validator->string('commit_message', required: false, max: 200, label: 'message du commit')
            : ($existing['commit_message'] ?? null);

        $environment = $validator->enum(
            'environment',
            self::ENVIRONMENTS,
            required: false,
            default: $existing['environment'] ?? 'preview',
        );

        $status = $validator->enum(
            'status',
            self::STATUSES,
            required: false,
            default: $existing['status'] ?? 'queued',
        );

        $url = $request->has('url')
            ? $validator->string('url', required: false, max: 400, label: 'URL')
            : ($existing['url'] ?? null);

        $log = $request->has('log')
            ? $validator->string('log', required: false, max: 20000, label: 'journal')
            : ($existing['log'] ?? null);

        $validator->check();

        return [
            'environment'    => $environment,
            'branch'         => $branch,
            'commit_sha'     => $sha,
            'commit_message' => $message,
            'status'         => $status,
            'url'            => $url,
            'log'            => $log,
        ];
    }

    /**
     * Un filtre hors liste blanche est une ERREUR, pas un filtre ignoré :
     * renvoyer la liste entière alors que l'utilisateur croit l'avoir
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
        $deployment = $this->deployments->find($this->validateId($request), $request->organizationId());

        if ($deployment === null) {
            throw HttpException::notFound('Déploiement introuvable.');
        }

        return $deployment;
    }

    private function validateId(Request $request): string
    {
        $validator = new Validator(['id' => $request->param('id')]);
        $id = $validator->uuid('id');
        $validator->check();

        return (string) $id;
    }
}
