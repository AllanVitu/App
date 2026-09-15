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
     * Mot de passe NOUVEAU : 80 bits, mesurés comme la CNIL les mesure
     * (cf. PasswordPolicy). La connexion ne passe pas par ici : un compte créé
     * sous l'ancienne règle continue d'ouvrir sa session.
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

        $problem = PasswordPolicy::problem($value);

        if ($problem !== null) {
            $this->errors[$field] = $problem;
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

    /**
     * Entier borné.
     *
     * FILTER_VALIDATE_INT refuse « 12.5 » et « douze » là où un simple cast
     * (int) les transformerait silencieusement en 12 et 0 — une valeur fausse
     * acceptée sans bruit est pire qu'une valeur rejetée.
     */
    public function integer(
        string $field,
        int $min,
        int $max,
        ?int $default = null,
        ?string $label = null,
    ): ?int {
        $label = $label ?? $field;
        $value = $this->data[$field] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        if ($parsed === false) {
            $this->errors[$field] = "Le champ « {$label} » doit être un nombre entier.";

            return $default;
        }

        if ($parsed < $min || $parsed > $max) {
            $this->errors[$field] = "Le champ « {$label} » doit être compris entre {$min} et {$max}.";

            return $default;
        }

        return $parsed;
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
     * @param  array<string, mixed> $default
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

    /**
     * Liste de chaînes courtes : étiquettes, mots-clés.
     *
     * La valeur est NORMALISÉE autant que validée. Sans cela « Bug », « bug »
     * et « bug  » coexisteraient comme trois étiquettes distinctes, et les
     * filtres deviendraient inutilisables au bout de quelques semaines.
     * L'ordre de première apparition est conservé : c'est celui que
     * l'utilisateur a saisi, donc celui qu'il s'attend à relire.
     *
     * @param  list<string> $default
     * @return list<string>
     */
    public function stringList(
        string $field,
        array $default = [],
        int $maxItems = 8,
        int $maxLength = 30,
        ?string $label = null,
    ): array {
        $label = $label ?? $field;
        $value = $this->data[$field] ?? null;

        if ($value === null) {
            return $default;
        }

        if (!is_array($value)) {
            $this->errors[$field] = "Le champ « {$label} » doit être une liste.";

            return $default;
        }

        $clean = [];

        foreach ($value as $entry) {
            if (!is_string($entry)) {
                $this->errors[$field] = "Le champ « {$label} » ne doit contenir que du texte.";

                return $default;
            }

            $entry = mb_strtolower(trim($entry));

            if ($entry === '') {
                continue;
            }

            if (mb_strlen($entry) > $maxLength) {
                $this->errors[$field] = "Chaque valeur de « {$label} » est limitée à {$maxLength} caractères.";

                return $default;
            }

            // in_array plutôt qu'array_unique en sortie : le dédoublonnage
            // doit précéder le comptage, sinon une liste de doublons serait
            // refusée alors qu'elle tient dans la limite une fois réduite.
            if (!in_array($entry, $clean, true)) {
                $clean[] = $entry;
            }
        }

        if (count($clean) > $maxItems) {
            $this->errors[$field] = "Le champ « {$label} » accepte au plus {$maxItems} valeurs.";

            return $default;
        }

        return $clean;
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
