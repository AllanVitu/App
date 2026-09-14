<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Les limites de débit ne se contournent plus en changeant d'en-tête, et
 * l'inscription en a enfin une.
 */
final class ClientIpThrottleTest extends ApiTestCase
{
    /**
     * Le verrou par IP bloque le balayage de nombreux comptes depuis une
     * machine. Quand l'adresse venait de « X-Forwarded-For », chaque tentative
     * pouvait s'en inventer une nouvelle, et le verrou ne se fermait jamais.
     */
    #[Test]
    public function un_faux_x_forwarded_for_ne_contourne_plus_le_verrou_par_ip(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $reponse = $this->call(
                'POST',
                '/api/auth/login',
                ['email' => "inconnu{$i}@test.local", 'password' => 'mauvais'],
                ['X-Forwarded-For' => "198.51.100.{$i}"],
            );

            $this->assertSame(401, $reponse['status']);
        }

        $refus = $this->call(
            'POST',
            '/api/auth/login',
            ['email' => 'encore-un@test.local', 'password' => 'mauvais'],
            ['X-Forwarded-For' => '203.0.113.77'],
        );

        $this->assertSame(429, $refus['status'], 'vingt échecs depuis la même machine, quelle que soit l\'adresse annoncée');
    }

    /**
     * L'inscription n'avait aucune limite : une boucle créait des comptes à
     * volonté, et chacun déclenchait un e-mail de confirmation vers une adresse
     * qui pouvait être celle de n'importe qui.
     *
     * Vingt par heure et par adresse, et non dix : en développement, toutes
     * les requêtes arrivent de la même adresse, et une exécution complète des
     * parcours navigateur crée quatre comptes. À dix, la troisième relance de
     * l'heure échouait pour une raison sans rapport avec ce qu'elle vérifie.
     */
    #[Test]
    public function l_inscription_est_limitee_par_adresse(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->register("inscrit{$i}@test.local");
        }

        $refus = $this->call('POST', '/api/auth/register', [
            'full_name'      => 'Vingt et unième',
            'email'          => 'vingt-et-unieme@test.local',
            'password'       => 'Motdepasse1-solide',
            'terms_accepted' => true,
        ]);

        $this->assertSame(429, $refus['status']);
    }
}
