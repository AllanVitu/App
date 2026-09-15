<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MetadataStripper;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\Images;

/**
 * Ce qu'une image garde, et ce qu'elle perd, en entrant dans l'application.
 *
 * Trois exigences, chacune avec son test :
 *
 *   — les métadonnées qui désignent une personne ou un lieu disparaissent ;
 *   — ce qui fait l'image reste : dimensions, profil de couleur, pixels ;
 *   — ce qui ne se lit pas est refusé, et ce qui suit la fin est coupé.
 */
final class MetadataStripperTest extends TestCase
{
    private MetadataStripper $stripper;

    protected function setUp(): void
    {
        $this->stripper = new MetadataStripper();
    }

    #[Test]
    public function un_jpeg_perd_exif_xmp_iptc_et_commentaires_mais_garde_ses_couleurs(): void
    {
        $photo = Images::jpeg(320, 200, [
            [0xE1, "Exif\0\0GPSLatitude 48.8566 N"],
            [0xE1, "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta>Créé par Alice</x:xmpmeta>"],
            [0xED, "Photoshop 3.0\x008BIM IPTC Alice Martin"],
            [0xFE, 'Photographié chez Alice'],
            [0xE2, "ICC_PROFILE\0\x01\x01profil-couleur"],
            [0xEE, "Adobe\0\x64\0\0\0\0\x01"],
        ]);

        $nettoyee = $this->stripper->strip($photo . 'OCTETS APRÈS LA FIN', 'image/jpeg');

        foreach (['GPSLatitude', 'xmpmeta', '8BIM', 'Photographié', 'APRÈS LA FIN'] as $trace) {
            $this->assertStringNotContainsString($trace, $nettoyee);
        }

        foreach (['JFIF', 'ICC_PROFILE', 'Adobe'] as $garde) {
            $this->assertStringContainsString($garde, $nettoyee);
        }

        $this->assertStringEndsWith("\xFF\xD9", $nettoyee);
        $this->assertSame([320, 200], $this->dimensions($nettoyee));
    }

    /**
     * Le retrait se fait SANS RECOMPRESSION : une image qui n'a rien à perdre
     * ressort à l'octet près.
     */
    #[Test]
    public function un_jpeg_sans_metadonnees_ressort_a_l_octet_pres(): void
    {
        $photo = Images::jpeg(16, 9);

        $this->assertSame($photo, $this->stripper->strip($photo, 'image/jpeg'));
    }

    #[Test]
    public function un_png_perd_ses_textes_sa_date_et_son_exif_et_ce_qui_suit_iend(): void
    {
        $image = Images::png(12, 8, [
            'iCCP' => "profil\0\0" . gzcompress('icc'),
            'tEXt' => "Author\0Alice Martin",
            'eXIf' => "MM\0*GPSLatitude",
            'tIME' => "\x07\xEA\x09\x0E\x0C\x00\x00",
        ]);

        $nettoyee = $this->stripper->strip($image . '<?php echo "second visage"; ?>', 'image/png');

        foreach (['Alice Martin', 'GPSLatitude', 'tIME', 'second visage'] as $trace) {
            $this->assertStringNotContainsString($trace, $nettoyee);
        }

        $this->assertStringContainsString('iCCP', $nettoyee);
        $this->assertSame([12, 8], $this->dimensions($nettoyee));
    }

    #[Test]
    public function un_webp_perd_son_bloc_exif_et_l_annonce_qui_allait_avec(): void
    {
        $nettoyee = $this->stripper->strip(Images::webp(40, 20), 'image/webp');

        $this->assertStringNotContainsString('GPSLatitude', $nettoyee);
        $this->assertStringNotContainsString('EXIF', $nettoyee);

        // Le drapeau EXIF du bloc VP8X est éteint : un lecteur qui le verrait
        // allumé chercherait un bloc qui n'existe plus.
        $this->assertSame(0, ord($nettoyee[20]) & 0x08);

        // La taille du conteneur est recalculée.
        $this->assertSame(strlen($nettoyee) - 8, unpack('V', substr($nettoyee, 4, 4))[1]);

        $this->assertSame([40, 20], $this->dimensions($nettoyee));
    }

    #[Test]
    public function un_gif_passe_tel_quel(): void
    {
        $gif = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF!\xF9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";

        $this->assertSame($gif, $this->stripper->strip($gif, 'image/gif'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function illisibles(): iterable
    {
        yield 'JPEG tronqué' => [substr(Images::jpeg(), 0, 30), 'image/jpeg'];
        yield 'JPEG sans début' => ["\x00\x01" . substr(Images::jpeg(), 2), 'image/jpeg'];
        yield 'PNG à bloc démesuré' => ["\x89PNG\r\n\x1A\n" . pack('N', 999_999) . 'IHDR' . str_repeat("\0", 20), 'image/png'];
        yield 'PNG sans fin' => [substr(Images::png(), 0, -12), 'image/png'];
        yield 'WebP à taille mensongère' => ['RIFF' . pack('V', 90_000) . 'WEBPVP8X', 'image/webp'];
        yield 'type non pris en charge' => ['<svg xmlns="http://www.w3.org/2000/svg"/>', 'image/svg+xml'];
    }

    #[Test]
    #[DataProvider('illisibles')]
    public function ce_qui_ne_se_lit_pas_est_refuse_plutot_que_transmis(string $octets, string $type): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->stripper->strip($octets, $type);
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function dimensions(string $octets): ?array
    {
        $taille = getimagesizefromstring($octets);

        return $taille === false ? null : [$taille[0], $taille[1]];
    }
}
