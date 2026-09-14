<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\UnsafeUrl;
use App\Services\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ce qu'une sonde a le droit d'appeler — sans un seul appel réseau.
 */
final class UrlGuardTest extends TestCase
{
    /**
     * Un DNS de laboratoire : chaque nom répond ce qu'on lui a appris.
     */
    private function guard(): UrlGuard
    {
        $zone = [
            'relais.example'     => ['93.184.216.34'],
            'double.example'     => ['93.184.216.34', '10.0.0.7'],
            'metadonnees.example' => ['169.254.169.254'],
            'fantome.example'    => [],
        ];

        return new UrlGuard(static fn (string $host): array => $zone[$host] ?? []);
    }

    #[Test]
    public function une_adresse_publique_passe_et_garde_son_adresse_verifiee(): void
    {
        $cible = $this->guard()->check('https://relais.example/sante?format=json');

        $this->assertSame('relais.example', $cible['host']);
        $this->assertSame(443, $cible['port']);
        $this->assertSame('93.184.216.34', $cible['ip']);
        $this->assertFalse($cible['literal']);
    }

    #[Test]
    public function le_port_explicite_et_le_http_sont_conserves(): void
    {
        $cible = $this->guard()->check('http://relais.example:8080/');

        $this->assertSame('http', $cible['scheme']);
        $this->assertSame(8080, $cible['port']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function refusees(): iterable
    {
        yield 'boucle locale'               => ['http://127.0.0.1/'];
        yield 'localhost par son nom'       => ['http://localhost:9000/'];
        yield 'sous-domaine de localhost'   => ['http://api.localhost/'];
        yield 'réseau privé'                => ['http://10.1.2.3/'];
        yield 'réseau privé 172'            => ['http://172.18.0.5/'];
        yield 'métadonnées du cloud'        => ['http://169.254.169.254/latest/meta-data/'];
        yield 'métadonnées derrière un nom' => ['http://metadonnees.example/'];
        yield 'CGNAT'                       => ['http://100.64.1.1/'];
        yield 'IPv6 locale'                 => ['http://[::1]/'];
        yield 'IPv4 déguisée en IPv6'       => ['http://[::ffff:127.0.0.1]/'];
        yield 'un nom, une adresse privée'  => ['http://double.example/'];
        yield 'nom interne'                 => ['http://db.internal/'];
        yield 'nom qui ne résout pas'       => ['http://fantome.example/'];
        yield 'autre protocole'             => ['ftp://relais.example/'];
        yield 'fichier local'               => ['file:///etc/passwd'];
        yield 'gopher'                      => ['gopher://relais.example/'];
        yield 'identifiants dans l’URL'     => ['https://admin:secret@relais.example/'];
        yield 'sans protocole'              => ['relais.example/sante'];
        yield 'retour à la ligne injecté'   => ["https://relais.example/\r\nHost: db"];
    }

    #[Test]
    #[DataProvider('refusees')]
    public function une_adresse_interne_ou_douteuse_est_refusee(string $url): void
    {
        $this->expectException(UnsafeUrl::class);

        $this->guard()->check($url);
    }

    #[Test]
    public function le_refus_ne_dit_pas_quel_reseau_a_ete_touche(): void
    {
        $messages = [];

        foreach (['http://127.0.0.1/', 'http://10.1.2.3/', 'http://169.254.169.254/'] as $url) {
            try {
                $this->guard()->check($url);
            } catch (UnsafeUrl $refus) {
                $messages[] = $refus->getMessage();
            }
        }

        $this->assertCount(3, $messages);
        $this->assertCount(1, array_unique($messages));
    }

    #[Test]
    public function les_adresses_publiques_et_privees_se_distinguent(): void
    {
        $this->assertTrue(UrlGuard::isPublic('93.184.216.34'));
        $this->assertTrue(UrlGuard::isPublic('2606:4700::6810:84e5'));
        $this->assertFalse(UrlGuard::isPublic('192.168.1.1'));
        $this->assertFalse(UrlGuard::isPublic('fd12:3456::1'));
        $this->assertFalse(UrlGuard::isPublic('224.0.0.251'));
        $this->assertFalse(UrlGuard::isPublic('pas-une-ip'));
    }

    /**
     * La vérification d'un formulaire : tout, sauf le DNS. Un nom inconnu du
     * laboratoire passe, parce qu'il n'est pas résolu ; ce qui se juge sans
     * réseau reste refusé.
     */
    #[Test]
    public function sans_resolution_seul_le_dns_est_epargne(): void
    {
        $cible = $this->guard()->check('https://inconnu-du-labo.example/sante', resolve: false);

        $this->assertSame('inconnu-du-labo.example', $cible['host']);
        $this->assertSame('', $cible['ip']);

        foreach (['http://10.0.0.1/', 'http://localhost/', 'ftp://relais.example/', 'https://a:b@relais.example/'] as $url) {
            try {
                $this->guard()->check($url, resolve: false);
                $this->fail("{$url} aurait dû être refusée sans résolution");
            } catch (UnsafeUrl) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
