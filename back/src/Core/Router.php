<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Routeur HTTP minimaliste.
 *
 * Une route associe une méthode, un motif d'URL ({param} pour les segments
 * dynamiques), un contrôleur et une pile de middlewares exécutés avant lui.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: array{0: class-string, 1: string}, middleware: list<class-string>}> */
    private array $routes = [];

    /** @var list<class-string> Middlewares appliqués à toutes les routes */
    private array $globalMiddleware = [];

    /** @param class-string $middleware */
    public function addGlobalMiddleware(string $middleware): self
    {
        $this->globalMiddleware[] = $middleware;

        return $this;
    }

    /**
     * @param array{0: class-string, 1: string} $handler    [Contrôleur::class, 'méthode']
     * @param list<class-string>                $middleware
     */
    public function get(string $path, array $handler, array $middleware = []): self
    {
        return $this->add('GET', $path, $handler, $middleware);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<class-string>                $middleware
     */
    public function post(string $path, array $handler, array $middleware = []): self
    {
        return $this->add('POST', $path, $handler, $middleware);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<class-string>                $middleware
     */
    public function put(string $path, array $handler, array $middleware = []): self
    {
        return $this->add('PUT', $path, $handler, $middleware);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<class-string>                $middleware
     */
    public function patch(string $path, array $handler, array $middleware = []): self
    {
        return $this->add('PATCH', $path, $handler, $middleware);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<class-string>                $middleware
     */
    public function delete(string $path, array $handler, array $middleware = []): self
    {
        return $this->add('DELETE', $path, $handler, $middleware);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<class-string>                $middleware
     */
    private function add(string $method, string $path, array $handler, array $middleware): self
    {
        $this->routes[] = [
            'method'     => $method,
            'regex'      => $this->compile($path),
            'handler'    => $handler,
            'middleware' => $middleware,
        ];

        return $this;
    }

    /**
     * Transforme « /api/modules/{slug}/items » en expression régulière
     * nommée : les segments dynamiques deviennent des groupes capturants.
     */
    private function compile(string $path): string
    {
        $pattern = preg_replace_callback(
            '/\{([a-z_][a-z0-9_]*)\}/i',
            static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
            '/' . trim($path, '/'),
        );

        return '#^' . $pattern . '$#';
    }

    /**
     * Résout la requête, exécute les middlewares puis le contrôleur.
     *
     * @throws HttpException 404 si aucune route, 405 si le chemin existe
     *                       pour une autre méthode.
     */
    public function dispatch(Request $request): void
    {
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $request->method) {
                $allowedMethods[] = $route['method'];
                continue;
            }

            // Ne garder que les groupes nommés : ce sont les paramètres d'URL.
            $params = array_filter(
                $matches,
                static fn (int|string $key): bool => is_string($key),
                ARRAY_FILTER_USE_KEY,
            );

            $request->setRouteParams(array_map('urldecode', $params));

            foreach ([...$this->globalMiddleware, ...$route['middleware']] as $middlewareClass) {
                (new $middlewareClass())->handle($request);
            }

            [$controllerClass, $method] = $route['handler'];
            (new $controllerClass())->{$method}($request);

            return;
        }

        if ($allowedMethods !== []) {
            header('Allow: ' . implode(', ', array_unique($allowedMethods)));

            throw new HttpException(405, 'Méthode HTTP non autorisée pour cette ressource.');
        }

        throw HttpException::notFound("Endpoint inconnu : {$request->method} {$request->path}");
    }
}
