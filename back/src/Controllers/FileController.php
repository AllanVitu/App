<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Services\FileStorage;
use App\Services\SignedUrl;
use RuntimeException;

/**
 * GET /api/files/{id}?expires=…&signature=… — un fichier, servi à qui en a
 * reçu l'adresse.
 *
 * Aucune session n'est demandée : une balise <img> n'en envoie pas. C'est la
 * SIGNATURE qui autorise, et l'API ne la remet qu'à quelqu'un qui a le droit
 * de voir le fichier (cf. SignedUrl).
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  UN FICHIER TÉLÉVERSÉ EST SERVI COMME UN OBJET HOSTILE                  │
 * │                                                                         │
 * │  Même contrôlé, un fichier reste écrit par quelqu'un d'autre. Il part   │
 * │  donc avec les en-têtes qui l'empêchent de devenir autre chose qu'une   │
 * │  image : le type lu à la réception et l'interdiction d'en deviner un    │
 * │  autre (nosniff), une politique de contenu qui n'autorise rien et met   │
 * │  le document en bac à sable s'il est ouvert seul, et aucun référent.    │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
final class FileController
{
    public function show(Request $request): void
    {
        $id        = $request->param('id') ?? '';
        $expires   = $request->queryParam('expires') ?? '';
        $signature = $request->queryParam('signature') ?? '';

        // La signature est vérifiée AVANT la base : un lien invalide reçoit la
        // même réponse, que le fichier existe ou non.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) !== 1
            || !SignedUrl::isValid($id, $expires, $signature)
        ) {
            throw HttpException::forbidden('Ce lien a expiré ou n\'est pas valide. Rechargez la page pour en obtenir un nouveau.');
        }

        $storage = new FileStorage();
        $file    = $storage->find($id);

        if ($file === null) {
            throw HttpException::notFound('Fichier introuvable.');
        }

        $path = $storage->pathFor((string) $file['storage_key']);

        if (!is_file($path)) {
            // Une description sans octets : c'est une panne, pas une saisie,
            // et elle doit arriver en supervision.
            throw new RuntimeException("Octets introuvables pour le fichier {$id}.");
        }

        $headers     = self::headersFor($file, (int) $expires, time());
        $notModified = $request->header('if-none-match') === $headers['ETag'];

        if ($notModified) {
            unset($headers['Content-Length']);
        }

        if (!headers_sent()) {
            http_response_code($notModified ? 304 : 200);

            foreach ($headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        if (!$notModified) {
            readfile($path);
        }
    }

    /**
     * Les en-têtes d'un fichier servi.
     *
     * Une fonction pure, pour que chacun se vérifie sans serveur : en ligne de
     * commande, PHP ne garde aucune trace des en-têtes émis.
     *
     * @param  array<string, mixed>  $file
     * @return array<string, string>
     */
    public static function headersFor(array $file, int $expires, int $now): array
    {
        return [
            'Content-Type'                 => (string) $file['media_type'],
            'Content-Length'               => (string) $file['byte_size'],
            'Content-Disposition'          => self::disposition($file['original_name'] ?? null),
            // « private » : un cache partagé n'a pas à garder le fichier d'un
            // espace. Et jamais au-delà de l'échéance du lien.
            'Cache-Control'                => 'private, max-age=' . max(0, $expires - $now),
            'ETag'                         => '"' . $file['sha256'] . '"',
            'X-Content-Type-Options'       => 'nosniff',
            'Content-Security-Policy'      => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-site',
            'Referrer-Policy'              => 'no-referrer',
        ];
    }

    /**
     * « inline » : ce sont des images, faites pour s'afficher. Le nom est donné
     * deux fois — en ASCII pour les clients anciens, encodé (RFC 5987) pour
     * garder les accents.
     */
    private static function disposition(mixed $name): string
    {
        $name  = is_string($name) && $name !== '' ? $name : 'fichier';
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'fichier';

        return sprintf("inline; filename=\"%s\"; filename*=UTF-8''%s", $ascii, rawurlencode($name));
    }
}
