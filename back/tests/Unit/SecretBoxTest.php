<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SecretBox;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Ce que SecretBox promet : le scellé ne contient pas le clair, il ne s'ouvre
 * qu'avec la bonne clé et pour le bon usage, et une altération est refusée.
 */
final class SecretBoxTest extends TestCase
{
    #[Test]
    public function un_scelle_s_ouvre_et_ne_contient_pas_le_clair(): void
    {
        $scelle = SecretBox::seal('JBSWY3DPEHPK3PXP', 'two-factor');

        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $scelle);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', (string) base64_decode($scelle, true));
        $this->assertSame('JBSWY3DPEHPK3PXP', SecretBox::open($scelle, 'two-factor'));
    }

    #[Test]
    public function deux_scelles_du_meme_secret_different(): void
    {
        // Un nonce neuf à chaque fois : deux comptes au même secret ne se
        // reconnaissent pas en comparant leurs scellés.
        $this->assertNotSame(SecretBox::seal('même secret', 'two-factor'), SecretBox::seal('même secret', 'two-factor'));
    }

    #[Test]
    public function un_scelle_ne_s_ouvre_pas_pour_un_autre_usage(): void
    {
        $scelle = SecretBox::seal('secret', 'two-factor');

        $this->expectException(RuntimeException::class);

        SecretBox::open($scelle, 'autre-usage');
    }

    #[Test]
    public function un_scelle_altere_est_refuse(): void
    {
        $brut          = (string) base64_decode(SecretBox::seal('secret', 'two-factor'), true);
        $brut[-1]      = chr(ord($brut[-1]) ^ 0x01);
        $altere        = base64_encode($brut);

        $this->expectException(RuntimeException::class);

        SecretBox::open($altere, 'two-factor');
    }
}
