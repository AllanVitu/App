<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Models\ActivityRepository;
use App\Models\ErrorRepository;
use App\Models\OrganizationRepository;
use Throwable;

/**
 * L'application range ses propres pannes dans son module Supervision.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  UN MODULE DE SUPERVISION QUI NE SE SUPERVISAIT PAS                     │
 * │                                                                         │
 * │  Les erreurs des applications clientes arrivaient ici, triées, comptées │
 * │  et suivies en temps réel. Celles de l'API partaient dans « error_log » │
 * │  — la sortie d'un conteneur que personne ne lit. Le navigateur, lui,    │
 * │  écrivait les siennes dans une console que personne n'ouvre.            │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Trois sources, un seul chemin : l'API (Kernel), le navigateur
 * (ClientErrorController) et le worker (Queue::fail). Toutes aboutissent dans
 * l'espace de l'instance, réservé à ses administrateurs.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  UNE PILE D'APPELS EST UNE FUITE QUI S'IGNORE                           │
 * │                                                                         │
 * │  Une trace PHP ordinaire recopie les ARGUMENTS de chaque appel — dont   │
 * │  le mot de passe passé à password_verify(). Le message d'une violation  │
 * │  d'unicité recopie la valeur fautive, souvent une adresse.              │
 * │                                                                         │
 * │  Deux règles donc : les traces sont RECONSTRUITES cadre par cadre, sans │
 * │  un seul argument ; et tout texte passe par Scrubber avant la base.     │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * CE SERVICE NE LÈVE JAMAIS. Il s'exécute quand quelque chose a déjà échoué ;
 * échouer à son tour ne doit ni masquer la réponse 500, ni en produire une
 * seconde. Son propre échec est journalisé, puis abandonné.
 */
final class SelfMonitor
{
    /**
     * Occurrences DÉTAILLÉES par panne et par minute.
     *
     * Au-delà, l'occurrence est comptée sans être stockée. Une boucle qui
     * échoue cent fois par seconde remplirait sinon la table en quelques
     * minutes — et c'est pendant un incident que la base a le moins de marge.
     * Vingt suffisent à lire le problème ; le compteur en dit l'ampleur.
     */
    private const DETAILED_PER_MINUTE = 20;

    /** Cadres gardés par exception : au-delà, c'est le cadre applicatif qui se noie. */
    private const MAX_FRAMES = 30;

    /** Garde contre la récursion — une panne pendant le signalement d'une panne. */
    private static bool $busy = false;

    /**
     * Une exception que l'API n'a pas prévue.
     *
     * @return string La référence remise à l'utilisateur, retrouvable dans le
     *                contexte de l'occurrence
     */
    public function captureException(Throwable $exception, ?Request $request = null): string
    {
        $reference = bin2hex(random_bytes(4));
        $message   = Scrubber::text($this->messageChain($exception));
        $culprit   = $this->relative($exception->getFile()) . ':' . $exception->getLine();

        // La sortie du conteneur reste alimentée : c'est le seul recours quand
        // la base elle-même est la cause de la panne. Même texte nettoyé.
        error_log(sprintf('[API] réf. %s — %s (%s)', $reference, $this->firstLine($message), $culprit));

        $this->store(
            fingerprint: 'api-' . $this->digest($exception::class . '|' . $culprit),
            // Une Error PHP (TypeError, appel sur null…) est un défaut du code ;
            // une Exception peut être une panne de l'environnement.
            level: $exception instanceof \Error ? 'fatal' : 'error',
            title: $this->shortClass($exception) . ' : ' . $this->firstLine($message),
            culprit: $culprit,
            message: $message,
            stack: $this->trace($exception),
            context: array_filter([
                'source'    => 'api',
                'reference' => $reference,
                'method'    => $request?->method,
                'route'     => $this->routeOf($request),
                'exception' => $exception::class,
            ], static fn (?string $value): bool => $value !== null),
        );

        return $reference;
    }

