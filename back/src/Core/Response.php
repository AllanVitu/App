<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Réponse JSON de l'API.
 *
 * Format uniforme côté client :
 *   succès -> { "data": ... }            (+ "meta" pour la pagination)
 *   erreur -> { "message": ..., "errors": { champ: message } }
 */
final class Response
{
    /**
     * @param array<string, mixed>|list<mixed>|null $data
     * @param array<string, mixed>                  $meta
     */
    public static function json(mixed $data, int $status = 200, array $meta = []): void
    {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        self::send($payload, $status);
    }

    public static function created(mixed $data): void
    {
        self::json($data, 201);
    }

    /**
     * 204 : suppression ou action sans contenu de retour.
     */
    public static function noContent(): void
    {
        http_response_code(204);
    }

    /**
     * @param array<string, string> $errors
     */
    public static function error(string $message, int $status = 400, array $errors = []): void
    {
        $payload = ['message' => $message];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        self::send($payload, $status);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function send(array $payload, int $status): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            // Une réponse d'API ne doit jamais être mise en cache par le navigateur
            header('Cache-Control: no-store');
        }

        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
    }
}
