<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * « Qu'est-ce que ce déploiement a cassé ? »
 *
 * Les erreurs APPARUES pendant qu'une version était la dernière de son
 * environnement : de son lancement au lancement suivant du même environnement.
 * Une préversion déployée entre-temps ne ferme pas la fenêtre de la
 * production — elle ne remplace pas ce que les utilisateurs exécutent.
 */
final class DeploymentErrorsTest extends ApiTestCase
{
    #[Test]
    public function la_fenetre_va_de_ce_deploiement_au_suivant_du_meme_environnement(): void
    {
        $session = $this->register('fenetre@test.local');
        $entete  = $this->bearer($session['token']);

        $a       = $this->deploiement($entete, 'production', '3 hours');
        $apercu  = $this->deploiement($entete, 'preview', '150 minutes');
        $b       = $this->deploiement($entete, 'production', '1 hour');

        $avant   = $this->erreur($entete, 'avant-a', '5 hours');
        $pendant = $this->erreur($entete, 'pendant-a', '2 hours');
        $apres   = $this->erreur($entete, 'apres-b', '30 minutes');

        $this->assertSame([$pendant], $this->ids($entete, $a));
        $this->assertSame([$apres], $this->ids($entete, $b));

        // La préversion, elle, voit tout ce qui est apparu depuis elle : aucune
        // autre préversion ne l'a remplacée.
        $this->assertSame([$pendant, $apres], $this->ids($entete, $apercu));

        $this->assertNotContains($avant, $this->ids($entete, $a));
    }

    #[Test]
    public function les_erreurs_d_un_autre_espace_n_entrent_pas_dans_la_fenetre(): void
    {
        $alice = $this->bearer($this->register('alice@test.local')['token']);
        $bob   = $this->bearer($this->register('bob@test.local')['token']);

        $deploiement = $this->deploiement($alice, 'production', '2 hours');

        $this->erreur($bob, 'chez-bob', '1 hour');

        $this->assertSame([], $this->ids($alice, $deploiement));
        $this->assertSame(404, $this->call('GET', "/api/deployments/{$deploiement}/errors", [], $bob)['status']);
    }

    /**
     * @param  array<string, string> $entete
     * @return list<string>
     */
    private function ids(array $entete, string $deploiement): array
    {
        $reponse = $this->call('GET', "/api/deployments/{$deploiement}/errors", [], $entete);

        $this->assertSame(200, $reponse['status'], json_encode($reponse['body']) ?: '');

        return array_column($reponse['body']['data'], 'id');
    }

    /**
     * @param array<string, string> $entete
     */
    private function deploiement(array $entete, string $environnement, string $il_y_a): string
    {
        $reponse = $this->call('POST', '/api/deployments', [
            'environment'    => $environnement,
            'branch'         => 'main',
            'commit_sha'     => substr(hash('sha1', $environnement . $il_y_a), 0, 12),
            'commit_message' => "Version d'il y a {$il_y_a}",
        ], $entete);

        $this->assertSame(201, $reponse['status'], json_encode($reponse['body']) ?: '');

        $id = (string) $reponse['body']['data']['id'];

        Database::connection()
            ->prepare('UPDATE deployments SET created_at = NOW() - CAST(:il_y_a AS interval) WHERE id = :id')
            ->execute(['il_y_a' => $il_y_a, 'id' => $id]);

        return $id;
    }

    /**
     * @param array<string, string> $entete
     */
    private function erreur(array $entete, string $empreinte, string $il_y_a): string
    {
        $reponse = $this->call('POST', '/api/errors', [
            'fingerprint' => $empreinte,
            'title'       => "Erreur {$empreinte}",
            'message'     => 'Quelque chose a cassé.',
        ], $entete);

        $this->assertContains($reponse['status'], [200, 201, 202], json_encode($reponse['body']) ?: '');

        $id = (string) $reponse['body']['data']['id'];

        Database::connection()
            ->prepare('UPDATE error_groups SET first_seen_at = NOW() - CAST(:il_y_a AS interval) WHERE id = :id')
            ->execute(['il_y_a' => $il_y_a, 'id' => $id]);

        return $id;
    }
}
