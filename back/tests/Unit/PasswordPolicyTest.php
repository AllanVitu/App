<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\PasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ce qu'un mot de passe doit valoir : 80 bits, mesurés comme la CNIL les mesure.
 */
final class PasswordPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function acceptes(): iterable
    {
        yield 'douze caractères, quatre familles (l\'exemple de la CNIL)' => ['Password123!'];
        yield 'quatorze caractères, sans symbole'                         => ['Motdepasse2026'];
        yield 'une phrase de passe en minuscules'                         => ['cheval batterie agrafe'];
        yield 'des lettres accentuées'                                    => ['Élégance été 2026'];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function refuses(): iterable
    {
        // Ce que l'ancienne règle (8 caractères, une lettre, un chiffre) laissait passer.
        yield 'l\'ancienne règle suffisait'       => ['motdepasse1'];
        yield 'onze caractères, trois familles'   => ['Motdepasse1'];
        yield 'douze caractères, sans symbole'    => ['Motdepasse12'];
        yield 'long mais fait de quatre signes'   => [str_repeat('Ab1!', 5)];
        yield 'long mais d\'une seule lettre'     => [str_repeat('a', 40)];
    }

    #[Test]
    #[DataProvider('acceptes')]
    public function un_mot_de_passe_solide_passe(string $password): void
    {
        $this->assertNull(PasswordPolicy::problem($password));
    }

    #[Test]
    #[DataProvider('refuses')]
    public function un_mot_de_passe_previsible_est_refuse(string $password): void
    {
        $this->assertNotNull(PasswordPolicy::problem($password));
    }

    #[Test]
    public function l_entropie_est_la_longueur_fois_le_logarithme_de_l_alphabet(): void
    {
        // Minuscules, majuscules, chiffres, symboles : 26 + 26 + 10 + 33.
        $this->assertEqualsWithDelta(12 * log(95, 2), PasswordPolicy::bits('Password123!'), 0.0001);
        $this->assertEqualsWithDelta(11 * log(36, 2), PasswordPolicy::bits('motdepasse1'), 0.0001);
        $this->assertSame(0.0, PasswordPolicy::bits(''));
    }

    /**
     * bcrypt ne lit que les 72 premiers octets. Juger la suite, ce serait
     * accepter ce qui ne protège pas le compte : ici, 72 fois la lettre « a ».
     */
    #[Test]
    public function seuls_les_octets_que_bcrypt_lit_comptent(): void
    {
        $this->assertNotNull(PasswordPolicy::problem(str_repeat('a', 72) . 'Zx9!Qw8?'));
        $this->assertNull(PasswordPolicy::problem('Zx9!Qw8?' . str_repeat('a', 70)));
    }

    /**
     * Un octet nul coupe le mot de passe pour bcrypt, et PHP refuse de le
     * hacher : sans ce contrôle, la requête finissait en erreur 500.
     */
    #[Test]
    public function les_caracteres_de_controle_et_l_utf8_invalide_sont_refuses(): void
    {
        $this->assertNotNull(PasswordPolicy::problem("Password123!\0suite"));
        $this->assertNotNull(PasswordPolicy::problem("Password123!\tsuite"));
        $this->assertNotNull(PasswordPolicy::problem("Password123!\xFF\xFE"));
    }

    #[Test]
    public function au_dela_de_deux_cents_caracteres_c_est_refuse(): void
    {
        $this->assertNotNull(PasswordPolicy::problem('Zx9!Qw8?' . str_repeat('a', 193)));
        $this->assertNull(PasswordPolicy::problem('Zx9!Qw8?' . str_repeat('a', 192)));
    }
}
