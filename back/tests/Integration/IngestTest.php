<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\BackendRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Ingestion d'erreurs par clé d'API.
 *
 * C'est la porte d'entrée du module Supervision, et elle n'existait pas :
 * POST /api/errors exigeait un jeton de SESSION, valable quinze minutes et
 * détenu par un navigateur connecté. Une application en production ne peut
 * pas en obtenir un — le module ne recevait donc jamais rien de réel.
 *
 * Ces tests portent sur les RÈGLES DE REFUS autant que sur le cas nominal :
 * c'est du code d'authentification, et une règle qui cesse silencieusement de
 * s'appliquer y ouvre une porte au lieu d'en fermer une.
 */
final class IngestTest extends ApiTestCase
{
    /**
     * @return array{token: string, session: array<string, mixed>, key: array<string, mixed>}
     */
    private function serviceKey(string $email = 'ingestion@test.local'): array
    {
        $session = $this->register($email);
        $created = (new BackendRepository())->createKey($session['org'], $session['id'], 'Rapporteur', 'service');

        return ['token' => $created['token'], 'session' => $session, 'key' => $created['key']];
    }

    /**
     * @return array<string, mixed>
     */
    private function erreur(string $fingerprint = 'checkout-total-undefined'): array
    {
        return [
            'fingerprint' => $fingerprint,
            'title'       => 'TypeError: impossible de lire « total »',
            'culprit'     => 'checkout.js:88',
            'level'       => 'error',
            'message'     => 'Cannot read properties of undefined (reading total)',
            'stack'       => 'at checkout (checkout.js:88)',
        ];
    }

    #[Test]
    public function une_cle_de_service_peut_signaler_une_erreur(): void
    {
        ['token' => $token, 'session' => $session] = $this->serviceKey();

        $reponse = $this->call('POST', '/api/errors', $this->erreur(), $this->bearer($token));

        $this->assertSame(201, $reponse['status']);

        // L'erreur atterrit bien sur LE COMPTE DE LA CLÉ : c'est tout l'objet
        // du middleware. Une erreur rangée chez quelqu'un d'autre serait pire
        // qu'une erreur perdue.
        $liste = $this->call('GET', '/api/errors', [], $this->bearer($session['token']));

        $titres = array_column($liste['body']['data'], 'title');
        $this->assertContains('TypeError: impossible de lire « total »', $titres);
    }

    #[Test]
    public function une_cle_publique_ne_peut_pas_ecrire(): void
    {
        $session = $this->register('publique@test.local');
        $created = (new BackendRepository())->createKey($session['org'], $session['id'], 'Navigateur', 'anon');

        $reponse = $this->call(
            'POST',
            '/api/errors',
            $this->erreur(),
            $this->bearer($created['token']),
        );

        // Une clé « anon » vit dans du code livré au navigateur : elle est
        // publique par destination. Lui laisser écrire ouvrirait l'ingestion à
        // quiconque lit la source de la page.
        $this->assertSame(401, $reponse['status']);
    }

    #[Test]
    public function une_cle_revoquee_cesse_immediatement_de_fonctionner(): void
    {
        ['token' => $token, 'session' => $session, 'key' => $key] = $this->serviceKey(
            'revoquee@test.local',
        );

        $avant = $this->call('POST', '/api/errors', $this->erreur(), $this->bearer($token));
        $this->assertSame(201, $avant['status'], 'la clé devait fonctionner avant révocation');

        (new BackendRepository())->revokeKey($key['id'], $session['org']);

        $apres = $this->call('POST', '/api/errors', $this->erreur(), $this->bearer($token));
        $this->assertSame(401, $apres['status'], 'une clé révoquée doit être refusée');
    }

    #[Test]
    public function une_cle_inconnue_est_refusee(): void
    {
        $reponse = $this->call(
            'POST',
            '/api/errors',
            $this->erreur(),
            $this->bearer('sk_deadbeef_totalement_inventee'),
        );

        $this->assertSame(401, $reponse['status']);
    }

    #[Test]
    public function sans_aucune_authentification_l_ingestion_est_refusee(): void
    {
        $reponse = $this->call('POST', '/api/errors', $this->erreur());

        $this->assertSame(401, $reponse['status']);
    }

    /**
     * La session reste acceptée : c'est ce qui permet d'essayer l'endpoint
     * depuis l'interface sans fabriquer de clé au préalable.
     */
    #[Test]
    public function une_session_ouverte_reste_acceptee(): void
    {
        $session = $this->register('session@test.local');

        $reponse = $this->call(
            'POST',
            '/api/errors',
            $this->erreur(),
            $this->bearer($session['token']),
        );

        $this->assertSame(201, $reponse['status']);
    }

    /**
     * Le préfixe décide de la voie d'authentification. Sans ce tri, une clé
     * d'API partirait dans la vérification JWT et l'utilisateur lirait
     * « jeton illisible » sur une clé parfaitement valide — un message qui
     * envoie chercher le problème au mauvais endroit.
     */
    #[Test]
    public function une_cle_invalide_ne_produit_pas_un_message_de_jeton(): void
    {
        $reponse = $this->call(
            'POST',
            '/api/errors',
            $this->erreur(),
            $this->bearer('sk_00000000_invalide'),
        );

        $this->assertSame(401, $reponse['status']);
        $this->assertStringContainsString('Clé d\'API', $reponse['body']['message']);
    }

    /**
     * Le regroupement par empreinte est tenu par un trigger PostgreSQL : deux
     * envois identiques forment un seul groupe, avec un compteur. C'est la
     * règle qui rend le module utilisable — sans elle, une erreur récurrente
     * noierait toutes les autres.
     */
    #[Test]
    public function deux_envois_de_meme_empreinte_forment_un_seul_groupe(): void
    {
        ['token' => $token, 'session' => $session] = $this->serviceKey('groupe@test.local');

        foreach (range(1, 3) as $ignored) {
            $this->call('POST', '/api/errors', $this->erreur('meme-empreinte'), $this->bearer($token));
        }

        $liste = $this->call('GET', '/api/errors', [], $this->bearer($session['token']));

        $groupes = array_values(array_filter(
            $liste['body']['data'],
            static fn (array $g): bool => $g['fingerprint'] === 'meme-empreinte',
        ));

        $this->assertCount(1, $groupes, 'trois envois identiques doivent former un seul groupe');
        $this->assertSame(3, $groupes[0]['occurrences']);
    }
}
