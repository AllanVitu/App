<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Retire d'une image ce qui raconte autre chose que l'image.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  UNE PHOTO DE TÉLÉPHONE DIT OÙ ELLE A ÉTÉ PRISE                          │
 * │                                                                         │
 * │  Les métadonnées EXIF portent la position GPS au mètre près, la date à  │
 * │  la seconde, le modèle et le numéro de série de l'appareil. Un avatar   │
 * │  pris chez soi publierait l'adresse de son domicile à toute l'équipe ;  │
 * │  une maquette exportée, le nom de la personne qui l'a produite.         │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * SANS RECOMPRESSION. Réencoder l'image (GD, Imagick) retirerait tout aussi
 * sûrement les métadonnées, mais dégraderait un JPEG à chaque passage et
 * ajouterait une extension — donc une surface d'attaque, les décodeurs
 * d'images étant un grand classique des failles. Ici la structure du fichier
 * est parcourue bloc par bloc, et seuls les blocs de métadonnées sont omis :
 * les pixels ressortent octet pour octet.
 *
 * Ce qui est GARDÉ, à dessein : le profil de couleur, sans lequel les teintes
 * changent, et pour le JPEG le segment Adobe, sans lequel certains JPEG CMJN
 * s'affichent en négatif. Ce qui est PERDU, et assumé : l'orientation EXIF.
 * Une photo prise téléphone tourné peut s'afficher couchée — l'avatar, lui,
 * est redressé par le navigateur avant l'envoi.
 *
 * Ce que le parcours ne comprend pas est REFUSÉ, pas transmis tel quel : un
 * fichier tronqué ou bricolé ne se range pas « au cas où ». Et ce qui suit la
 * fin déclarée d'une image est coupé : un fichier qui continue au-delà a deux
 * visages, et le second n'a rien d'une image.
 */
final class MetadataStripper
{
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1A\n";

    /** Blocs PNG qui décrivent autre chose que les pixels. */
    private const PNG_METADATA = ['tEXt', 'zTXt', 'iTXt', 'eXIf', 'tIME'];

    /**
     * @throws InvalidArgumentException si la structure du fichier est illisible
     */
    public function strip(string $bytes, string $mediaType): string
    {
        return match ($mediaType) {
            'image/jpeg' => $this->jpeg($bytes),
            'image/png'  => $this->png($bytes),
            'image/webp' => $this->webp($bytes),
            // Le GIF ne porte ni EXIF ni XMP ; ses blocs de commentaire, rares,
            // ne contiennent ni position ni appareil.
            'image/gif'  => $bytes,
            default      => throw new InvalidArgumentException("Type non pris en charge : {$mediaType}"),
        };
    }

    // -----------------------------------------------------------------------
    //  JPEG
    // -----------------------------------------------------------------------

    /**
     * Une suite de segments « FF xx » suivis de leur longueur, jusqu'au début
     * des données compressées (SOS). Ce qui suit est l'image elle-même, et se
     * recopie jusqu'au dernier marqueur de fin.
     */
    private function jpeg(string $bytes): string
    {
        if (!str_starts_with($bytes, "\xFF\xD8")) {
            throw new InvalidArgumentException('JPEG sans marqueur de début.');
        }

        $out    = "\xFF\xD8";
        $offset = 2;
        $length = strlen($bytes);

        while ($offset < $length) {
            if ($bytes[$offset] !== "\xFF") {
                throw new InvalidArgumentException('JPEG : marqueur attendu.');
            }

            // Des octets de remplissage 0xFF peuvent précéder un marqueur.
            while ($offset < $length && $bytes[$offset] === "\xFF") {
                $offset++;
            }

            if ($offset >= $length) {
                throw new InvalidArgumentException('JPEG tronqué.');
            }

            $marker = ord($bytes[$offset]);
            $offset++;

            if ($marker === 0xD9) {
                return $out . "\xFF\xD9";
            }

            // Marqueurs sans longueur : redémarrages et TEM.
            if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                $out .= "\xFF" . chr($marker);

                continue;
            }

            if ($offset + 2 > $length) {
                throw new InvalidArgumentException('JPEG tronqué.');
            }

            // La longueur compte ses deux propres octets.
            $segmentLength = $this->uint16be($bytes, $offset);

            if ($segmentLength < 2 || $offset + $segmentLength > $length) {
                throw new InvalidArgumentException('JPEG : longueur de segment incohérente.');
            }

            $segment = substr($bytes, $offset, $segmentLength);
            $offset += $segmentLength;

            if ($marker === 0xDA) {
                $rest = substr($bytes, $offset);
                $end  = strrpos($rest, "\xFF\xD9");

                if ($end === false) {
                    throw new InvalidArgumentException('JPEG sans marqueur de fin.');
                }

                return $out . "\xFF\xDA" . $segment . substr($rest, 0, $end + 2);
            }

            if ($this->keepJpegSegment($marker, substr($segment, 2))) {
                $out .= "\xFF" . chr($marker) . $segment;
            }
        }

