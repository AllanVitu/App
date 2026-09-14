<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ClientIp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * L'adresse IP d'un client, et qui a le droit de l'affirmer.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  « X-Forwarded-For » EST UN EN-TÊTE COMME UN AUTRE                      │
 * │                                                                         │
 * │  N'importe quel client l'écrit. Le lire sans condition, c'était laisser │
 * │  chaque requête CHOISIR son adresse IP — et donc remettre à zéro le     │
 * │  verrou de connexion par IP à chaque tentative, en changeant une ligne. │
 * │                                                                         │
 * │  Seul un proxy DE CONFIANCE peut dire d'où vient la requête qu'il       │
 * │  relaie. Et même derrière lui, la chaîne se lit de droite à gauche : le │
 * │  début a été écrit par le client, la fin par l'infrastructure.          │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
final class ClientIpTest extends TestCase
{
    #[Test]
    public function sans_proxy_de_confiance_l_en_tete_est_ignore(): void
    {
        $this->assertSame('172.18.0.1', ClientIp::resolve('172.18.0.1', '198.51.100.7', []));
    }

    #[Test]
    public function un_intermediaire_qui_n_est_pas_de_confiance_n_affirme_rien(): void
    {
        $this->assertSame('203.0.113.9', ClientIp::resolve('203.0.113.9', '198.51.100.7', ['10.0.0.0/8']));
    }

    #[Test]
    public function derriere_un_proxy_de_confiance_le_client_est_le_dernier_maillon_etranger(): void
    {
        $this->assertSame(
            '198.51.100.7',
            ClientIp::resolve('10.0.0.5', '198.51.100.7, 10.0.0.4', ['10.0.0.0/8']),
        );
    }

    /**
     * Le cas d'attaque : le client écrit lui-même un en-tête, et le proxy de
     * confiance y AJOUTE l'adresse qu'il a réellement vue. Lire la chaîne par
     * la gauche rendrait l'adresse inventée.
     */
    #[Test]
    public function le_debut_de_chaine_ecrit_par_le_client_ne_passe_pas(): void
    {
        $this->assertSame(
            '198.51.100.7',
            ClientIp::resolve('10.0.0.5', '1.1.1.1, 198.51.100.7', ['10.0.0.0/8']),
        );
    }

    #[Test]
    public function une_chaine_illisible_rend_l_adresse_du_proxy(): void
    {
        $this->assertSame('10.0.0.5', ClientIp::resolve('10.0.0.5', 'n-importe-quoi', ['10.0.0.0/8']));
    }

    #[Test]
    public function derriere_un_proxy_sans_en_tete_l_adresse_distante_reste(): void
    {
        $this->assertSame('10.0.0.5', ClientIp::resolve('10.0.0.5', null, ['10.0.0.0/8']));
    }

    #[Test]
    public function les_adresses_ipv6_suivent_les_memes_regles(): void
    {
        $this->assertSame('2001:db8::1', ClientIp::resolve('fd00::5', '2001:db8::1', ['fd00::/8']));
        $this->assertSame('2001:db8::9', ClientIp::resolve('2001:db8::9', '2001:db8::1', ['fd00::/8']));
    }

    #[Test]
    public function une_adresse_distante_illisible_ne_devient_pas_une_adresse(): void
    {
        $this->assertNull(ClientIp::resolve('pas-une-ip', '198.51.100.7', []));
        $this->assertNull(ClientIp::resolve(null, null, []));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function plages(): iterable
    {
        yield 'dans un bloc IPv4'        => ['10.1.2.3', '10.0.0.0/8', true];
        yield 'hors du bloc IPv4'        => ['11.0.0.1', '10.0.0.0/8', false];
        yield 'bloc /32'                 => ['192.0.2.10', '192.0.2.10/32', true];
        yield 'adresse seule, égale'     => ['192.0.2.10', '192.0.2.10', true];
        yield 'adresse seule, différente' => ['192.0.2.11', '192.0.2.10', false];
        yield 'dans un bloc IPv6'        => ['fd12:3456::1', 'fd00::/8', true];
        yield 'famille différente'       => ['10.0.0.1', 'fd00::/8', false];
        yield 'bloc illisible'           => ['10.0.0.1', '10.0.0.0/99', false];
        yield 'bloc vide'                => ['10.0.0.1', '', false];
    }

    #[Test]
    #[DataProvider('plages')]
    public function une_adresse_appartient_ou_non_a_une_plage(string $ip, string $plage, bool $attendu): void
    {
        $this->assertSame($attendu, ClientIp::inRange($ip, $plage));
    }
}
