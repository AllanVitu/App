<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Le module Documentation, par son API.
 */
final class DocsTest extends ApiTestCase
{
    #[Test]
    public function l_arbre_montre_les_titres_sans_les_corps(): void
    {
        $entete = $this->bearer($this->register('arbre@test.local')['token']);

        $racine = $this->page($entete, ['title' => 'Exploitation', 'body' => str_repeat('Procédure. ', 200)]);
        $this->page($entete, ['title' => 'Restaurer une sauvegarde', 'parent_id' => $racine]);

        $arbre = $this->call('GET', '/api/docs', headers: $entete);

        $this->assertSame(200, $arbre['status']);
        $this->assertCount(2, $arbre['body']['data']);
        $this->assertArrayNotHasKey('body', $arbre['body']['data'][0], 'l’arbre ne transporte pas les corps');
        $this->assertLessThanOrEqual(400, mb_strlen($arbre['body']['data'][0]['head']));
    }

    /**
     * Le texte est stocké tel qu'il a été écrit, balises comprises : c'est au
     * client de l'afficher comme du texte (cf. utils/richText). Le transformer
     * ici — l'échapper, le « nettoyer » — le rendrait faux à la relecture.
     */
    #[Test]
    public function le_texte_revient_exactement_tel_qu_il_a_ete_ecrit(): void
    {
        $entete = $this->bearer($this->register('texte@test.local')['token']);
        $corps  = "# Titre\n\n<script>alert('x')</script>\n\n```\n  indenté\n```";

        $id = $this->page($entete, ['title' => 'Piège', 'body' => $corps]);

        $this->assertSame($corps, $this->call('GET', "/api/docs/{$id}", headers: $entete)['body']['data']['body']);
    }

    #[Test]
    public function la_recherche_trouve_titre_ou_texte_et_garde_les_jokers_comme_caracteres(): void
    {
        $entete = $this->bearer($this->register('recherche@test.local')['token']);

        $this->page($entete, ['title' => 'Paiement', 'body' => 'Relancer le prestataire.']);
        $this->page($entete, ['title' => 'Remises', 'body' => 'Une remise de 100% est refusée.']);
        $this->page($entete, ['title' => 'Autre', 'body' => 'Rien à voir.']);

        $titres = fn (string $terme): array => array_column(
            $this->call('GET', '/api/docs', headers: $entete, query: ['q' => $terme])['body']['data'],
            'title',
        );

        $this->assertSame(['Paiement'], $titres('paiement'));
        $this->assertSame(['Paiement'], $titres('prestataire'));
        $this->assertSame(['Remises'], $titres('100%'));
        $this->assertSame([], $titres('10_%'), 'le joker « _ » ne vaut pas n’importe quel caractère');
    }

    #[Test]
    public function une_page_ne_se_range_ni_sous_elle_meme_ni_sous_une_descendante(): void
    {
        $entete = $this->bearer($this->register('boucle@test.local')['token']);

        $a = $this->page($entete, ['title' => 'A']);
        $b = $this->page($entete, ['title' => 'B', 'parent_id' => $a]);
        $c = $this->page($entete, ['title' => 'C', 'parent_id' => $b]);

        $this->assertSame(422, $this->call('PUT', "/api/docs/{$a}", ['parent_id' => $a], $entete)['status']);
        $this->assertSame(422, $this->call('PUT', "/api/docs/{$a}", ['parent_id' => $c], $entete)['status']);
        $this->assertSame(200, $this->call('PUT', "/api/docs/{$c}", ['parent_id' => $a], $entete)['status']);
    }

    #[Test]
    public function le_fil_d_ariane_va_de_la_racine_au_parent(): void
    {
        $entete = $this->bearer($this->register('fil@test.local')['token']);

        $a = $this->page($entete, ['title' => 'Exploitation']);
        $b = $this->page($entete, ['title' => 'Base de données', 'parent_id' => $a]);
        $c = $this->page($entete, ['title' => 'Restaurer', 'parent_id' => $b]);

        $fil = $this->call('GET', "/api/docs/{$c}", headers: $entete)['body']['data']['ancestors'];

        $this->assertSame(['Exploitation', 'Base de données'], array_column($fil, 'title'));
    }

