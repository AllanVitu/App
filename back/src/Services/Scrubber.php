<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Retire d'un texte technique ce qui désigne une personne ou ouvre un accès.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  UN MESSAGE D'ERREUR RECOPIE CE QU'IL A SOUS LA MAIN                    │
 * │                                                                         │
 * │  « duplicate key value violates unique constraint — Key (email)=(…) »   │
 * │  contient l'adresse fautive. Une chaîne de connexion recopie son mot de │
 * │  passe, une erreur HTTP recopie son en-tête d'autorisation. Ranger ces  │
 * │  textes tels quels ferait de la supervision un gisement de données      │
 * │  personnelles et de secrets, lisible par des personnes qui n'ont aucune │
 * │  raison de les voir.                                                    │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * CE QUI RESTE, À DESSEIN : les identifiants de ligne (UUID), les noms de
 * classe, les chemins, les dates et les heures. Ils ne désignent personne, et
 * ce sont précisément eux qu'on cherche en lisant une panne.
 *
 * C'est une défense PAR ÉNUMÉRATION : elle rattrape les formes connues, pas
 * les formes imprévues. Elle ne dispense pas de garder les messages
 * d'exception impersonnels — elle rattrape les fois où ils ne l'étaient pas.
 * Deux formes sont laissées de côté en connaissance de cause : l'IPv6 abrégée
 * (« fe80::1 »), indiscernable d'un appel statique « Feed::add », et les noms
 * propres, qu'aucun motif ne sait reconnaître.
 */
final class Scrubber
{
    /**
     * Motif => remplacement, appliqués DANS L'ORDRE.
     *
     * L'ordre compte : un jeton JWT contient des suites qui ressemblent à du
     * base64 quelconque, une clé d'API contient de l'hexadécimal. Les formes
     * les plus spécifiques passent d'abord, pour que le remplacement dise ce
     * qui a été retiré.
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        // Jetons JWT : trois segments base64url, le premier commence toujours
        // par « eyJ » — l'encodage de « {" ».
        '/\beyJ[\w-]{4,}\.[\w-]{4,}\.[\w-]{4,}/' => '[jeton]',

        // En-tête d'autorisation recopié tel quel.
        '/\bBearer\s+\S+/i' => 'Bearer [jeton]',

        // Clés d'API du module Backend.
        '/\b[sp]k_[A-Za-z0-9_]{8,}/' => '[clé]',

        // La VALEUR d'un champ sensible — « password=… », « "refresh_token": "…" ».
        // Le mot est gardé : savoir qu'un mot de passe traînait dans le message
        // est une information, sa valeur n'en est pas une.
        '/(?<![A-Za-z])(password|passwd|pwd|secret|token|api_key)(["\']?\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s,;&)]+)/i' => '$1$2[masqué]',

        // Adresses électroniques.
        '/[A-Z0-9._%+-]+@[A-Z0-9-]+(?:\.[A-Z0-9-]+)*\.[A-Z]{2,}/i' => '[courriel]',

        // Suites hexadécimales longues : jetons de réinitialisation et
        // d'invitation, empreintes. Un UUID, découpé par ses tirets, n'en est
        // pas une.
        '/\b[a-f0-9]{32,}\b/i' => '[jeton]',

        // Adresses IPv4, et IPv6 dans sa forme complète.
        '/\b(?:\d{1,3}\.){3}\d{1,3}\b/'             => '[ip]',
        '/\b(?:[a-f0-9]{1,4}:){7}[a-f0-9]{1,4}\b/i' => '[ip]',

        // Dix chiffres ou plus, séparés au plus d'une espace ou d'un point :
        // téléphone, carte, IBAN. Les tirets en sont exclus pour épargner les
        // dates, et un chiffre collé à une lettre pour épargner les UUID.
        '/(?<![\w-])\+?\d(?:[ .]?\d){9,}(?![\w-])/' => '[numéro]',
    ];

    public static function text(string $text): string
    {
        foreach (self::PATTERNS as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        return $text;
    }
}
