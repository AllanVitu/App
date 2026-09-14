<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Des images fabriquées octet par octet, avec leurs métadonnées.
 *
 * Pas de fichiers d'exemple dans le dépôt : un binaire opaque ne dit pas ce
 * qu'il contient, et un test qui vérifie qu'une position GPS disparaît doit
 * montrer où elle était. Ici, chaque bloc est écrit en clair.
 *
 * Ces images sont VALIDES pour ce que l'API lit — signature, structure,
 * dimensions — sans prétendre se décoder dans un navigateur : le JPEG n'a pas
 * de vraies tables de Huffman. Le serveur ne décode jamais de pixels, et
 * c'est précisément le point.
 */
final class Images
{
    /**
     * @param array<string, string> $extraChunks type => données, glissés avant IDAT
     */
    public static function png(int $width = 4, int $height = 4, array $extraChunks = []): string
    {
        $row  = "\0" . str_repeat("\xE8\x3D\x5C", $width);
        $idat = (string) gzcompress(str_repeat($row, $height));

        $png = "\x89PNG\r\n\x1A\n" . self::pngChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0));

        foreach ($extraChunks as $type => $data) {
            $png .= self::pngChunk($type, $data);
        }

        return $png . self::pngChunk('IDAT', $idat) . self::pngChunk('IEND', '');
    }

    public static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    /**
     * @param list<array{0: int, 1: string}> $segments [marqueur, contenu], glissés après APP0
     */
    public static function jpeg(int $width = 4, int $height = 4, array $segments = []): string
    {
        $jpeg = "\xFF\xD8" . self::jpegSegment(0xE0, "JFIF\0\x01\x01\0\0\x01\0\x01\0\0");

        foreach ($segments as [$marker, $payload]) {
            $jpeg .= self::jpegSegment($marker, $payload);
        }

        // SOF0 : précision, hauteur, largeur, trois composantes.
        $jpeg .= self::jpegSegment(0xC0, pack('Cnn', 8, $height, $width) . "\x03\x01\x11\x00\x02\x11\x01\x03\x11\x01");
        // SOS, puis quelques octets de « données » — dont un 0xFF bourré.
        $jpeg .= self::jpegSegment(0xDA, "\x03\x01\x00\x02\x11\x03\x11\x00\x3F\x00");

        return $jpeg . "\x12\x34\xFF\x00\x56\xFF\xD9";
    }

    public static function jpegSegment(int $marker, string $payload): string
    {
        return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
    }

    /**
     * Un WebP étendu (VP8X) qui ANNONCE et PORTE un bloc EXIF, puis une image
     * VP8L réduite à son en-tête.
     */
    public static function webp(int $width = 4, int $height = 4, bool $withExif = true): string
    {
        $vp8x = chr($withExif ? 0x08 : 0x00) . "\0\0\0" . self::uint24le($width - 1) . self::uint24le($height - 1);

        $chunks = self::riffChunk('VP8X', $vp8x);

        if ($withExif) {
            $chunks .= self::riffChunk('EXIF', "Exif\0\0GPSLatitude 48.8566 N");
        }

        $chunks .= self::riffChunk('VP8L', "\x2F" . pack('V', ($width - 1) | (($height - 1) << 14)) . "\0\0\0");

        return 'RIFF' . pack('V', 4 + strlen($chunks)) . 'WEBP' . $chunks;
    }

    private static function riffChunk(string $fourcc, string $payload): string
    {
        return $fourcc . pack('V', strlen($payload)) . $payload . (strlen($payload) % 2 === 1 ? "\0" : '');
    }

    private static function uint24le(int $value): string
    {
        return chr($value & 0xFF) . chr(($value >> 8) & 0xFF) . chr(($value >> 16) & 0xFF);
    }
}