    #[Test]
    public function une_page_avec_des_sous_pages_ne_se_supprime_pas(): void
    {
        $entete = $this->bearer($this->register('parent@test.local')['token']);

        $parent = $this->page($entete, ['title' => 'Parent']);
        $enfant = $this->page($entete, ['title' => 'Enfant', 'parent_id' => $parent]);

        $this->assertSame(422, $this->call('DELETE', "/api/docs/{$parent}", headers: $entete)['status']);
        $this->assertSame(204, $this->call('DELETE', "/api/docs/{$enfant}", headers: $entete)['status']);
        $this->assertSame(204, $this->call('DELETE', "/api/docs/{$parent}", headers: $entete)['status']);
    }

    /**
     * Restaurer une page dont le parent a disparu entre-temps la ramène à la
     * racine, plutôt que sous une page que plus personne ne voit.
     */
    #[Test]
    public function une_page_restauree_sans_son_parent_revient_a_la_racine(): void
    {
        $entete = $this->bearer($this->register('retour@test.local')['token']);

        $parent = $this->page($entete, ['title' => 'Parent']);
        $enfant = $this->page($entete, ['title' => 'Enfant', 'parent_id' => $parent]);

        $this->call('DELETE', "/api/docs/{$enfant}", headers: $entete);
        $this->call('DELETE', "/api/docs/{$parent}", headers: $entete);

        $restauree = $this->call('POST', "/api/docs/{$enfant}/restore", headers: $entete);

        $this->assertSame(200, $restauree['status']);
        $this->assertNull($restauree['body']['data']['parent_id']);
    }

    #[Test]
    public function un_parent_d_un_autre_espace_est_introuvable(): void
    {
        $alice = $this->bearer($this->register('alice@test.local')['token']);
        $bob   = $this->bearer($this->register('bob@test.local')['token']);

        $pageAlice = $this->page($alice, ['title' => 'Chez Alice']);

        $refus = $this->call('POST', '/api/docs', ['title' => 'Chez Bob', 'parent_id' => $pageAlice], $bob);

        $this->assertSame(422, $refus['status']);
        $this->assertSame(404, $this->call('GET', "/api/docs/{$pageAlice}", headers: $bob)['status']);
        $this->assertCount(0, $this->call('GET', '/api/docs', headers: $bob)['body']['data']);
    }

    /**
     * Le journal dit que le texte a changé, pas ce qu'il contenait : deux
     * copies d'une page de deux cent mille caractères par enregistrement
     * gonfleraient l'historique sans rien apprendre à personne.
     */
    #[Test]
    public function le_journal_ne_recopie_pas_le_texte(): void
    {
        $entete = $this->bearer($this->register('journal@test.local')['token']);

        $id = $this->page($entete, ['title' => 'Procédure', 'body' => 'Court.']);
        $this->call('PUT', "/api/docs/{$id}", ['body' => str_repeat('Long. ', 1000)], $entete);

        $changes = json_decode((string) Database::connection()->query(
            "SELECT changes FROM activity WHERE module = 'documentation' AND action = 'updated'",
        )->fetchColumn(), true);

        $this->assertSame(['6 caractères', '6000 caractères'], $changes['body']);
    }

    #[Test]
    public function le_texte_est_borne(): void
    {
        $entete = $this->bearer($this->register('borne@test.local')['token']);

        $refus = $this->call('POST', '/api/docs', ['title' => 'Trop long', 'body' => str_repeat('a', 200001)], $entete);

        $this->assertSame(422, $refus['status']);
        $this->assertArrayHasKey('body', $refus['body']['errors']);
    }

    /**
     * @param array<string, string> $entete
     * @param array<string, mixed>  $donnees
     */
    private function page(array $entete, array $donnees): string
    {
        $reponse = $this->call('POST', '/api/docs', $donnees, $entete);

        $this->assertSame(201, $reponse['status'], json_encode($reponse['body']) ?: '');

        return (string) $reponse['body']['data']['id'];
    }
}
