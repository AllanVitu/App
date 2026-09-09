<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Database;
use App\Core\Kernel;
use App\Core\Request;
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
        'user_modules',
        'user_settings',
        'users',
    ];

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
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     * @param array<string, string> $query
     * @param array<string, string> $cookies
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
    ): array {
        $request = Request::create($method, $path, $body, $query, $headers, $cookies);

        // http_response_code() conserve sa valeur d'un appel à l'autre dans le
        // même processus : on la réinitialise pour ne pas hériter du test
        // précédent.
        http_response_code(200);

        ob_start();
        (new Kernel())->handle($request);
        $output = (string) ob_get_clean();

        $decoded = json_decode($output, true);

        return [
            'status' => http_response_code(),
            'body'   => is_array($decoded) ? $decoded : [],
        ];
    }

    /**
     * Crée un compte et renvoie son jeton d'accès et son identifiant.
     *
     * @return array{token: string, id: string, email: string}
     */
    protected function register(
        string $email = 'utilisateur@test.local',
        string $password = 'Motdepasse1',
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
}