    /**
     * Une erreur remontée par le navigateur.
     *
     * @param array{kind: string, message: string, stack: ?string, route: ?string, component: ?string, release: ?string} $report
     */
    public function captureClientError(array $report): void
    {
        $message = Scrubber::text($report['message']);
        $stack   = $report['stack'] !== null ? Scrubber::text($report['stack']) : null;
        $culprit = implode(' · ', array_filter([$report['route'], $report['component']]));

        $this->store(
            fingerprint: 'web-' . $this->digest(implode('|', [
                $report['kind'],
                $this->normalize($this->firstLine($message)),
                $this->normalize($this->topFrame($stack)),
            ])),
            level: 'error',
            title: $this->firstLine($message),
            culprit: $culprit !== '' ? $culprit : null,
            message: $message,
            stack: $stack,
            context: array_filter([
                'source'    => 'web',
                'kind'      => $report['kind'],
                'route'     => $report['route'],
                'component' => $report['component'],
                'release'   => $report['release'],
            ], static fn (?string $value): bool => $value !== null),
        );
    }

    /**
     * Une tâche de fond abandonnée après son dernier essai.
     *
     * Personne ne la réessaiera : c'est une panne, et elle ne se voyait qu'en
     * interrogeant la table « jobs » à la main.
     */
    public function captureJobFailure(string $type, string $error): void
    {
        $message = Scrubber::text($error);

        $this->store(
            fingerprint: 'job-' . $this->digest($type . '|' . $this->normalize($this->firstLine($message))),
            level: 'error',
            title: 'Tâche abandonnée : ' . $type,
            culprit: 'worker · ' . $type,
            message: $message,
            stack: null,
            context: ['source' => 'worker', 'job' => $type],
        );
    }

    // -----------------------------------------------------------------------
    //  Rangement
    // -----------------------------------------------------------------------

    /**
     * @param array<string, string> $context
     */
    private function store(
        string $fingerprint,
        string $level,
        string $title,
        ?string $culprit,
        string $message,
        ?string $stack,
        array $context,
    ): void {
        if (self::$busy) {
            return;
        }

        self::$busy = true;

        try {
            $pdo = Database::connection();

            // Une exception levée en pleine transaction la laisse AVORTÉE :
            // PostgreSQL refuse alors toute écriture jusqu'au ROLLBACK. Et la
            // requête a échoué — rien de ce qu'elle avait écrit ne doit tenir.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $organizationId = (new OrganizationRepository())->instanceId();
            $errors         = new ErrorRepository();

            if ($errors->recentEventCount($organizationId, $fingerprint, 60) >= self::DETAILED_PER_MINUTE) {
                $errors->countOccurrence($organizationId, $fingerprint);

                return;
            }

            $group = $errors->record($organizationId, [
                'fingerprint' => $fingerprint,
                'title'       => mb_substr($title, 0, 200),
                'culprit'     => $culprit !== null ? mb_substr($culprit, 0, 200) : null,
                'level'       => $level,
                'message'     => mb_substr($message, 0, 5000),
                'stack'       => $stack !== null ? mb_substr($stack, 0, 20000) : null,
                'context'     => $context,
            ]);

            // Même règle que l'ingestion : la PREMIÈRE occurrence fait un fait
            // dans le fil, et le retour d'une panne qu'on croyait réglée aussi.
            // Les autres ne font que monter le compteur.
            $action = $group['reopened'] ? 'reopened' : ((int) $group['occurrences'] === 1 ? 'created' : null);

            if ($action !== null) {
                (new ActivityRepository())->record(
                    $organizationId,
                    null,
                    null,
                    'supervision',
                    $action,
                    (string) $group['id'],
                    null,
                    (string) $group['title'],
                    [],
                    (int) $group['version'],
                );
            }
        } catch (Throwable $failure) {
            error_log(sprintf(
                '[supervision] signalement impossible : %s — %s',
                $failure::class,
                Scrubber::text($failure->getMessage()),
            ));
        } finally {
            self::$busy = false;
        }
    }

    // -----------------------------------------------------------------------
    //  Mise en forme
    // -----------------------------------------------------------------------

