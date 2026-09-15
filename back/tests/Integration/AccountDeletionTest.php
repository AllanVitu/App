<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\SchemaBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Supprimer son compte : ce qui part, ce qui reste, et à qui.
 *
 * Deux promesses de la politique de confidentialité, vérifiées ici : un espace
 * dont le compte était le seul membre part avec lui — schéma Backend compris,
 * que la cascade de PostgreSQL n'atteint pas —, et un espace partagé reste à
 * l'équipe, avec un propriétaire et sans le nom de la personne partie.
 */
final class AccountDeletionTest extends ApiTestCase
{
    private const MOT_DE_PASSE = 'Motdepasse1-solide';

    #[Test]
    public function l_espace_d_un_compte_seul_part_avec_lui_schema_compris(): void
    {
        $seul   = $this->register('seul@test.local');
        $entete = $this->bearer($seul['token']);

        $this->call('POST', '/api/tickets', ['title' => 'Mon seul ticket'], $entete);

        $schemas = new SchemaBuilder();
        $schemas->ensureSchema($seul['org']);
        $this->assertTrue($this->schemaExiste($schemas->schemaFor($seul['org'])));

        $this->assertSame(204, $this->supprimer($seul));

        $this->assertSame(0, $this->compter('SELECT COUNT(*) FROM organizations WHERE id = :id', $seul['org']));
        $this->assertSame(0, $this->compter('SELECT COUNT(*) FROM tickets WHERE organization_id = :id', $seul['org']));
        $this->assertFalse($this->schemaExiste($schemas->schemaFor($seul['org'])), 'les tables Backend ne survivent pas à leur espace');
    }

    #[Test]
    public function un_espace_partage_reste_a_l_equipe_sans_le_nom_de_la_personne_partie(): void
    {
        $hote   = $this->register('proprietaire@test.local');
        $membre = $this->membre($hote, 'successeur@test.local');
        $ticket = $this->call('POST', '/api/tickets', ['title' => 'Écrit par le propriétaire'], $this->bearer($hote['token']))['body']['data'];

        $this->assertSame(204, $this->supprimer($hote));

        $this->assertSame(1, $this->compter('SELECT COUNT(*) FROM organizations WHERE id = :id', $hote['org']));
        $this->assertSame(1, $this->compter('SELECT COUNT(*) FROM tickets WHERE id = :id AND created_by IS NULL', $ticket['id']));

        // L'espace ne reste pas sans propriétaire.
        $this->assertSame('owner', $this->role($hote['org'], $membre['id']));

        // L'historique garde le fait, pas le nom.
        $this->assertSame(
            'Compte supprimé',
            $this->valeur("SELECT actor_name FROM activity WHERE subject_id = :id AND action = 'created'", $ticket['id']),
        );

        $this->assertSame(200, $this->call('GET', "/api/tickets/{$ticket['id']}", [], $this->bearer($membre['token']))['status']);
    }

    #[Test]
    public function un_administrateur_herite_avant_un_membre_plus_ancien(): void
    {
        $hote   = $this->register('fondatrice@test.local');
        $ancien = $this->membre($hote, 'ancien@test.local');
        $admin  = $this->membre($hote, 'admin-recent@test.local');

        // Filtré sur l'espace de l'hôte : une personne peut appartenir à
        // plusieurs espaces, et c'est de celui-ci qu'il s'agit.
        Database::connection()
            ->prepare("UPDATE memberships SET role = 'admin' WHERE user_id = :id AND organization_id = :org")
            ->execute(['id' => $admin['id'], 'org' => $hote['org']]);

        $this->assertSame(204, $this->supprimer($hote));

        $this->assertSame('owner', $this->role($hote['org'], $admin['id']));
        $this->assertSame('member', $this->role($hote['org'], $ancien['id']));
    }

    #[Test]
    public function sans_le_bon_mot_de_passe_rien_ne_part(): void
    {
        $seul = $this->register('prudent@test.local');

        $refus = $this->call('DELETE', '/api/profile', ['password' => 'pas-le-bon'], $this->bearer($seul['token']));

        $this->assertSame(422, $refus['status']);
        $this->assertSame(1, $this->compter('SELECT COUNT(*) FROM organizations WHERE id = :id', $seul['org']));
    }

    /**
     * @param array{token: string} $session
     */
    private function supprimer(array $session): int
    {
        return $this->call('DELETE', '/api/profile', ['password' => self::MOT_DE_PASSE], $this->bearer($session['token']))['status'];
    }

    private function role(string $organizationId, string $userId): string
    {
        $statement = Database::connection()->prepare(
            'SELECT role::text FROM memberships WHERE organization_id = :org AND user_id = :id',
        );
        $statement->execute(['org' => $organizationId, 'id' => $userId]);

        return (string) $statement->fetchColumn();
    }

    private function schemaExiste(string $schema): bool
    {
        return $this->compter('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = :id', $schema) === 1;
    }

    private function compter(string $sql, string $id): int
    {
        return (int) $this->valeur($sql, $id);
    }

    private function valeur(string $sql, string $id): mixed
    {
        $statement = Database::connection()->prepare($sql);
        $statement->execute(['id' => $id]);

        return $statement->fetchColumn();
    }
}
