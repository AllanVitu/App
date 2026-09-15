<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\ProbeRepository;
use App\Services\Journal;
use App\Services\RateLimiter;
use App\Services\UnsafeUrl;
use App\Services\UrlGuard;

/**
 * Module Disponibilité : les sondes d'un espace.
 *
 * Tout membre lit ; seuls les administrateurs créent, règlent, suspendent ou
 * suppriment (cf. les routes). Une sonde fait émettre des requêtes par le
 * serveur, vers l'adresse de son choix, toutes les minutes : c'est un pouvoir
 * sur ce que Relais envoie à Internet, pas une préférence d'affichage.
 */
final class ProbeController
{
    private const METHODS = ['GET', 'HEAD'];

    /** Les paliers que la base accepte (cf. la contrainte « probes_interval »). */
    private const INTERVALS = [60, 300, 900, 3600];

    private const FIELD_LABELS = [
        'name'             => 'le nom',
        'url'              => 'l\'adresse',
        'method'           => 'la méthode',
        'interval_seconds' => 'l\'intervalle',
        'timeout_ms'       => 'le délai',
        'slow_ms'          => 'le seuil de lenteur',
        'is_paused'        => 'la pause',
    ];

    private ProbeRepository $probes;
    private Journal $journal;

    public function __construct()
    {
        $this->probes  = new ProbeRepository();
        $this->journal = new Journal('disponibilite', self::FIELD_LABELS);
    }

    /**
     * GET /api/probes
     */
    public function index(Request $request): void
    {
        Response::json(
            $this->probes->listForOrganization($request->organizationId()),
            meta: ['quota' => ProbeRepository::QUOTA],
        );
    }

    /**
     * GET /api/probes/{id}
     */
    public function show(Request $request): void
    {
        $probe = $this->findOrFail($request);

        Response::json($probe + $this->probes->history((string) $probe['id'], $request->organizationId()));
    }

    /**
     * POST /api/probes
     */
    public function store(Request $request): void
    {
        if ($this->probes->countForOrganization($request->organizationId()) >= ProbeRepository::QUOTA) {
            throw HttpException::validation([
                'name' => sprintf('Un espace compte au plus %d sondes.', ProbeRepository::QUOTA),
            ]);
        }

        $probe = $this->probes->create(
            $request->organizationId(),
            $request->actorId(),
            $this->validatePayload($request),
        );

        $this->journal->record(
            $request,
            'created',
            (string) $probe['id'],
            self::ref($probe),
            (string) $probe['name'],
            version: (int) $probe['version'],
        );

        Response::created($probe);
    }

    /**
     * PUT /api/probes/{id}
     */
    public function update(Request $request): void
    {
        $existing = $this->findOrFail($request);

        $this->journal->assertNoConflict($request, $existing, 'Cette sonde');

        $attributes = $this->validatePayload($request, $existing);
        $probe      = $this->probes->update((string) $existing['id'], $request->organizationId(), $attributes);

        if ($probe === null) {
            throw HttpException::notFound('Sonde introuvable.');
        }

        $changes = $this->journal->diff($existing, $probe);

        if ($changes !== []) {
            $this->journal->record(
                $request,
                'updated',
                (string) $probe['id'],
                self::ref($probe),
                (string) $probe['name'],
                $changes,
                (int) $probe['version'],
            );
        }

        Response::json($probe);
    }

    /**
     * DELETE /api/probes/{id}
     */
    public function destroy(Request $request): void
    {
        $probe = $this->findOrFail($request);

        $this->probes->softDelete((string) $probe['id'], $request->organizationId());

        $this->journal->record($request, 'deleted', (string) $probe['id'], self::ref($probe), (string) $probe['name']);

        Response::noContent();
    }

    /**
     * POST /api/probes/{id}/restore
     */
    public function restore(Request $request): void
    {
        $id = $this->validateId($request);

        if (!$this->probes->restore($id, $request->organizationId())) {
            throw HttpException::notFound('Sonde introuvable.');
        }

        /** @var array<string, mixed> $probe */
        $probe = $this->probes->find($id, $request->organizationId());

        $this->journal->record($request, 'restored', $id, self::ref($probe), (string) $probe['name']);

        Response::json($probe);
    }

