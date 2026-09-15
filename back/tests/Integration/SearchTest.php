<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Recherche transverse aux cinq modules.
 *
 * LE TEST QUI COMPTE LE PLUS EST CELUI DU CLOISONNEMENT. La requête réunit
 * cinq tables par UNION ALL ; il suffit qu'une branche oublie son `user_id`
 * pour livrer les données d'autrui, et aucun test de module ne le verrait —
 * chacun n'interroge que sa propre table.
 */
final class SearchTest extends ApiTestCase
{
    /**
     * Sème une donnée dans plusieurs modules pour un compte donné.
     *
     * @return array<string, mixed> la session
     */
    private function compteAvecDonnees(string $email, string $marqueur): array
    {
        $session = $this->register($email);
        $entete  = $this->bearer($session['token']);

        $this->call('POST', '/api/tickets', ['title' => "Ticket {$marqueur}"], $entete);

        $this->call('POST', '/api/deployments', [
            'branch'         => "feat/{$marqueur}",
            'commit_sha'     => 'a1b2c3d4e5f6',
            'commit_message' => "Déploiement {$marqueur}",
        ], $entete);

        $this->call('POST', '/api/design/files', [
            'name' => "Maquette {$marqueur}",
            'kind' => 'maquette',
        ], $entete);

        $this->call('POST', '/api/docs', [
            'title' => "Procédure {$marqueur}",
            'body'  => 'Arrêter le worker, puis restaurer la base.',
        ], $entete);

        return $session;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function chercher(string $token, string $terme): array
    {
        $reponse = $this->call(
            'GET',
            '/api/search',
            [],
            $this->bearer($token),
            ['q' => $terme],
        );

        $this->assertSame(200, $reponse['status']);

        return $reponse['body']['data'];
    }

    #[Test]
    public function un_seul_terme_traverse_plusieurs_modules(): void
    {
        $session = $this->compteAvecDonnees('transverse@test.local', 'zephyr');

        $modules = array_column($this->chercher($session['token'], 'zephyr'), 'module');

        // C'est tout l'objet du service : un mot, plusieurs modules, sans que
        // l'utilisateur ait eu à deviner lequel.
        $this->assertContains('tickets', $modules);
        $this->assertContains('deploiement', $modules);
        $this->assertContains('design', $modules);
        $this->assertContains('documentation', $modules);
    }

    #[Test]
    public function la_recherche_ne_franchit_jamais_la_frontiere_entre_comptes(): void
    {
        $mien   = $this->compteAvecDonnees('mien@test.local', 'cloison');
        $autrui = $this->compteAvecDonnees('autrui@test.local', 'cloison');

        $this->assertNotSame($mien['id'], $autrui['id']);

        // Les deux comptes ont des données portant le MÊME marqueur : si une
        // branche de l'UNION oubliait son user_id, chacun verrait huit lignes
        // au lieu de quatre.
        foreach ([$mien, $autrui] as $session) {
            $resultats = $this->chercher($session['token'], 'cloison');

            $this->assertCount(
                4,
                $resultats,
                'un compte ne doit voir que ses propres données',
            );
        }
    }

    #[Test]
    public function une_sonde_et_le_texte_d_une_page_se_trouvent_aussi(): void
    {
        $session = $this->register('sondes-pages@test.local');
        $entete  = $this->bearer($session['token']);

        $sonde = $this->call('POST', '/api/probes', [
            'name'             => 'Paiement quasar',
            'url'              => 'https://boutique.relais-demo.fr/paiement',
            'method'           => 'GET',
            'interval_seconds' => 300,
            'timeout_ms'       => 5000,
            'slow_ms'          => 1000,
        ], $entete);
        $this->assertSame(201, $sonde['status']);

        $page = $this->call('POST', '/api/docs', [
            'title' => 'Astreinte',
            'body'  => 'Si le paiement tombe, relancer la file nébuleuse.',
        ], $entete);
        $this->assertSame(201, $page['status']);

        $parNom = $this->chercher($session['token'], 'quasar');
        $this->assertSame(['disponibilite'], array_column($parNom, 'module'));
        $this->assertSame('boutique.relais-demo.fr', $parNom[0]['ref']);

        // Le texte, pas seulement le titre — et les accents repliés.
        $parTexte = $this->chercher($session['token'], 'nebuleuse');
        $this->assertSame(['documentation'], array_column($parTexte, 'module'));
        $this->assertSame('Astreinte', $parTexte[0]['title']);
    }

    #[Test]
    public function un_terme_trop_court_ne_renvoie_rien(): void
    {
        $session = $this->compteAvecDonnees('court@test.local', 'brievete');

        // Un seul caractère remonterait presque tout : ce n'est pas une
        // recherche, c'est un listing déguisé.
        $this->assertSame([], $this->chercher($session['token'], 'b'));
        $this->assertSame([], $this->chercher($session['token'], ''));
    }

    /**
     * Les jokers de LIKE sont neutralisés. Sans échappement, « % » remonterait
     * l'intégralité du compte — une fuite d'information par un caractère.
     */
    #[Test]
    public function les_jokers_sont_neutralises(): void
    {
        $session = $this->compteAvecDonnees('joker@test.local', 'echappement');

        $this->assertSame([], $this->chercher($session['token'], '%%'));
        $this->assertSame([], $this->chercher($session['token'], '__'));
    }

    /**
     * Personne ne tape les accents dans un champ de recherche. Sans repli,
     * « deploiement » ne trouverait jamais « Déploiement ».
     */
    #[Test]
    public function les_accents_sont_replies(): void
    {
        $session = $this->compteAvecDonnees('accents@test.local', 'accentue');

        $sans = array_column($this->chercher($session['token'], 'deploiement'), 'module');
        $avec = array_column($this->chercher($session['token'], 'déploiement'), 'module');

        $this->assertContains('deploiement', $sans, '« deploiement » doit trouver « Déploiement »');
        $this->assertSame($avec, $sans, 'la présence des accents ne doit rien changer');
    }

    #[Test]
    public function un_visiteur_non_authentifie_ne_peut_pas_chercher(): void
    {
        $reponse = $this->call('GET', '/api/search', [], [], ['q' => 'quoiquecesoit']);

        $this->assertSame(401, $reponse['status']);
    }

    /**
     * Le terme est renvoyé avec les résultats : c'est ce qui permet au client
     * d'ignorer une réponse périmée. On tape plus vite que le réseau ne
     * répond, et sans lui une réponse lente à « re » écraserait celle de
     * « refresh » déjà affichée.
     */
    #[Test]
    public function la_reponse_rappelle_le_terme_cherche(): void
    {
        $session = $this->compteAvecDonnees('echo@test.local', 'rappel');

        $reponse = $this->call(
            'GET',
            '/api/search',
            [],
            $this->bearer($session['token']),
            ['q' => 'rappel'],
        );

        $this->assertSame('rappel', $reponse['body']['meta']['query']);
    }
}