        throw new InvalidArgumentException('JPEG sans données d\'image.');
    }

    private function keepJpegSegment(int $marker, string $payload): bool
    {
        return match (true) {
            // APP0 : la déclaration du format, JFIF ou JFXX.
            $marker === 0xE0 => str_starts_with($payload, "JFIF\0") || str_starts_with($payload, "JFXX\0"),
            // APP2 : le profil de couleur ICC, et lui seul. Les autres APP2
            // (FlashPix) portent des métadonnées.
            $marker === 0xE2 => str_starts_with($payload, "ICC_PROFILE\0"),
            // APP14 : Adobe, qui dit comment lire les couleurs.
            $marker === 0xEE => str_starts_with($payload, 'Adobe'),
            // Tous les autres APPn — EXIF, XMP, IPTC, Photoshop — et COM.
            ($marker >= 0xE0 && $marker <= 0xEF) || $marker === 0xFE => false,
            // Le reste décrit l'image : tables de quantification, dimensions.
            default => true,
        };
    }

    // -----------------------------------------------------------------------
    //  PNG
    // -----------------------------------------------------------------------

    /**
     * Une signature, puis des blocs « longueur, type, données, CRC » jusqu'à
     * IEND. Les blocs omis n'ont aucune incidence sur les CRC des autres.
     */
    private function png(string $bytes): string
    {
        if (!str_starts_with($bytes, self::PNG_SIGNATURE)) {
            throw new InvalidArgumentException('PNG sans signature.');
        }

        $out    = self::PNG_SIGNATURE;
        $offset = strlen(self::PNG_SIGNATURE);
        $length = strlen($bytes);

        while ($offset + 12 <= $length) {
            $dataLength  = $this->uint32be($bytes, $offset);
            $type        = substr($bytes, $offset + 4, 4);
            $chunkLength = 12 + $dataLength;

            if (preg_match('/^[A-Za-z]{4}$/', $type) !== 1 || $offset + $chunkLength > $length) {
                throw new InvalidArgumentException('PNG : bloc incohérent.');
            }

            if (!in_array($type, self::PNG_METADATA, true)) {
                $out .= substr($bytes, $offset, $chunkLength);
            }

            $offset += $chunkLength;

            if ($type === 'IEND') {
                return $out;
            }
        }

        throw new InvalidArgumentException('PNG sans bloc de fin.');
    }

    // -----------------------------------------------------------------------
    //  WebP
    // -----------------------------------------------------------------------

    /**
     * Un conteneur RIFF : « WEBP », puis des blocs « code, taille, données »
     * alignés sur deux octets. La taille totale est recalculée, et les
     * drapeaux du bloc VP8X qui annonçaient EXIF et XMP sont éteints avec eux.
     */
    private function webp(string $bytes): string
    {
        if (strlen($bytes) < 12 || !str_starts_with($bytes, 'RIFF') || substr($bytes, 8, 4) !== 'WEBP') {
            throw new InvalidArgumentException('WebP sans en-tête RIFF.');
        }

        $declared = $this->uint32le($bytes, 4) + 8;

        if ($declared < 12 || $declared > strlen($bytes)) {
            throw new InvalidArgumentException('WebP tronqué.');
        }

        $chunks = '';
        $offset = 12;

        while ($offset + 8 <= $declared) {
            $fourcc = substr($bytes, $offset, 4);
            $size   = $this->uint32le($bytes, $offset + 4);

            if ($offset + 8 + $size > $declared) {
                throw new InvalidArgumentException('WebP : bloc incohérent.');
            }

            $payload = substr($bytes, $offset + 8, $size);

            if ($fourcc === 'VP8X' && $size >= 10) {
                $payload[0] = chr(ord($payload[0]) & ~0x0C);
            }

            if ($fourcc !== 'EXIF' && $fourcc !== 'XMP ') {
                $chunks .= $fourcc . pack('V', $size) . $payload . ($size % 2 === 1 ? "\0" : '');
            }

            $offset += 8 + $size + ($size % 2);
        }

        if ($chunks === '') {
            throw new InvalidArgumentException('WebP sans image.');
        }

        return 'RIFF' . pack('V', 4 + strlen($chunks)) . 'WEBP' . $chunks;
    }

    // -----------------------------------------------------------------------

    private function uint16be(string $bytes, int $offset): int
    {
        return (ord($bytes[$offset]) << 8) | ord($bytes[$offset + 1]);
    }

    private function uint32be(string $bytes, int $offset): int
    {
        return (ord($bytes[$offset]) << 24) | (ord($bytes[$offset + 1]) << 16)
            | (ord($bytes[$offset + 2]) << 8) | ord($bytes[$offset + 3]);
    }

    private function uint32le(string $bytes, int $offset): int
    {
        return ord($bytes[$offset]) | (ord($bytes[$offset + 1]) << 8)
            | (ord($bytes[$offset + 2]) << 16) | (ord($bytes[$offset + 3]) << 24);
    }
}