    /**
     * POST /api/probes/{id}/check
     *
     * « Vérifier maintenant » : l'échéance est avancée, le worker appelle dans
     * la minute. Une fois par sonde toutes les trente secondes, pour qu'un
     * double clic ou une boucle ne transforme pas la sonde en martèlement.
     */
    public function check(Request $request): void
    {
        $probe = $this->findOrFail($request);

        (new RateLimiter())->hit('probe-check', (string) $probe['id'], 1, 30);

        if (!$this->probes->checkSoon((string) $probe['id'], $request->organizationId())) {
            throw HttpException::validation(['is_paused' => 'Une sonde en pause ne se vérifie pas : reprenez-la d\'abord.']);
        }

        Response::json(['queued' => true], 202);
    }

    /**
     * @param array<string, mixed>|null $existing
     *
     * @return array{name: string, url: string, method: string, interval_seconds: int, timeout_ms: int, slow_ms: int, is_paused: bool}
     */
    private function validatePayload(Request $request, ?array $existing = null): array
    {
        $validator = new Validator($request->all());

        $name = ($existing === null || $request->has('name'))
            ? $validator->string('name', min: 1, max: 80, label: 'nom')
            : (string) $existing['name'];

        $url = ($existing === null || $request->has('url'))
            ? $validator->string('url', min: 8, max: 400, label: 'adresse')
            : (string) $existing['url'];

        if (is_string($url) && ($existing === null || $request->has('url'))) {
            try {
                // Sans résolution DNS ici : un nom peut changer d'adresse
                // demain. La vérification complète — résolution, adresses
                // publiques, épinglage — est refaite à CHAQUE appel, là où
                // elle protège vraiment (cf. HttpProbe).
                (new UrlGuard())->check($url, resolve: false);
            } catch (UnsafeUrl $refus) {
                $validator->addError('url', $refus->getMessage());
            }
        }

        $method = $validator->enum(
            'method',
            self::METHODS,
            required: false,
            default: isset($existing['method']) ? (string) $existing['method'] : 'GET',
        );

        $interval = $validator->integer(
            'interval_seconds',
            min: 60,
            max: 3600,
            default: isset($existing['interval_seconds']) ? (int) $existing['interval_seconds'] : 300,
            label: 'intervalle',
        );

        if ($interval !== null && !in_array($interval, self::INTERVALS, true)) {
            $validator->addError(
                'interval_seconds',
                'L\'intervalle est d\'une minute, cinq minutes, quinze minutes ou une heure.',
            );
        }

        $timeout = $validator->integer(
            'timeout_ms',
            min: 1000,
            max: 10000,
            default: isset($existing['timeout_ms']) ? (int) $existing['timeout_ms'] : 5000,
            label: 'délai',
        );

        $slow = $validator->integer(
            'slow_ms',
            min: 100,
            max: 10000,
            default: isset($existing['slow_ms']) ? (int) $existing['slow_ms'] : 1000,
            label: 'seuil de lenteur',
        );

        if ($timeout !== null && $slow !== null && $slow >= $timeout) {
            $validator->addError(
                'slow_ms',
                'Le seuil de lenteur reste sous le délai : au-delà, la sonde est en panne, pas lente.',
            );
        }

        $paused = $request->has('is_paused')
            ? $validator->boolean('is_paused')
            : (bool) ($existing['is_paused'] ?? false);

        $validator->check();

        return [
            'name'             => (string) $name,
            'url'              => (string) $url,
            'method'           => (string) $method,
            'interval_seconds' => (int) $interval,
            'timeout_ms'       => (int) $timeout,
            'slow_ms'          => (int) $slow,
            'is_paused'        => $paused,
        ];
    }

    /**
     * L'hôte, pas l'adresse entière : la référence d'une entrée du journal se
     * lit d'un coup d'œil, et un chemin peut contenir un jeton.
     *
     * @param array<string, mixed> $probe
     */
    private static function ref(array $probe): string
    {
        return (string) (parse_url((string) $probe['url'], PHP_URL_HOST) ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function findOrFail(Request $request): array
    {
        $probe = $this->probes->find($this->validateId($request), $request->organizationId());

        if ($probe === null) {
            throw HttpException::notFound('Sonde introuvable.');
        }

        return $probe;
    }

    private function validateId(Request $request): string
    {
        $validator = new Validator(['id' => $request->param('id')]);
        $id        = $validator->uuid('id');
        $validator->check();

        return (string) $id;
    }
}
