<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Core\HttpException;
use App\Core\UploadedFile;
use App\Models\StoredFileRepository;
use finfo;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Réception, rangement et effacement des fichiers téléversés.
 *
 * Un fichier ne passe dans le volume qu'après cinq contrôles, dans cet ordre,
 * et c'est l'ordre du moins coûteux au plus coûteux :
 *
 *   1. la réception elle-même (interrompue, trop grosse pour PHP) ;
 *   2. la taille, avant de lire quoi que ce soit du contenu ;
 *   3. le TYPE LU DANS LES OCTETS — jamais l'extension, jamais ce
 *      qu'annonce le navigateur ;
 *   4. la structure, parcourue pour en retirer les métadonnées
 *      (cf. MetadataStripper), et refusée si elle ne se lit pas ;
 *   5. les dimensions, contre les images qui pèsent quelques kilo-octets et
 *      occupent des gigaoctets une fois décodées.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  AUCUN SVG, AUCUN PDF                                                   │
 * │                                                                         │
 * │  Un SVG est un document qui peut porter du script : servi depuis notre  │
 * │  origine, il deviendrait une faille de script stockée. Un PDF garde le  │
 * │  nom de son auteur et de son logiciel dans ses propriétés, qu'on ne     │
 * │  sait pas retirer sans le réécrire. Les deux attendront une raison      │
 * │  plus forte que la commodité.                                           │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
final class FileStorage
{
    /** @var array<string, array{max: int, types: list<string>}> */
    private const RULES = [
        // Recadré et réencodé par le navigateur avant l'envoi : deux
        // mégaoctets laissent une marge large.
        'avatar' => ['max' => 2 * 1024 * 1024, 'types' => ['image/png', 'image/jpeg', 'image/webp']],
        'design' => ['max' => 10 * 1024 * 1024, 'types' => ['image/png', 'image/jpeg', 'image/webp', 'image/gif']],
    ];

    /** @var array<int, string> Ce que getimagesize() reconnaît, rapporté au type lu. */
    private const IMAGE_TYPES = [
        IMAGETYPE_PNG  => 'image/png',
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_WEBP => 'image/webp',
        IMAGETYPE_GIF  => 'image/gif',
    ];

    private const LABELS = ['image/png' => 'PNG', 'image/jpeg' => 'JPEG', 'image/webp' => 'WebP', 'image/gif' => 'GIF'];

    /**
     * Au-delà, une image légère sur le disque peut réclamer des gigaoctets de
     * mémoire au navigateur qui la décode : 12 000 × 12 000 pixels en RVBA,
     * c'est 576 Mo.
     */
    private const MAX_SIDE   = 12_000;
    private const MAX_PIXELS = 50_000_000;

    private readonly string $root;
    private readonly int $quota;
    private readonly StoredFileRepository $files;

    public function __construct(?string $root = null, ?int $quotaBytes = null)
    {
        $this->root  = rtrim($root ?? (Env::get('STORAGE_PATH', '/var/www/storage') ?? '/var/www/storage'), '/');
        $this->quota = $quotaBytes ?? Env::int('STORAGE_QUOTA_BYTES', 1024 * 1024 * 1024);
        $this->files = new StoredFileRepository();
    }

