<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Env;
use App\Core\Database;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Une invitation fait partir un e-mail vers une adresse que l'invitant choisit.
 *
 * Sans plafond, un compte — ou un jeton volé — ferait de Relais un relais de
 * spam, et le domaine d'envoi finirait sur liste noire : plus personne ne
 * recevrait ses liens de confirmation. Deux plafonds : par personne, et par
 * espace, pour que plusieurs administrateurs ne multiplient pas l'envoi.
 *
 * Les plafonds de production (20 par heure, 50 par jour) sont abaissés pour
 * les tests par tests/bootstrap.php — 3 et 5 : le même comportement, sans
 * cinquante requêtes par test.
 */
final class InvitationLimitTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(3, Env::int('INVITATIONS_PER_HOUR', 20), 'tests/bootstrap.php fixe le plafond horaire de test');
        $this->assertSame(5, Env::int('INVITATIONS_PER_DAY', 50), 'tests/bootstrap.php fixe le plafond quotidien de test');
    }

    #[Test]
    public function une_personne_est_plafonnee_par_heure_et_rien_ne_part_au_dela(): void
    {
        $entete = $this->bearer($this->register('recruteuse@test.local')['token']);

        for ($i = 1; $i <= 3; $i++) {
            $this->assertSame(201, $this->inviter($entete, "invite-{$i}@test.local"), "invitation n° {$i}");
        }

        $this->assertSame(429, $this->inviter($entete, 'une-de-trop@test.local'));

        // Refusée AVANT l'envoi : ni invitation en base, ni e-mail en file.
        $this->assertSame(0, $this->compter("SELECT COUNT(*) FROM invitations WHERE email = 'une-de-trop@test.local'"));
        $this->assertSame(0, $this->compter("SELECT COUNT(*) FROM jobs WHERE type = 'mail.send' AND payload->>'to' = 'une-de-trop@test.local'"));
    }

    #[Test]
    public function un_espace_est_plafonne_par_jour_meme_a_plusieurs_administrateurs(): void
    {
        $hote = $this->register('fondatrice-limite@test.local');

        // L'invitation de l'administratrice compte déjà : une pour l'hôte, une
        // pour l'espace.
        $admin = $this->membre($hote, 'admin-limite@test.local');

        Database::connection()
            ->prepare("UPDATE memberships SET role = 'admin' WHERE user_id = :id AND organization_id = :org")
            ->execute(['id' => $admin['id'], 'org' => $hote['org']]);

        // L'hôte atteint son plafond horaire (3), l'administratrice en envoie
        // deux : l'espace en est à cinq.
        foreach (['hote-a', 'hote-b'] as $adresse) {
            $this->assertSame(201, $this->inviter($this->bearer($hote['token']), "{$adresse}@test.local"));
        }

        foreach (['admin-a', 'admin-b'] as $adresse) {
            $this->assertSame(201, $this->inviter($this->bearer($admin['token']), "{$adresse}@test.local"));
        }

        // L'administratrice reste sous SON plafond horaire (2 sur 3) : c'est
        // celui de l'espace qui refuse.
        $this->assertSame(429, $this->inviter($this->bearer($admin['token']), 'une-de-trop@test.local'));
    }

    #[Test]
    public function une_saisie_refusee_ne_consomme_pas_le_plafond(): void
    {
        $entete = $this->bearer($this->register('distraite@test.local')['token']);

        // Plus de fautes de frappe que le plafond n'autorise d'invitations…
        for ($i = 1; $i <= 5; $i++) {
            $this->assertSame(422, $this->inviter($entete, "pas-une-adresse-{$i}"));
        }

        // …et l'invitation corrigée part quand même.
        $this->assertSame(201, $this->inviter($entete, 'corrigee@test.local'));
    }

    /**
     * @param array<string, string> $entete
     */
    private function inviter(array $entete, string $email): int
    {
        return $this->call('POST', '/api/organizations/invitations', ['email' => $email], $entete)['status'];
    }

    private function compter(string $sql): int
    {
        return (int) Database::connection()->query($sql)->fetchColumn();
    }
}
