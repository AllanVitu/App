<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SignedUrl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Les adresses signées : stables assez longtemps pour le cache, jamais assez
 * pour qu'un lien qui fuit vaille un accès durable.
 */
final class SignedUrlTest extends TestCase
{
    private const FICHIER = '550e8400-e29b-41d4-a716-446655440000';
    private const AUTRE   = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

    #[Test]
    public function l_adresse_ne_change_pas_pendant_l_heure(): void
    {
        // Sans quoi chaque réponse d'API ferait retélécharger chaque avatar.
        $this->assertSame(SignedUrl::forFile(self::FICHIER, 36_000), SignedUrl::forFile(self::FICHIER, 39_599));
        $this->assertNotSame(SignedUrl::forFile(self::FICHIER, 39_599), SignedUrl::forFile(self::FICHIER, 39_600));
    }

    #[Test]
    public function elle_vaut_entre_une_et_deux_heures(): void
    {
        foreach ([36_000, 37_800, 39_599] as $maintenant) {
            $delai = SignedUrl::expiryFor($maintenant) - $maintenant;

            $this->assertGreaterThan(3600, $delai);
            $this->assertLessThanOrEqual(7200, $delai);
        }
    }

    #[Test]
    public function elle_ouvre_le_fichier_qu_elle_designe_jusqu_a_son_echeance(): void
    {
        [$expires, $signature] = $this->parametres(SignedUrl::forFile(self::FICHIER, 36_000));

        $this->assertTrue(SignedUrl::isValid(self::FICHIER, $expires, $signature, 36_000));
        $this->assertTrue(SignedUrl::isValid(self::FICHIER, $expires, $signature, (int) $expires - 1));
        $this->assertFalse(SignedUrl::isValid(self::FICHIER, $expires, $signature, (int) $expires));
    }

    #[Test]
    public function elle_n_ouvre_rien_d_autre(): void
    {
        [$expires, $signature] = $this->parametres(SignedUrl::forFile(self::FICHIER, 36_000));

        $this->assertFalse(SignedUrl::isValid(self::AUTRE, $expires, $signature, 36_000), 'un autre fichier');
        $this->assertFalse(SignedUrl::isValid(self::FICHIER, (string) ((int) $expires + 86_400), $signature, 36_000), 'une échéance repoussée');
        $this->assertFalse(SignedUrl::isValid(self::FICHIER, $expires, $signature . 'x', 36_000), 'une signature altérée');
        $this->assertFalse(SignedUrl::isValid(self::FICHIER, '1e10', $signature, 36_000), 'une échéance qui n\'est pas un entier');
        $this->assertFalse(SignedUrl::isValid(self::FICHIER, '', '', 36_000), 'rien du tout');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parametres(string $adresse): array
    {
        parse_str((string) parse_url($adresse, PHP_URL_QUERY), $query);

        return [(string) $query['expires'], (string) $query['signature']];
    }
}
