<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scrubber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ce que la supervision a le droit de garder d'un message d'erreur.
 *
 * Les deux listes comptent autant l'une que l'autre. Un nettoyage qui laisse
 * passer une adresse fait de la supervision une fuite ; un nettoyage qui
 * efface les UUID, les heures et les noms de classe rend chaque panne
 * illisible — et l'on finirait par le désactiver pour pouvoir travailler.
 */
final class ScrubberTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function fuites(): iterable
    {
        yield 'adresse recopiée par une violation d\'unicité' => [
            'Key (email)=(alice.martin+test@exemple.fr) already exists.',
            'Key (email)=([courriel]) already exists.',
        ];

        yield 'jeton JWT' => [
            'jeton eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl refusé',
            'jeton [jeton] refusé',
        ];

        yield 'en-tête d\'autorisation' => [
            'Authorization: Bearer 9f86d081884c7d659a2feaa0c55ad015',
            'Authorization: Bearer [jeton]',
        ];

        yield 'clé d\'API de service' => [
            'clé sk_1a2b3c4d_e5f6a7b8c9d0 inconnue',
            'clé [clé] inconnue',
        ];

        yield 'jeton de réinitialisation' => [
            'lien ' . str_repeat('ab', 32) . ' expiré',
            'lien [jeton] expiré',
        ];

        yield 'mot de passe dans une chaîne de connexion' => [
            'pgsql:host=db;password=Sup3rS3cret!;dbname=saas',
            'pgsql:host=db;password=[masqué];dbname=saas',
        ];

        yield 'jeton dans un fragment JSON' => [
            '{"refresh_token": "abc123", "ok": true}',
            '{"refresh_token": [masqué], "ok": true}',
        ];

        yield 'adresse IPv4' => [
            'connexion refusée depuis 192.168.1.42',
            'connexion refusée depuis [ip]',
        ];

        yield 'adresse IPv6 complète' => [
            'client 2001:0db8:85a3:0000:0000:8a2e:0370:7334',
            'client [ip]',
        ];

        yield 'numéro de téléphone' => [
            'rappeler le 06 12 34 56 78 demain',
            'rappeler le [numéro] demain',
        ];

        yield 'numéro de carte' => [
            'carte 4111 1111 1111 1111 refusée',
            'carte [numéro] refusée',
        ];
    }

    #[Test]
    #[DataProvider('fuites')]
    public function ce_qui_designe_une_personne_ou_ouvre_un_acces_est_retire(string $brut, string $attendu): void
    {
        $this->assertSame($attendu, Scrubber::text($brut));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function reperes(): iterable
    {
        yield 'identifiant de ligne' => ['Ticket 550e8400-e29b-41d4-a716-446655440000 introuvable'];
        yield 'date et heure' => ['échec à 2026-09-13 14:05:32'];
        yield 'appel statique' => ['App\Controllers\TicketController::update() a levé'];
        yield 'chemin et ligne' => ['src/Models/ErrorRepository.php:188'];
        yield 'le mot « token » sans valeur' => ['tokens.purge a échoué'];
        yield 'code SQLSTATE' => ['SQLSTATE[23505]: Unique violation: 7 ERROR'];
    }

    #[Test]
    #[DataProvider('reperes')]
    public function ce_qui_sert_a_lire_la_panne_reste_intact(string $texte): void
    {
        $this->assertSame($texte, Scrubber::text($texte));
    }

    #[Test]
    public function la_valeur_masquee_n_emporte_pas_la_suite_du_message(): void
    {
        $this->assertSame(
            'password=[masqué] et la suite reste lisible',
            Scrubber::text('password=hunter2 et la suite reste lisible'),
        );
    }
}
