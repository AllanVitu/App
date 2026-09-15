<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Config\Env;
use App\Services\Mailer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * Le transport « fichier » : la boîte d'envoi de l'application de bureau.
 *
 * Sur le poste, aucun serveur SMTP ne relaie un lien de confirmation. Le
 * message est déposé tel qu'il serait parti — mêmes en-têtes, même corps,
 * mêmes protections — et l'application l'affiche. Ce qui est vérifié ici,
 * c'est que ce détour ne change rien au message, ni aux garde-fous.
 */
final class MailerTest extends TestCase
{
    private string $boite;

    protected function setUp(): void
    {
        $this->boite = sys_get_temp_dir() . '/relais-boite-' . bin2hex(random_bytes(4));

        self::env('MAIL_TRANSPORT', 'fichier');
        self::env('MAIL_OUTBOX_PATH', $this->boite);
    }

    protected function tearDown(): void
    {
        self::env('MAIL_TRANSPORT', null);
        self::env('MAIL_OUTBOX_PATH', null);

        foreach ($this->fichiers() as $fichier) {
            unlink($fichier);
        }

        if (is_dir($this->boite)) {
            rmdir($this->boite);
        }
    }

    #[Test]
    public function le_message_est_depose_tel_qu_il_serait_parti(): void
    {
        $texte = "Bonjour Marie,\n.\nConfirmez : http://127.0.0.1:41234/verifier-email?token=abc";

        (new Mailer())->send('marie@exemple.fr', 'Marie Dupont', 'Confirmez votre adresse', '<p>Bonjour</p>', $texte);

        // Un seul fichier, et plus aucun fichier temporaire à côté.
        $fichiers = $this->fichiers();
        $this->assertCount(1, $fichiers);
        $this->assertMatchesRegularExpression('/^\d{8}T\d{6}Z-[a-f0-9]{12}\.eml$/', basename($fichiers[0]));

        $eml = (string) file_get_contents($fichiers[0]);

        $this->assertStringContainsString("\r\nTo: Marie Dupont <marie@exemple.fr>\r\n", $eml);
        $this->assertStringContainsString("\r\nSubject: Confirmez votre adresse\r\n", $eml);

        // Le point seul sur sa ligne est une affaire de protocole SMTP : dans
        // le fichier, le texte se relit à l'octet près.
        $this->assertSame(1, preg_match(
            '#Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n(.+?)\r\n--#s',
            $eml,
            $partie,
        ));
        $this->assertSame($texte, base64_decode(str_replace("\r\n", '', $partie[1]), true));
    }

    #[Test]
    public function un_retour_a_la_ligne_dans_le_sujet_n_ajoute_aucun_en_tete(): void
    {
        (new Mailer())->send('marie@exemple.fr', 'Marie', "Bonjour\r\nBcc: espion@exemple.fr", '<p>x</p>', 'x');

        $fichiers = $this->fichiers();
        $this->assertCount(1, $fichiers);

        $this->assertStringNotContainsString("\r\nBcc:", (string) file_get_contents($fichiers[0]));
    }

    #[Test]
    public function une_adresse_invalide_ne_depose_rien(): void
    {
        try {
            (new Mailer())->send("marie@exemple.fr\r\nBcc: espion@exemple.fr>", 'Marie', 'Sujet', '<p>x</p>', 'x');
            $this->fail('Une adresse invalide aurait dû être refusée.');
        } catch (RuntimeException) {
            $this->assertSame([], $this->fichiers());
        }
    }

    #[Test]
    public function sans_boite_d_envoi_designee_l_envoi_echoue_franchement(): void
    {
        self::env('MAIL_OUTBOX_PATH', null);

        $this->expectException(RuntimeException::class);

        (new Mailer())->send('marie@exemple.fr', 'Marie', 'Sujet', '<p>x</p>', 'x');
    }

    #[Test]
    public function un_transport_inconnu_est_refuse_plutot_que_remplace(): void
    {
        self::env('MAIL_TRANSPORT', 'pigeon');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Transport');

        (new Mailer())->send('marie@exemple.fr', 'Marie', 'Sujet', '<p>x</p>', 'x');
    }

    /**
     * Tout ce que contient la boîte, fichiers temporaires cachés compris.
     *
     * @return list<string>
     */
    private function fichiers(): array
    {
        if (!is_dir($this->boite)) {
            return [];
        }

        $noms = array_diff(scandir($this->boite) ?: [], ['.', '..']);

        return array_values(array_map(fn (string $nom): string => $this->boite . '/' . $nom, $noms));
    }

    /**
     * Env met ses lectures en cache pour toute la durée du processus : la
     * valeur posée ici doit remplacer celle qu'un test précédent aurait lue.
     */
    private static function env(string $cle, ?string $valeur): void
    {
        putenv($valeur === null ? $cle : "{$cle}={$valeur}");

        $cache   = new ReflectionProperty(Env::class, 'cache');
        $valeurs = (array) $cache->getValue();
        unset($valeurs[$cle]);
        $cache->setValue(null, $valeurs);
    }
}
