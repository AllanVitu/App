<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Database;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\UploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * Base des tests d'intégration.
 *
 * `call()` traverse le noyau réel — routeur, middlewares, contrôleurs,
 * PostgreSQL — sans serveur HTTP. C'est le même code que celui exécuté par
 * public/index.php : un test qui court-circuiterait le routeur ne dirait rien
 * du comportement déployé.
 */
abstract class ApiTestCase extends TestCase
{
    /**
     * L'adresse de toutes les requêtes de test (bloc réservé à la
     * documentation, RFC 5737). Sans elle, l'adresse serait nulle, et aucune
     * limite par IP ne compterait quoi que ce soit.
     */
    protected const REMOTE_ADDRESS = '192.0.2.10';

    /**
     * Tables vidées avant chaque test ; « modules » est conservée (catalogue).
     *
     * tickets et ticket_counters seraient vidées de toute façon par la cascade
     * depuis « users », mais les nommer rend la liste lisible : on voit ce qui
     * est remis à zéro sans avoir à dérouler mentalement les clés étrangères.
     */
    private const MUTABLE_TABLES = [
        // La file : les e-mails y sont déposés au lieu d'être envoyés dans la
        // requête. Sans ce vidage, chaque inscription de test laisserait une
        // tâche derrière elle.
        'jobs',
        'rate_limits',
        'activity',
        'presence',
        'user_tokens',
        'refresh_tokens',
        'login_attempts',
        'module_items',
        'tickets',
        'ticket_counters',
        'backend_tables',
        'backend_api_keys',
        'deployments',
        'error_events',
        'error_groups',
        'design_versions',
        'design_files',
        // TRUNCATE ne réveille pas les déclencheurs de suppression : vider
        // stored_files ne pose aucune pierre tombale, d'où les deux tables.
        'stored_files',
        'stored_file_tombstones',
        'organization_modules',
        'user_settings',
        'invitations',
        'memberships',
        'organizations',
        'users',
    ];

    /**
     * Table de routage alternative. Null : celle de l'application.
     *
     * Un test qui doit provoquer une panne VRAIE — une exception que rien
     * n'attrape — ajoute ses propres routes à celles de l'application plutôt
     * que de simuler le noyau : c'est le vrai chemin de gestion d'erreur qui
     * doit être traversé.
     */
    protected ?string $routesFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        // TRUNCATE ... CASCADE remet la base dans un état connu entre deux
        // tests : l'ordre d'exécution ne peut plus influencer le résultat.
        Database::connection()->exec(
            'TRUNCATE ' . implode(', ', self::MUTABLE_TABLES) . ' RESTART IDENTITY CASCADE',
        );
    }

    /**
     * Exécute une requête et renvoie le statut et le corps décodé.
     *
     * @param array<string, mixed>        $body
     * @param array<string, string>       $headers
     * @param array<string, string>       $query
     * @param array<string, string>       $cookies
     * @param array<string, UploadedFile> $files
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    protected function call(
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
        array $query = [],
        array $cookies = [],
        array $files = [],
    ): array {
        $raw     = $this->callRaw($method, $path, $body, $headers, $query, $cookies, $files);
        $decoded = json_decode($raw['output'], true);

        return [
            'status' => $raw['status'],
            'body'   => is_array($decoded) ? $decoded : [],
        ];
    }

    /**
     * La même traversée, sortie brute : un fichier servi n'est pas du JSON.
     *
     * @param array<string, mixed>        $body
     * @param array<string, string>       $headers
     * @param array<string, string>       $query
     * @param array<string, string>       $cookies
     * @param array<string, UploadedFile> $files
     *
     * @return array{status: int, output: string}
     */
    protected function callRaw(
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
        array $query = [],
        array $cookies = [],
        array $files = [],
    ): array {
        $request = Request::create($method, $path, $body, $query, $headers, $cookies, $files, self::REMOTE_ADDRESS);

        // http_response_code() conserve sa valeur d'un appel à l'autre dans le
        // même processus : on la réinitialise pour ne pas hériter du test
        // précédent.
        http_response_code(200);

        ob_start();
        (new Kernel($this->routesFile))->handle($request);
        $output = (string) ob_get_clean();

        return ['status' => (int) http_response_code(), 'output' => $output];
    }

    /**
     * Crée un compte et renvoie son jeton d'accès, son identifiant et celui de
     * l'espace de travail créé avec lui.
     *
     * @return array{token: string, id: string, email: string, org: string}
     */
    protected function register(
        string $email = 'utilisateur@test.local',
        string $password = 'Motdepasse1-solide',
        string $name = 'Utilisateur Test',
    ): array {
        $response = $this->call('POST', '/api/auth/register', [
            'full_name'      => $name,
            'email'          => $email,
            'password'       => $password,
            // Le consentement fait partie du contrat d'inscription : un
            // compte ne peut plus être créé sans lui.
            'terms_accepted' => true,
        ]);

        $this->assertSame(201, $response['status'], 'inscription impossible');

        return [
            'token' => $response['body']['data']['access_token'],
            'id'    => $response['body']['data']['user']['id'],
            'email' => $email,
            // L'espace de travail créé avec le compte. Les tests qui écrivent
            // directement en base — sans passer par l'API — en ont besoin :
            // c'est lui qui cloisonne, et non plus l'identifiant du compte.
            'org'   => $response['body']['data']['organization']['id'],
        ];
    }

    /**
     * En-tête d'authentification prêt à l'emploi.
     *
     * @return array<string, string>
     */
    protected function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    /**
     * Identifiant d'un module du catalogue, par son slug.
     */
    protected function moduleSlug(): string
    {
        return 'backend';
    }

    /**
     * Dernier jeton en clair émis n'étant pas récupérable (il n'existe que
     * dans l'e-mail), les tests lisent son empreinte pour vérifier
     * l'existence d'une demande, jamais sa valeur.
     */
    protected function pendingTokenCount(string $userId, string $type): int
    {
        $statement = Database::connection()->prepare(
            'SELECT count(*) FROM user_tokens
              WHERE user_id = :id AND type = :type::user_token_type
                AND used_at IS NULL AND expires_at > NOW()',
        );
        $statement->execute(['id' => $userId, 'type' => $type]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Un second compte, membre de l'espace de l'hôte, par le vrai chemin :
     * l'invitation part, le lien est lu dans l'e-mail en file, l'inscription
     * l'utilise.
     *
     * @param  array{token: string} $hote
     * @return array{token: string, id: string}
     */
    protected function membre(array $hote, string $email): array
    {
        $invitation = $this->call('POST', '/api/organizations/invitations', [
            'email' => $email,
            'role'  => 'member',
        ], $this->bearer($hote['token']));

        $this->assertSame(201, $invitation['status'], 'invitation refusée');

        $statement = Database::connection()->prepare(
            "SELECT payload FROM jobs WHERE type = 'mail.send' AND payload->>'to' = :email ORDER BY created_at DESC LIMIT 1",
        );
        $statement->execute(['email' => $email]);

        $payload = json_decode((string) $statement->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        preg_match('/token=([a-f0-9]{64})/', (string) $payload['text'], $trouve);

        $inscription = $this->call('POST', '/api/auth/register', [
            'full_name'        => 'Membre Simple',
            'email'            => $email,
            'password'         => 'Motdepasse1-solide',
            'terms_accepted'   => true,
            'invitation_token' => $trouve[1],
        ]);

        $this->assertSame(201, $inscription['status'], json_encode($inscription['body']) ?: '');

        return [
            'token' => (string) $inscription['body']['data']['access_token'],
            'id'    => (string) $inscription['body']['data']['user']['id'],
        ];
    }
}
