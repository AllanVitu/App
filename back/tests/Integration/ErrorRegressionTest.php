<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Une erreur qui REVIENT.
 *
 * Le cas qui a fait naître ce fichier : supprimer un groupe d'erreurs, puis
 * recevoir la même erreur. L'occurrence était comptée dans le groupe supprimé,
 * que plus aucun écran ne montrait, et l'ingestion répondait « data: null ».
 * La panne existait, frappait, et restait invisible pour de bon.
 */
final class ErrorRegressionTest extends ApiTestCase
{
    #[Test]
    public function une_erreur_supprimee_qui_revient_reapparait(): void
    {
        $entetes = $this->bearer($this->register('regression@test.local')['token']);

        $id = $this->call('POST', '/api/errors', $this->erreur(), $entetes)['body']['data']['id'];
        $this->assertSame(204, $this->call('DELETE', "/api/errors/{$id}", [], $entetes)['status']);

        $retour = $this->call('POST', '/api/errors', $this->erreur(), $entetes);

        $this->assertSame(201, $retour['status']);
        $this->assertSame($id, $retour['body']['data']['id'], 'le même groupe revient, pas un nouveau');
        $this->assertTrue($retour['body']['data']['reopened']);
        $this->assertSame(2, $retour['body']['data']['occurrences']);

        $liste = $this->call('GET', '/api/errors', [], $entetes);
        $this->assertContains($id, array_column($liste['body']['data'], 'id'));

        $this->assertSame('reopened', $this->derniereAction($id));
    }

    #[Test]
    public function une_erreur_resolue_qui_revient_se_rouvre_et_le_dit_dans_le_fil(): void
    {
        $entetes = $this->bearer($this->register('resolue@test.local')['token']);

        $id = $this->call('POST', '/api/errors', $this->erreur(), $entetes)['body']['data']['id'];
        $this->assertSame(200, $this->call('PUT', "/api/errors/{$id}", ['status' => 'resolved'], $entetes)['status']);

        $retour = $this->call('POST', '/api/errors', $this->erreur(), $entetes)['body']['data'];

        $this->assertSame('unresolved', $retour['status']);
        $this->assertTrue($retour['reopened']);
        $this->assertSame('reopened', $this->derniereAction($id));
    }

    /**
     * « Ignoré » est une décision explicite de ne plus en entendre parler : la
     * répétition ne la défait pas, et n'a donc rien à annoncer.
     */
    #[Test]
    public function une_erreur_ignoree_qui_revient_reste_ignoree_et_silencieuse(): void
    {
        $entetes = $this->bearer($this->register('ignoree@test.local')['token']);

        $id = $this->call('POST', '/api/errors', $this->erreur(), $entetes)['body']['data']['id'];
        $this->call('PUT', "/api/errors/{$id}", ['status' => 'ignored'], $entetes);

        $retour = $this->call('POST', '/api/errors', $this->erreur(), $entetes)['body']['data'];

        $this->assertSame('ignored', $retour['status']);
        $this->assertFalse($retour['reopened']);
        $this->assertNotSame('reopened', $this->derniereAction($id));
    }

    /**
     * @return array<string, string>
     */
    private function erreur(): array
    {
        return [
            'fingerprint' => 'panier-total-indefini',
            'title'       => 'TypeError: impossible de lire « total »',
            'message'     => 'Cannot read properties of undefined (reading total)',
        ];
    }

    private function derniereAction(string $subjectId): string
    {
        $statement = Database::connection()->prepare(
            'SELECT action FROM activity WHERE subject_id = :id ORDER BY id DESC LIMIT 1',
        );
        $statement->execute(['id' => $subjectId]);

        return (string) $statement->fetchColumn();
    }
}
