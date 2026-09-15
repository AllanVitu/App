<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Représentation immuable de la requête HTTP entrante.
 *
 * Le corps JSON est décodé une seule fois, à la construction : les
 * contrôleurs et middlewares travaillent tous sur la même donnée.
 */
final class Request
{
    /** @var array<string, mixed> */
    private array $body;

    /** @var array<string, mixed> Données attachées par les middlewares (ex. utilisateur authentifié) */
    private array $attributes = [];

    /** @var array<string, string> Paramètres extraits de l'URL (ex. {slug}) */
    private array $routeParams = [];

    /** Vrai si le corps annonçait du JSON mais n'a pas pu être décodé. */
    private bool $bodyIsMalformed = false;

    /** @var array<string, UploadedFile> Fichiers d'un envoi multipart, par nom de champ */
    private array $files = [];

    /** L'adresse qui a ouvert la connexion — celle du dernier intermédiaire, s'il y en a un. */
    private ?string $remoteAddress = null;

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $body
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        /** @var array<string, string> */
        public readonly array $query,
        /** @var array<string, string> */
        public readonly array $headers,
        /** @var array<string, string> */
        public readonly array $cookies,
        array $body,
    ) {
        $this->body = $body;
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $body   = self::parseBody();

        $request = new self(
            method:  $method,
            path:    '/' . trim($path, '/'),
            query:   array_map('strval', $_GET),
            headers: self::normalizeHeaders(),
            cookies: array_map('strval', $_COOKIE),
            body:    $body['data'],
        );

        // La capture ne lève jamais d'exception : elle a lieu avant le bloc
        // try du contrôleur frontal (et avant les en-têtes CORS). L'anomalie
        // est mémorisée, puis signalée au bon moment par assertBodyIsValid().
        $request->bodyIsMalformed = $body['malformed'];
        $request->files           = self::parseFiles();
        $request->remoteAddress   = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : null;

        return $request;
    }

    /**
     * Construit une requête sans passer par les superglobales.
     *
     * Seconde fabrique volontaire : `capture()` lit l'environnement PHP-FPM,
     * `create()` prend ses valeurs en argument. C'est ce qui rend l'API
     * testable de bout en bout — sans elle, il faudrait simuler `php://input`,
     * impossible en ligne de commande.
     *
     * @param array<string, mixed>        $body
     * @param array<string, string>       $query
     * @param array<string, string>       $headers
     * @param array<string, string>       $cookies
     * @param array<string, UploadedFile> $files
     */
    public static function create(
        string $method,
        string $path,
        array $body = [],
        array $query = [],
        array $headers = [],
        array $cookies = [],
        array $files = [],
        ?string $remoteAddress = null,
    ): self {
        $request = new self(
            method:  strtoupper($method),
            path:    '/' . trim($path, '/'),
            query:   $query,
            headers: array_change_key_case($headers, CASE_LOWER),
            cookies: $cookies,
            body:    $body,
        );

        $request->files         = $files;
        $request->remoteAddress = $remoteAddress;

        return $request;
    }

    /**
     * Rejette explicitement un corps JSON illisible.
     *
     * Sans ce contrôle, un JSON tronqué produirait un corps vide, donc des
     * erreurs de validation trompeuses (« champ obligatoire ») au lieu d'un
     * diagnostic clair.
     *
     * @throws HttpException 400
     */
    public function assertBodyIsValid(): void
    {
        if ($this->bodyIsMalformed) {
            throw HttpException::badRequest('Le corps de la requête n\'est pas un JSON valide.');
        }
    }

    /**
     * @return array<string, string> En-têtes en minuscules : content-type, authorization...
     */
    private static function normalizeHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        // Nginx/FastCGI expose ces deux en-têtes hors du préfixe HTTP_
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $server => $header) {
            if (isset($_SERVER[$server])) {
                $headers[$header] = (string) $_SERVER[$server];
            }
        }

        return $headers;
    }

    /**
     * @return array{data: array<string, mixed>, malformed: bool}
     */
    private static function parseBody(): array
    {
        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');

        // Un envoi de fichier : PHP a déjà lu le corps et rempli $_POST et
        // $_FILES — « php://input » est vide pour ce type de contenu.
        if (str_contains($contentType, 'multipart/form-data')) {
            /** @var array<string, mixed> $fields */
            $fields = $_POST;

            return ['data' => $fields, 'malformed' => false];
        }

        $raw = file_get_contents('php://input');

        if ($raw === false || $raw === '') {
            return ['data' => [], 'malformed' => false];
        }

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($raw, true);

            // json_decode renvoie null aussi bien pour un JSON cassé que pour
            // un encodage non-UTF-8 : les deux cas doivent remonter au client.
            if (!is_array($decoded)) {
                return ['data' => [], 'malformed' => true];
            }

            return ['data' => $decoded, 'malformed' => false];
        }

        // Repli sur les formulaires classiques (application/x-www-form-urlencoded)
        parse_str($raw, $parsed);

        return ['data' => $parsed, 'malformed' => false];
    }

    // --- Lecture du corps ---------------------------------------------------

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Chaîne nettoyée : trim systématique, null si absente.
     */
    public function string(string $key, ?string $default = null): ?string
    {
        $value = $this->body[$key] ?? null;

        if (!is_string($value)) {
            return $default;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? $default : $trimmed;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->body;
    }

    /**
     * Le fichier envoyé sous ce nom de champ, ou null si aucun ne l'a été.
     *
     * « Aucun fichier choisi » est une absence, pas une erreur : un champ de
     * fichier laissé vide arrive avec UPLOAD_ERR_NO_FILE.
     */
    public function file(string $field): ?UploadedFile
    {
        $file = $this->files[$field] ?? null;

        return $file === null || $file->error === UPLOAD_ERR_NO_FILE ? null : $file;
    }

    /**
     * @return array<string, UploadedFile>
     */
    private static function parseFiles(): array
    {
        $files = [];

        foreach ($_FILES as $field => $entry) {
            if (!is_string($field) || !is_array($entry)) {
                continue;
            }

            $file = UploadedFile::fromGlobals($entry);

            if ($file !== null) {
                $files[$field] = $file;
            }
        }

        return $files;
    }

    // --- Query string -------------------------------------------------------

    public function queryParam(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;

        if (!is_string($value)) {
            return $default;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? $default : $trimmed;
    }

    public function queryInt(string $key, int $default, int $min, int $max): int
    {
        $value = $this->query[$key] ?? null;

        if (!is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    // --- En-têtes et contexte ----------------------------------------------

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * Jeton d'accès porté par l'en-tête « Authorization: Bearer <token> ».
     */
    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');

        if ($header === null || !preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * L'adresse qui compte pour les limites de débit et le journal des
     * sessions. « X-Forwarded-For » n'est lu que s'il vient d'un intermédiaire
     * déclaré : cf. ClientIp.
     */
    public function ip(): ?string
    {
        return ClientIp::resolve($this->remoteAddress, $this->header('x-forwarded-for'), ClientIp::trustedProxies());
    }

    public function userAgent(): ?string
    {
        $agent = $this->header('user-agent');

        return $agent === null ? null : mb_substr($agent, 0, 500);
    }

    // --- Attributs (middlewares) -------------------------------------------

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Utilisateur authentifié, garanti non nul sur une route protégée.
     *
     * @return array<string, mixed>
     */
    public function user(): array
    {
        $user = $this->attribute('user');

        if (!is_array($user)) {
            throw HttpException::unauthorized();
        }

        return $user;
    }

    public function userId(): string
    {
        return (string) $this->user()['id'];
    }

    /**
     * Compte à l'origine de l'appel, ou null si c'est une clé d'API.
     *
     * ┌───────────────────────────────────────────────────────────────────────┐
     * │  « QUI A ÉCRIT CETTE LIGNE » N'A PAS TOUJOURS DE RÉPONSE              │
     * │                                                                       │
     * │  Une erreur signalée par un serveur de production arrive avec une clé │
     * │  de service : il y a bien une organisation destinataire, mais aucune  │
     * │  personne derrière. C'est ce que cette méthode dit, là où userId()    │
     * │  refuserait la requête.                                               │
     * └───────────────────────────────────────────────────────────────────────┘
     */
    public function actorId(): ?string
    {
        $user = $this->attribute('user');

        return is_array($user) ? (string) $user['id'] : null;
    }

    // --- Organisation courante ----------------------------------------------

    /**
     * Espace de travail de la requête, posé par AuthMiddleware ou
     * IngestMiddleware. Garanti non nul sur une route protégée.
     *
     * « kind » vaut « team » pour un espace de travail, « instance » pour
     * l'espace où l'application range ses propres pannes.
     *
     * @return array{id: string, name: string, slug: string, kind: string, role: string}
     */
    public function organization(): array
    {
        $organization = $this->attribute('organization');

        if (!is_array($organization)) {
            throw HttpException::unauthorized();
        }

        /** @var array{id: string, name: string, slug: string, kind: string, role: string} $organization */
        return $organization;
    }

    /**
     * LA valeur de cloisonnement : tout ce qu'un dépôt lit ou écrit en dépend.
     */
    public function organizationId(): string
    {
        return $this->organization()['id'];
    }

    /** Rôle du demandeur DANS cette organisation — owner, admin ou member. */
    public function organizationRole(): string
    {
        return $this->organization()['role'];
    }

    // --- Paramètres de route ------------------------------------------------

    /** @param array<string, string> $params */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function param(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }
}
