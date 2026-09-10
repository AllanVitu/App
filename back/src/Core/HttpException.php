<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/**
 * Erreur applicative destinée à être renvoyée telle quelle au client.
 *
 * Toute exception NON typée HttpException est considérée comme un bug et
 * devient un 500 générique : les détails techniques (requête SQL, chemin
 * de fichier...) ne fuitent jamais vers le client.
 */
class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $errors Erreurs de validation, champ => message
     * @param array<string, mixed>  $meta   Contexte que le client doit pouvoir exploiter
     */
    public function __construct(
        private readonly int $status,
        string $message,
        private readonly array $errors = [],
        ?Throwable $previous = null,
        private readonly array $meta = [],
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * ┌───────────────────────────────────────────────────────────────────────┐
     * │  UN REFUS PEUT AVOIR À TRANSPORTER AUTRE CHOSE QU'UN MESSAGE         │
     * │                                                                       │
     * │  « errors » dit ce qui ne va pas, champ par champ. Il ne sait pas     │
     * │  dire « et voici l'état courant ».                                    │
     * │                                                                       │
     * │  Un conflit d'écriture en a besoin : sans l'état du serveur, le       │
     * │  client ne peut que recharger — et perdre ce qui était en cours de    │
     * │  saisie. Avec lui, il peut proposer un arbitrage.                     │
     * └───────────────────────────────────────────────────────────────────────┘
     *
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    // --- Raccourcis de construction ----------------------------------------

    public static function badRequest(string $message = 'Requête invalide.'): self
    {
        return new self(400, $message);
    }

    /** @param array<string, string> $errors */
    public static function validation(array $errors, string $message = 'Les données envoyées sont invalides.'): self
    {
        return new self(422, $message, $errors);
    }

    public static function unauthorized(string $message = 'Authentification requise.'): self
    {
        return new self(401, $message);
    }

    public static function forbidden(string $message = 'Accès refusé.'): self
    {
        return new self(403, $message);
    }

    public static function notFound(string $message = 'Ressource introuvable.'): self
    {
        return new self(404, $message);
    }

    public static function conflict(string $message = 'Conflit avec une ressource existante.'): self
    {
        return new self(409, $message);
    }

    public static function tooManyRequests(string $message = 'Trop de tentatives. Réessayez plus tard.'): self
    {
        return new self(429, $message);
    }
}
