<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Préférences du compte.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  CET ÉCRAN A DÉJÀ PERDU DES RÉGLAGES QUI N'AGISSAIENT PAS               │
 * │                                                                         │
 * │  Trois interrupteurs de notification et un choix « English » en ont été │
 * │  retirés : ils étaient enregistrés en base et consommés par personne.   │
 * │  Un réglage qui ne change rien fait croire à un contrôle qui n'existe   │
 * │  pas.                                                                   │
 * │                                                                         │
 * │  Ce qui se vérifie ici est donc le CONTRAT de l'API : ce qu'elle        │
 * │  accepte, ce qu'elle refuse, et ce qu'elle conserve quand on ne lui     │
 * │  envoie qu'un champ. L'effet à l'écran, lui, est vérifié par un         │
 * │  parcours navigateur — il ne se voit pas d'ici.                         │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
final class SettingsTest extends ApiTestCase
{
    #[Test]
    public function un_compte_neuf_part_sur_des_valeurs_sensees(): void
    {
        $user = $this->register();

        $reponse = $this->call('GET', '/api/settings', headers: $this->bearer($user['token']));
        $reglages = $reponse['body']['data'];

        $this->assertSame(200, $reponse['status']);
        $this->assertSame('system', $reglages['theme']);
        $this->assertSame('confortable', $reglages['density']);
        $this->assertFalse($reglages['reduce_motion']);
    }

    #[Test]
    public function la_densite_et_le_mouvement_s_enregistrent(): void
    {
        $user = $this->register();

        $reponse = $this->call(
            'PUT',
            '/api/settings',
            ['density' => 'compact', 'reduce_motion' => true],
            headers: $this->bearer($user['token']),
        );

        $this->assertSame(200, $reponse['status']);
        $this->assertSame('compact', $reponse['body']['data']['density']);
        $this->assertTrue($reponse['body']['data']['reduce_motion']);

        // Et ils survivent à une relecture : c'est bien la base qui les porte,
        // pas la réponse qui les renvoie.
        $relu = $this->call('GET', '/api/settings', headers: $this->bearer($user['token']));

        $this->assertSame('compact', $relu['body']['data']['density']);
        $this->assertTrue($relu['body']['data']['reduce_motion']);
    }

    #[Test]
    public function un_champ_absent_conserve_sa_valeur(): void
    {
        $user = $this->register();

        $this->call(
            'PUT',
            '/api/settings',
            ['density' => 'compact', 'reduce_motion' => true],
            headers: $this->bearer($user['token']),
        );

        // L'écran Paramètres envoie tout le formulaire, mais le contrat est
        // celui d'une mise à jour PARTIELLE : changer le thème seul ne doit
        // pas remettre la densité par défaut.
        $reponse = $this->call(
            'PUT',
            '/api/settings',
            ['theme' => 'dark'],
            headers: $this->bearer($user['token']),
        );

        $this->assertSame('dark', $reponse['body']['data']['theme']);
        $this->assertSame('compact', $reponse['body']['data']['density']);
        $this->assertTrue($reponse['body']['data']['reduce_motion']);
    }

    #[Test]
    public function une_densite_inventee_est_refusee(): void
    {
        $user = $this->register();

        $reponse = $this->call(
            'PUT',
            '/api/settings',
            ['density' => 'gigantesque'],
            headers: $this->bearer($user['token']),
        );

        // Refusée par le VALIDATEUR — et la base porte la même contrainte, de
        // sorte qu'une insertion manuelle en psql produise une ligne aussi
        // correcte qu'un passage par l'API.
        $this->assertSame(422, $reponse['status']);
        $this->assertArrayHasKey('density', $reponse['body']['errors']);
    }

    #[Test]
    public function les_preferences_ne_traversent_pas_les_comptes(): void
    {
        $premier = $this->register('premier@test.local');
        $second  = $this->register('second@test.local');

        $this->call(
            'PUT',
            '/api/settings',
            ['density' => 'compact'],
            headers: $this->bearer($premier['token']),
        );

        $reponse = $this->call('GET', '/api/settings', headers: $this->bearer($second['token']));

        $this->assertSame('confortable', $reponse['body']['data']['density']);
    }

    #[Test]
    public function un_fuseau_inconnu_est_refuse(): void
    {
        $user = $this->register();

        // Comparé à la liste officielle PHP plutôt qu'à une expression
        // régulière : seule une valeur réellement utilisable passe.
        $reponse = $this->call(
            'PUT',
            '/api/settings',
            ['timezone' => 'Europe/Atlantide'],
            headers: $this->bearer($user['token']),
        );

        $this->assertSame(422, $reponse['status']);
        $this->assertArrayHasKey('timezone', $reponse['body']['errors']);
    }
}