    /**
     * La trace, reconstruite SANS ARGUMENTS, causes comprises.
     *
     * getTraceAsString() recopie les arguments de chaque appel quand
     * « zend.exception_ignore_args » est désactivé — le réglage par défaut du
     * php.ini de développement. On ne dépend donc pas du réglage : les cadres
     * sont réécrits un par un, avec ce qui situe l'appel et rien de ce qui
     * transitait.
     */
    private function trace(Throwable $exception): string
    {
        $lines = [];
        $depth = 0;

        for ($current = $exception; $current !== null && $depth < 3; $current = $current->getPrevious(), $depth++) {
            if ($depth > 0) {
                $lines[] = '';
                $lines[] = sprintf(
                    'Causée par %s : %s',
                    $current::class,
                    $this->firstLine(Scrubber::text($current->getMessage())),
                );
            }

            $lines[] = sprintf('levée dans %s:%d', $this->relative($current->getFile()), $current->getLine());

            foreach (array_slice($current->getTrace(), 0, self::MAX_FRAMES) as $index => $frame) {
                $lines[] = sprintf(
                    '#%d %s%s%s()%s',
                    $index,
                    $frame['class'] ?? '',
                    $frame['type'] ?? '',
                    $frame['function'],
                    isset($frame['file']) ? ' — ' . $this->relative($frame['file']) . ':' . ($frame['line'] ?? 0) : '',
                );
            }
        }

        return implode("\n", $lines);
    }

    /** Le message, et ceux des causes : c'est souvent la cause qui parle. */
    private function messageChain(Throwable $exception): string
    {
        $messages = [];

        for ($current = $exception; $current !== null && count($messages) < 3; $current = $current->getPrevious()) {
            $messages[] = $current->getMessage();
        }

        return implode("\n— causée par : ", array_filter($messages, static fn (string $m): bool => $m !== ''));
    }

    /**
     * Le MOTIF de la route, pas le chemin.
     *
     * « /api/tickets/{id} » dit où chercher ; le chemin, lui, porterait un
     * identifiant différent à chaque occurrence sans rien apprendre de plus.
     * Sans route résolue — une panne avant le routage — les UUID du chemin
     * sont remplacés à la main.
     */
    private function routeOf(?Request $request): ?string
    {
        if ($request === null) {
            return null;
        }

        $route = $request->attribute('route');

        if (is_string($route)) {
            return $route;
        }

        return preg_replace(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            '{id}',
            $request->path,
        ) ?? $request->path;
    }

    /**
     * Ce qui change d'une compilation à l'autre sans changer la panne.
     *
     * Vite nomme ses morceaux d'après leur contenu — « TicketsView-Bx3kLm9a.js »
     * — et une compilation minifiée déplace les colonnes à chaque livraison.
     * Sans cette réduction, chaque déploiement ouvrirait un nouveau groupe pour
     * la même erreur, et le compteur d'occurrences ne voudrait plus rien dire.
     */
    private function normalize(string $text): string
    {
        $text = preg_replace('#https?://[^/\s]+#', '', $text) ?? $text;
        $text = preg_replace('/\?[^\s:)]*/', '', $text) ?? $text;
        $text = preg_replace('/-[A-Za-z0-9_-]{8}(?=\.(?:js|css)\b)/', '', $text) ?? $text;

        return preg_replace('/\d+/', 'N', $text) ?? $text;
    }

    /** Le premier cadre qui pointe vers un fichier du client. */
    private function topFrame(?string $stack): string
    {
        foreach (explode("\n", (string) $stack) as $line) {
            if (preg_match('/\.(?:js|mjs|ts|vue)\b/', $line) === 1) {
                return trim($line);
            }
        }

        return '';
    }

    private function firstLine(string $text): string
    {
        $line = trim(strtok($text, "\n") ?: '');

        return $line !== '' ? mb_substr($line, 0, 180) : 'Erreur sans message';
    }

    private function shortClass(Throwable $exception): string
    {
        $parts = explode('\\', $exception::class);

        return (string) end($parts);
    }

    /** Chemin relatif à la racine de l'API : le chemin absolu ne dit rien de plus. */
    private function relative(string $path): string
    {
        $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : basename($path);
    }

    private function digest(string $value): string
    {
        return substr(hash('sha256', $value), 0, 32);
    }
}
