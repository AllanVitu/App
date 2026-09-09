<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\SchemaBuilder;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\ApiTestCase;

/**
 * Le module Backend crée de VRAIES tables.
 *
 * Il ne faisait que décrire : on posait des colonnes dans un JSONB, aucun
 * CREATE TABLE n'était jamais émis, rien n'était interrogeable. La promesse la
 * plus ambitieuse de l'application était aussi la plus creuse.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  CE FICHIER EXISTE D'ABORD POUR LES TESTS D'INJECTION.              │
 * │                                                                     │
 * │  Un nom de table ne peut PAS être passé en paramètre lié : PDO ne   │
 * │  lie que des valeurs. Il est donc concaténé — c'est la surface la   │
 * │  plus délicate de tout le projet. Les cas nominaux ci-dessous       │
 * │  seraient vite remarqués s'ils cassaient ; une barrière qui cède    │
 * │  en silence, non.                                                   │
 * └─────────────────────────────────────────────────────────────────────┘
 */
final class SchemaTest extends ApiTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function colonne(string $name, string $type = 'text', bool $nullable = true): array
    {
        return ['name' => $name, 'type' => $type, 'nullable' => $nullable];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function schemaMinimal(): array
    {
        return [$this->colonne('id', 'uuid', false), $this->colonne('email', 'text', false)];
    }

    // =====================================================================
    //  Injection
    // =====================================================================

    /**
     * Chaque chaîne ci-dessous est une tentative d'évasion réelle. Toutes
     * doivent lever AVANT d'atteindre la base — et « users » doit survivre.
     */
    #[Test]
    public function aucun_nom_de_table_hostile_n_atteint_la_base(): void
    {
        $session = $this->register('injection@test.local');
        $schema  = new SchemaBuilder();

        $comptesAvant = (int) Database::connection()->query('SELECT COUNT(*) FROM users')->fetchColumn();

        $hostiles = [
            'clients"; DROP TABLE users; --',
            'users" --',
            'clients; DELETE FROM users',
            'pg_catalog.pg_tables',
            'Clients',            // majuscule : refusée aussi, le motif est strict
            'clients espace',
            'accentué',
            '',
            '1clients',           // un identifiant SQL ne commence pas par un chiffre
        ];

        foreach ($hostiles as $nom) {
            try {
                $schema->sync($session['id'], $nom, $this->schemaMinimal());
                $this->fail("« {$nom} » aurait dû être refusé");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('refusé', $e->getMessage());
            }
        }

        $this->assertSame(
            $comptesAvant,
            (int) Database::connection()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'la table users doit être intacte',
        );
    }

    #[Test]
    public function aucun_type_hostile_n_atteint_la_base(): void
    {
        $session = $this->register('typehostile@test.local');
        $schema  = new SchemaBuilder();

        foreach (['text; DROP TABLE users', 'serial', 'TEXT', ''] as $type) {
            try {
                $schema->sync($session['id'], 't_essai', [$this->colonne('a', $type)]);
                $this->fail("le type « {$type} » aurait dû être refusé");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Type SQL refusé', $e->getMessage());
            }
        }
    }

    #[Test]
    public function un_nom_de_colonne_hostile_est_refuse(): void
    {
        $session = $this->register('colhostile@test.local');
        $schema  = new SchemaBuilder();

        $this->expectException(RuntimeException::class);

        $schema->sync($session['id'], 't_essai', [$this->colonne('a"; DROP TABLE users; --')]);
    }

    // =====================================================================
    //  Cloisonnement
    // =====================================================================

    /**
     * Le cloisonnement est STRUCTUREL : les tables d'un compte vivent dans son
     * propre schéma PostgreSQL. Il n'y a pas de clause WHERE à oublier, parce
     * qu'il n'y a rien à traverser.
     */
    #[Test]
    public function deux_comptes_peuvent_avoir_une_table_du_meme_nom_sans_se_voir(): void
    {
        $mien   = $this->register('mien-schema@test.local');
        $autrui = $this->register('autrui-schema@test.local');
        $schema = new SchemaBuilder();

        foreach ([$mien, $autrui] as $session) {
            $schema->sync($session['id'], 'clients', $this->schemaMinimal());
        }

        $schema->insert($mien['id'], 'clients', ['email' => 'a-moi@exemple.fr']);
        $schema->insert($autrui['id'], 'clients', ['email' => 'a-lui@exemple.fr']);

        $miennes  = $schema->rows($mien['id'], 'clients');
        $siennes  = $schema->rows($autrui['id'], 'clients');

        $this->assertSame(1, $miennes['total']);
        $this->assertSame(1, $siennes['total']);
        $this->assertSame('a-moi@exemple.fr', $miennes['rows'][0]['email']);
        $this->assertSame('a-lui@exemple.fr', $siennes['rows'][0]['email']);

        $this->assertNotSame($schema->schemaFor($mien['id']), $schema->schemaFor($autrui['id']));
    }

    // =====================================================================
    //  Structure
    // =====================================================================

    #[Test]
    public function une_table_decrite_devient_une_table_reelle(): void
    {
        $session = $this->register('reelle@test.local');
        $schema  = new SchemaBuilder();

        $this->assertFalse($schema->tableExists($session['id'], 'clients'));

        $schema->sync($session['id'], 'clients', $this->schemaMinimal());

        $this->assertTrue($schema->tableExists($session['id'], 'clients'));
        $this->assertSame(['id', 'email'], $schema->physicalColumns($session['id'], 'clients'));
    }

    /**
     * « id uuid » reçoit sa valeur par défaut : sans elle, chaque insertion
     * exigerait de fabriquer un identifiant côté client — ce que personne ne
     * devrait avoir à faire pour un prototype.
     */
    #[Test]
    public function la_colonne_id_se_remplit_toute_seule(): void
    {
        $session = $this->register('idauto@test.local');
        $schema  = new SchemaBuilder();

        $schema->sync($session['id'], 'clients', $this->schemaMinimal());

        $ligne = $schema->insert($session['id'], 'clients', ['email' => 'sans-id@exemple.fr']);

        $this->assertNotEmpty($ligne['id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $ligne['id']);
    }

    /**
     * LE TEST QUI PROTÈGE LES DONNÉES DES UTILISATEURS.
     *
     * Une colonne n'a pas d'identifiant stable : vu d'une liste, renommer
     * « email » en « courriel » ressemble à une suppression suivie d'un ajout.
     * Traité ainsi, le contenu de la colonne serait DÉTRUIT. Le renommage est
     * donc déduit du rang, que l'écran conserve en modifiant sur place.
     */
    #[Test]
    public function renommer_une_colonne_conserve_son_contenu(): void
    {
        $session = $this->register('renommage@test.local');
        $schema  = new SchemaBuilder();
        $avant   = $this->schemaMinimal();

        $schema->sync($session['id'], 'clients', $avant);
        $schema->insert($session['id'], 'clients', ['email' => 'a-conserver@exemple.fr']);

        $apres = [$this->colonne('id', 'uuid', false), $this->colonne('courriel', 'text', false)];
        $schema->sync($session['id'], 'clients', $apres, $avant);

        $lignes = $schema->rows($session['id'], 'clients');

        $this->assertSame(['id', 'courriel'], $schema->physicalColumns($session['id'], 'clients'));
        $this->assertSame('a-conserver@exemple.fr', $lignes['rows'][0]['courriel']);
    }

    #[Test]
    public function une_colonne_ajoutee_puis_retiree_suit_la_description(): void
    {
        $session = $this->register('colonnes@test.local');
        $schema  = new SchemaBuilder();
        $base    = $this->schemaMinimal();

        $schema->sync($session['id'], 'clients', $base);

        $avecActif = [...$base, $this->colonne('actif', 'boolean')];
        $schema->sync($session['id'], 'clients', $avecActif, $base);
        $this->assertContains('actif', $schema->physicalColumns($session['id'], 'clients'));

        $schema->sync($session['id'], 'clients', $base, $avecActif);
        $this->assertNotContains('actif', $schema->physicalColumns($session['id'], 'clients'));
    }

    // =====================================================================
    //  Données
    // =====================================================================

    /**
     * Une clé mal orthographiée est REFUSÉE, pas ignorée : l'ignorer ferait
     * croire à un enregistrement réussi alors que la valeur est perdue.
     */
    #[Test]
    public function une_colonne_inconnue_est_refusee_et_non_ignoree(): void
    {
        $session = $this->register('inconnue@test.local');
        $schema  = new SchemaBuilder();

        $schema->sync($session['id'], 'clients', $this->schemaMinimal());

        $reponse = $this->call(
            'POST',
            '/api/backend/data/clients',
            ['emial' => 'faute@exemple.fr'],
            $this->bearer($session['token']),
        );

        $this->assertSame(422, $reponse['status']);
        $this->assertStringContainsString('emial', $reponse['body']['message']);
    }

    #[Test]
    public function les_donnees_passent_par_l_api(): void
    {
        $session = $this->register('viaapi@test.local');
        $entete  = $this->bearer($session['token']);

        $table = $this->call('POST', '/api/backend/tables', [
            'name'    => 'commandes',
            'columns' => $this->schemaMinimal(),
        ], $entete);

        $this->assertSame(201, $table['status']);

        $creation = $this->call(
            'POST',
            '/api/backend/data/commandes',
            ['email' => 'client@exemple.fr'],
            $entete,
        );

        $this->assertSame(201, $creation['status']);
        $id = $creation['body']['data']['id'];

        $liste = $this->call('GET', '/api/backend/data/commandes', [], $entete);
        $this->assertSame(200, $liste['status']);
        $this->assertSame(1, $liste['body']['meta']['total']);

        $suppression = $this->call('DELETE', "/api/backend/data/commandes/{$id}", [], $entete);
        $this->assertSame(204, $suppression['status']);

        $vide = $this->call('GET', '/api/backend/data/commandes', [], $entete);
        $this->assertSame(0, $vide['body']['meta']['total']);
    }

    #[Test]
    public function une_table_absente_repond_404_et_non_une_erreur_postgresql(): void
    {
        $session = $this->register('absente@test.local');

        $reponse = $this->call(
            'GET',
            '/api/backend/data/nexiste_pas',
            [],
            $this->bearer($session['token']),
        );

        $this->assertSame(404, $reponse['status']);
        $this->assertStringContainsString('nexiste_pas', $reponse['body']['message']);
    }

    /**
     * Supprimer la description supprime AUSSI la table physique. Garder des
     * données inatteignables consommerait de l'espace en laissant croire qu'on
     * peut revenir en arrière.
     */
    #[Test]
    public function supprimer_une_table_supprime_ses_donnees(): void
    {
        $session = $this->register('suppression@test.local');
        $entete  = $this->bearer($session['token']);

        $table = $this->call('POST', '/api/backend/tables', [
            'name'    => 'ephemere',
            'columns' => $this->schemaMinimal(),
        ], $entete);

        $id     = $table['body']['data']['id'];
        $schema = new SchemaBuilder();

        $this->assertTrue($schema->tableExists($session['id'], 'ephemere'));

        $this->call('DELETE', "/api/backend/tables/{$id}", [], $entete);

        $this->assertFalse($schema->tableExists($session['id'], 'ephemere'));
    }
}
