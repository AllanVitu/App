<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Un fichier reçu dans un formulaire multipart.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  CE QUE LE NAVIGATEUR ANNONCE N'EST PAS CRU                             │
 * │                                                                         │
 * │  « $_FILES[…]['type'] » est écrit par le client : un script renommé en  │
 * │  « photo.png » arrive avec « image/png » si l'on veut. Ce type n'est    │
 * │  donc même pas conservé ici — le vrai se lit dans les octets (cf.       │
 * │  FileStorage). Le nom d'origine ne sert qu'à proposer un nom au         │
 * │  téléchargement, jamais à choisir un chemin.                            │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
final class UploadedFile
{
    public function __construct(
        public readonly string $clientName,
        public readonly string $path,
        public readonly int $size,
        public readonly int $error = UPLOAD_ERR_OK,
    ) {
    }

    /**
     * Depuis une entrée de $_FILES.
     *
     * Le chemin temporaire n'est accepté que si PHP l'a lui-même reçu :
     * is_uploaded_file() empêche qu'une entrée forgée fasse lire un fichier du
     * serveur comme s'il venait d'être envoyé.
     *
     * Les champs multiples (« file[] ») sont écartés : aucune route n'en
     * attend, et leur forme imbriquée ne doit pas passer pour un fichier unique.
     *
     * @param array<mixed> $entry
     */
    public static function fromGlobals(array $entry): ?self
    {
        $path  = $entry['tmp_name'] ?? null;
        $error = $entry['error'] ?? UPLOAD_ERR_NO_FILE;

        if (!is_string($path) || !is_int($error)) {
            return null;
        }

        if ($error === UPLOAD_ERR_OK && !is_uploaded_file($path)) {
            return null;
        }

        return new self(
            clientName: is_string($entry['name'] ?? null) ? $entry['name'] : '',
            path: $path,
            size: is_int($entry['size'] ?? null) ? $entry['size'] : 0,
            error: $error,
        );
    }

    /**
     * @throws RuntimeException si le fichier temporaire est illisible
     */
    public function contents(): string
    {
        $contents = @file_get_contents($this->path);

        if ($contents === false) {
            throw new RuntimeException('Fichier reçu illisible.');
        }

        return $contents;
    }
}
