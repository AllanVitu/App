<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;

/**
 * Validation des entrées.
 *
 * Chaque accesseur valide un champ, mémorise l'erreur éventuelle et renvoie
 * la valeur nettoyée. Un unique appel à check() lève une 422 contenant TOUTES
 * les erreurs — le client peut ainsi afficher le formulaire complet d'un coup
 * plutôt que champ par champ.
 *
 *   $v        = new Validator($request->all());
 *   $email    = $v->email('email');
 *   $password = $v->password('password');
 *   $v->check();
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    /**
     * Chaîne de texte.
     */
    public function string(
        string $field,
        bool $required = true,
        int $min = 1,
        int $max = 255,
        ?string $default = null,
        ?string $label = null,
    ): ?string {
        $label = $label ?? $field;
        $value = $this->data[$field] ?? null;

        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = "Le champ « {$label} » est obligatoire.";
            }

            return $default;
        }

        if (!is_string($value)) {
            $this->errors[$field] = "Le champ « {$label} » doit être une chaîne de caractères.";

            return $default;
        }

        $value = trim($value);
        $length = mb_strlen($value);

        if ($length < $min) {
            $this->errors[$field] = "Le champ « {$label} » doit contenir au moins {$min} caractère(s).";
        } elseif ($length > $max) {
            $this->errors[$field] = "Le champ « {$label} » ne doit pas dépasser {$max} caractères.";
        }

        return $value;
    }

    public function email(string $field = 'email', bool $required = true): ?string
    {
        $value = $this->string($field, $required, 3, 254, label: 'e-mail');

        if ($value === null || isset($this->errors[$field])) {
            return $value;
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$field] = "L'adresse e-mail n'est pas valide.";

            return $value;
        }

        return mb_strtolower($value);
    }

    /**
     * Mot de passe : 8 caractères minimum, au moins une lettre et un chiffre.
     * Volontairement simple — une politique trop stricte pousse aux mots de
     * passe notés sur un post-it.
     */
    public function password(string $field = 'password', bool $required = true): ?string
    {
        $value = $this->data[$field] ?? null;

        if (!is_string($value) || $value === '') {
            if ($required) {
                $this->errors[$field] = 'Le mot de passe est obligatoire.';
            }

            return null;
        }

        if (mb_strlen($value) < 8) {
            $this->errors[$field] = 'Le mot de passe doit contenir au moins 8 caractères.';
        } elseif (mb_strlen($value) > 200) {
            // Garde-fou : bcrypt tronque au-delà de 72 octets, et un très long
            // mot de passe est un vecteur de déni de service au hachage.
            $this->errors[$field] = 'Le mot de passe est trop long (200 caractères maximum).';
        } elseif (!preg_match('/[a-zA-Z]/', $value) || !preg_match('/\d/', $value)) {
            $this->errors[$field] = 'Le mot de passe doit contenir au moins une lettre et un chiffre.';
        }

        return $value;
    }

    /**
     * Valeur restreinte à une liste blanche (statuts, thèmes, langues...).
     *
     * @param list<string> $allowed
     */
    public function enum(string $field, array $allowed, bool $required = true, ?string $default = null): ?string
    {
        $value = $this->data[$field] ?? null;

        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = "Le champ « {$field} » est obligatoire.";
            }

            return $default;
        }

        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $this->errors[$field] = "Valeur invalide pour « {$field} » (attendu : " . implode(', ', $allowed) . ').';

            return $default;
        }

        return $value;
    }

    public function boolean(string $field, bool $default = false): bool
    {
        $value = $this->data[$field] ?? null;

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * Date au format ISO (AAAA-MM-JJ), null autorisé.
     */
    public function date(string $field, bool $required = false): ?string
    {
        $value = $this->data[$field] ?? null;

        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] = "Le champ « {$field} » est obligatoire.";
            }

            return null;
        }

        if (!is_string($value)) {
            $this->errors[$field] = "Le champ « {$field} » doit être une date.";

            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->errors[$field] = "Le champ « {$field} » doit être une date au format AAAA-MM-JJ.";

            return null;
        }

        return $value;
    }

    /**
     * Objet JSON libre (colonnes JSONB).
     *
     * @return array<string, mixed>
     */
    public function jsonObject(string $field, array $default = []): array
    {
        $value = $this->data[$field] ?? null;

        if ($value === null) {
            return $default;
        }

        if (!is_array($value)) {
            $this->errors[$field] = "Le champ « {$field} » doit être un objet.";

            return $default;
        }

        // Un JSONB trop volumineux dégrade les index GIN : on borne la taille.
        if (strlen((string) json_encode($value)) > 16384) {
            $this->errors[$field] = "Le champ « {$field} » est trop volumineux (16 Ko maximum).";

            return $default;
        }

        return $value;
    }

    public function uuid(string $field, bool $required = true): ?string
    {
        $value = $this->data[$field] ?? null;

        if (!is_string($value) || $value === '') {
            if ($required) {
                $this->errors[$field] = "Le champ « {$field} » est obligatoire.";
            }

            return null;
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) !== 1) {
            $this->errors[$field] = "Identifiant invalide pour « {$field} ».";

            return null;
        }

        return $value;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Lève une 422 si au moins une règle a échoué.
     */
    public function check(): void
    {
        if ($this->errors !== []) {
            throw HttpException::validation($this->errors);
        }
    }
}