    /**
     * Contrôle, nettoie et range un fichier.
     *
     * @param string $field Le champ du formulaire, pour que l'erreur s'affiche au bon endroit
     * @return array<string, mixed> La description enregistrée
     * @throws HttpException 422 pour tout refus lié au fichier envoyé
     */
    public function store(
        UploadedFile $upload,
        string $purpose,
        ?string $organizationId,
        ?string $ownerUserId,
        ?string $actorId,
        string $field = 'file',
    ): array {
        $rules = self::RULES[$purpose] ?? throw new InvalidArgumentException("Usage de fichier inconnu : {$purpose}");

        $this->assertReceived($upload, $rules['max'], $field);

        $bytes = $upload->contents();

        if ($bytes === '') {
            throw HttpException::validation([$field => 'Le fichier est vide.']);
        }

        if (strlen($bytes) > $rules['max']) {
            throw HttpException::validation([$field => sprintf('Le fichier dépasse %s.', $this->humanSize($rules['max']))]);
        }

        $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (!is_string($type) || !in_array($type, $rules['types'], true)) {
            throw HttpException::validation([$field => sprintf(
                'Format refusé. Formats acceptés : %s.',
                implode(', ', array_map(static fn (string $t): string => self::LABELS[$t], $rules['types'])),
            )]);
        }

        try {
            $bytes = (new MetadataStripper())->strip($bytes, $type);
        } catch (InvalidArgumentException) {
            throw HttpException::validation([$field => 'Ce fichier est endommagé, ou n\'est pas une image valide.']);
        }

        $size = getimagesizefromstring($bytes);

        if ($size === false || (self::IMAGE_TYPES[$size[2]] ?? null) !== $type) {
            throw HttpException::validation([$field => 'Ce fichier est endommagé, ou n\'est pas une image valide.']);
        }

        [$width, $height] = [$size[0], $size[1]];

        if ($width < 1 || $height < 1 || $width > self::MAX_SIDE || $height > self::MAX_SIDE
            || $width * $height > self::MAX_PIXELS
        ) {
            throw HttpException::validation([
                $field => 'Image trop grande : 12 000 pixels de côté et 50 mégapixels au plus.',
            ]);
        }

        // Le quota se lit avant l'écriture. Deux envois simultanés peuvent le
        // dépasser d'un fichier : c'est une limite de consommation, pas une
        // frontière de sécurité, et un verrou par espace ne vaudrait pas ce
        // qu'il coûterait à chaque téléversement.
        if ($organizationId !== null
            && $this->files->organizationUsage($organizationId) + strlen($bytes) > $this->quota
        ) {
            throw HttpException::validation([$field => sprintf(
                'Cet espace a atteint sa limite de stockage (%s).',
                $this->humanSize($this->quota),
            )]);
        }

        $key = $this->newKey();
        $this->write($key, $bytes);

        try {
            return $this->files->create([
                'purpose'         => $purpose,
                'organization_id' => $organizationId,
                'owner_user_id'   => $ownerUserId,
                'storage_key'     => $key,
                'media_type'      => $type,
                'byte_size'       => strlen($bytes),
                'sha256'          => hash('sha256', $bytes),
                'width'           => $width,
                'height'          => $height,
                'original_name'   => $this->cleanName($upload->clientName),
                'created_by'      => $actorId,
            ]);
        } catch (Throwable $e) {
            // Rien en base : les octets ne doivent pas rester sans description,
            // puisque plus rien ne les désignerait pour la purge.
            @unlink($this->pathFor($key));

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $fileId): ?array
    {
        return $this->files->find($fileId);
    }

    /**
     * Supprime la DESCRIPTION ; les octets suivent au passage du worker.
     *
     * Le chemin est le même que pour une suppression en cascade — une pierre
     * tombale posée par la base — et il n'y en a qu'un. Une tâche immédiate
     * est déposée : une photo retirée n'a pas à attendre l'heure suivante.
     */
    public function delete(string $fileId): void
    {
        if ($this->files->delete($fileId)) {
            Queue::push('storage.purge');
        }
    }

    /**
     * Efface les octets des fichiers supprimés.
     *
     * L'ordre compte : les octets d'abord, la pierre tombale ensuite. Un
     * worker tué entre les deux laisse une pierre tombale sans fichier, que
     * le passage suivant efface sans rien trouver. L'ordre inverse laisserait
     * un fichier que plus rien ne désigne.
     *
     * @return int nombre de fichiers traités
     */
    public function purge(int $batch = 500): int
    {
        $keys = $this->files->tombstones($batch);

        foreach ($keys as $key) {
            $path = $this->pathFor($key);

            if (is_file($path) && !@unlink($path)) {
                throw new RuntimeException("Effacement impossible : {$key}");
            }
        }

        $this->files->forget($keys);

        return count($keys);
    }

    /**
     * Chemin sur le disque d'une clé de stockage.
     *
     * La clé vient de la base, pas du client. Le chemin se construit malgré
     * tout comme si elle venait d'ailleurs : une ligne modifiée à la main ne
     * doit pas pouvoir faire lire ou effacer autre chose qu'un fichier rangé.
     */
    public function pathFor(string $key): string
    {
        if (preg_match('#^[a-f0-9]{2}/[a-f0-9]{2}/[a-f0-9]{32}$#', $key) !== 1) {
            throw new RuntimeException('Clé de stockage invalide.');
        }

        return $this->root . '/' . $key;
    }

    // -----------------------------------------------------------------------

    private function assertReceived(UploadedFile $upload, int $max, string $field): void
    {
        match ($upload->error) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => throw HttpException::validation([
                $field => sprintf('Le fichier dépasse %s.', $this->humanSize($max)),
            ]),
            UPLOAD_ERR_PARTIAL => throw HttpException::validation([$field => 'L\'envoi a été interrompu. Réessayez.']),
            UPLOAD_ERR_NO_FILE => throw HttpException::validation([$field => 'Aucun fichier reçu.']),
            // Dossier temporaire absent, disque plein : une panne du serveur,
            // pas une erreur de saisie — elle doit arriver en supervision.
            default => throw new RuntimeException("Réception du fichier impossible (code {$upload->error})."),
        };
    }

    private function write(string $key, string $bytes): void
    {
        $path      = $this->pathFor($key);
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new RuntimeException('Dossier de stockage impossible à créer.');
        }

        // Écrit à côté, puis renommé : un lecteur ne voit jamais un fichier à
        // moitié écrit.
        $temporary = $path . '.part';

        if (@file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || !@rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException('Écriture du fichier impossible.');
        }

        @chmod($path, 0o640);
    }

    /** Trois niveaux, dont deux de répertoires : aucun dossier ne dépasse 256 entrées. */
    private function newKey(): string
    {
        $hex = bin2hex(random_bytes(16));

        return substr($hex, 0, 2) . '/' . substr($hex, 2, 2) . '/' . $hex;
    }

    /**
     * Le nom proposé au téléchargement : dernier segment, sans caractère de
     * contrôle, 160 caractères au plus.
     */
    private function cleanName(string $name): ?string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim(mb_substr($name, 0, 160));

        return $name === '' || $name === '.' || $name === '..' ? null : $name;
    }

    private function humanSize(int $bytes): string
    {
        return $bytes >= 1024 ** 3
            ? round($bytes / 1024 ** 3, 1) . ' Go'
            : round($bytes / 1024 ** 2, 1) . ' Mo';
    }
}
