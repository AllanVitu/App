<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Core\Migrator;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;

/**
 * Le migrateur.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  IL GOUVERNE TOUT CE QUI SUIVRA                                         │
 * │                                                                         │
 * │  Jusqu'ici, le schéma n'était créé qu'à la création du volume : passé   │
 * │  le premier déploiement, aucune évolution n'avait de chemin. Ce         │
 * │  migrateur est donc la pièce dont dépend chaque changement de structure │
 * │  à venir — organisations, file de tâches, colonnes de préférences.      │
 * │                                                                         │
 * │  Une pièce pareille se teste sur ses REFUS autant que sur son travail : │
 * │  ce qui coûte cher, ce n'est pas une migration qui échoue franchement,  │
 * │  c'est une base qui diverge du dépôt sans que personne ne le voie.      │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Les migrations sont écrites dans un répertoire temporaire : les vraies
 * vivent dans « database/migrations » et sont déjà appliquées sur la base de
 * test. Les rejouer ne prouverait rien et les casser casserait la suite.
 */
final class MigratorTest extends ApiTestCase
{
    private string $repertoire = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->repertoire = sys_get_temp_dir() . '/migrations_' . bin2hex(random_bytes(4));
        mkdir($this->repertoire);

        // Table d'état repartie de zéro : les tests décrivent une base neuve.
        Database::connection()->exec('DROP TABLE IF EXISTS schema_migrations');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->repertoire . '/*') ?: [] as $fichier) {
            unlink($fichier);
        }

        if (is_dir($this->repertoire)) {
            rmdir($this->repertoire);
        }

        Database::connection()->exec('DROP TABLE IF EXISTS recette_migration');

        parent::tearDown();
    }

    private function ecrire(string $nom, string $sql): void
    {
        file_put_contents($this->repertoire . '/' . $nom, $sql);
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->repertoire);
    }

    #[Test]
    public function applique_les_migrations_dans_l_ordre_des_versions(): void
    {
        // Écrites dans le désordre : c'est la VERSION qui ordonne, pas
        // l'ordre de création des fichiers ni celui du système de fichiers.
        $this->ecrire('202601020000_deuxieme.sql', 'ALTER TABLE recette_migration ADD COLUMN b int;');
        $this->ecrire('202601010000_premiere.sql', 'CREATE TABLE recette_migration (a int);');

        $faites = $this->migrator()->run();

        $this->assertSame(['202601010000', '202601020000'], $faites);

        // La seconde n'aurait pas pu s'appliquer avant la première.
        $colonnes = Database::connection()->query(
            "SELECT column_name FROM information_schema.columns
              WHERE table_name = 'recette_migration' ORDER BY column_name",
        )->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertSame(['a', 'b'], $colonnes);
    }

    #[Test]
    public function ne_rejoue_jamais_une_migration_appliquee(): void
    {
        $this->ecrire('202601010000_creation.sql', 'CREATE TABLE recette_migration (a int);');

        $this->assertCount(1, $this->migrator()->run());

        // Un second passage — le cas de CHAQUE redémarrage de conteneur. Sans
        // la table d'état, ce « CREATE TABLE » lèverait.
        $this->assertSame([], $this->migrator()->run());
    }

    #[Test]
    public function refuse_de_demarrer_si_une_migration_appliquee_a_ete_modifiee(): void
    {
        $this->ecrire('202601010000_creation.sql', 'CREATE TABLE recette_migration (a int);');
        $this->migrator()->run();

        // ┌─────────────────────────────────────────────────────────────────┐
        // │  L'ERREUR CLASSIQUE, ET CELLE QUI COÛTE LE PLUS CHER            │
        // │                                                                 │
        // │  On corrige une migration déjà partie en production. Elle ne    │
        // │  sera pas rejouée : le dépôt dit une chose, la base en contient │
        // │  une autre, et rien ne le signale — jusqu'au jour où une        │
        // │  requête échoue sur une colonne qui n'existe que sur les postes │
        // │  de développement.                                              │
        // └─────────────────────────────────────────────────────────────────┘
        $this->ecrire(
            '202601010000_creation.sql',
            'CREATE TABLE recette_migration (a int, b text);',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/modifiée après avoir été appliquée/');

        $this->migrator()->run();
    }

    #[Test]
    public function une_migration_echouee_ne_laisse_pas_un_schema_a_moitie_transforme(): void
    {
        // PostgreSQL sait annuler du DDL — c'est rare et c'est précieux : la
        // table créée par le premier ordre doit disparaître avec le second.
        $this->ecrire(
            '202601010000_bancale.sql',
            'CREATE TABLE recette_migration (a int); ORDRE QUI NE VEUT RIEN DIRE;',
        );

        try {
            $this->migrator()->run();
            $this->fail('la migration aurait dû échouer');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('202601010000', $e->getMessage());
        }

        $existe = Database::connection()->query(
            "SELECT to_regclass('recette_migration') IS NOT NULL",
        )->fetchColumn();

        $this->assertFalse((bool) $existe, 'la table ne devait pas survivre à l’échec');

        // Et rien n'a été inscrit : la migration reste en attente, donc
        // rejouable une fois corrigée.
        $etat = $this->migrator()->status();
        $this->assertNull($etat[0]['applied_at']);
    }

    #[Test]
    public function garde_la_trace_de_ce_qui_a_ete_applique(): void
    {
        $this->ecrire('202601010000_creation.sql', 'CREATE TABLE recette_migration (a int);');
        $this->migrator()->run();

        $trace = Database::connection()
            ->query('SELECT * FROM schema_migrations WHERE version = \'202601010000\'')
            ->fetch();

        $this->assertSame('creation', $trace['name']);
        $this->assertSame(64, strlen((string) $trace['checksum']));
        $this->assertGreaterThanOrEqual(0, (int) $trace['duration_ms']);
    }

    #[Test]
    public function l_etat_distingue_ce_qui_est_applique_de_ce_qui_attend(): void
    {
        $this->ecrire('202601010000_creation.sql', 'CREATE TABLE recette_migration (a int);');
        $this->migrator()->run();
        $this->ecrire('202601020000_suite.sql', 'ALTER TABLE recette_migration ADD COLUMN b int;');

        $etat = $this->migrator()->status();

        $this->assertCount(2, $etat);
        $this->assertNotNull($etat[0]['applied_at']);
        $this->assertNull($etat[1]['applied_at'], 'la seconde est en attente');
    }

    #[Test]
    public function refuse_un_fichier_qui_gere_sa_propre_transaction(): void
    {
        // ┌─────────────────────────────────────────────────────────────────┐
        // │  CE TEST VIENT D'UN INCIDENT, PAS D'UNE PRÉCAUTION              │
        // │                                                                 │
        // │  La première migration écrite pour ce projet portait un         │
        // │  BEGIN/COMMIT, par mimétisme avec les fichiers de « init/ » que │
        // │  PostgreSQL joue directement. Le résultat n'a pas été une       │
        // │  erreur propre : le COMMIT du fichier a validé la transaction   │
        // │  DU MIGRATEUR, les tables ont été créées, la ligne d'état       │
        // │  écrite hors transaction — puis « commit() » a levé sur une     │
        // │  transaction déjà close.                                        │
        // │                                                                 │
        // │  La base s'est donc retrouvée avec la migration appliquée ET    │
        // │  marquée en échec. C'est précisément le demi-état que           │
        // │  l'atomicité annoncée doit interdire.                           │
        // └─────────────────────────────────────────────────────────────────┘
        $this->ecrire(
            '202601010000_avec_transaction.sql',
            "BEGIN;\nCREATE TABLE recette_migration (a int);\nCOMMIT;\n",
        );

        try {
            $this->migrator()->run();
            $this->fail('le fichier aurait dû être refusé');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('retirez les BEGIN / COMMIT', $e->getMessage());
        }

        // Refusé AVANT d'exécuter quoi que ce soit : rien n'a été créé, rien
        // n'a été inscrit. C'est ce qui distingue un refus d'un demi-état.
        $existe = Database::connection()->query(
            "SELECT to_regclass('recette_migration') IS NOT NULL",
        )->fetchColumn();

        $this->assertFalse((bool) $existe);
        $this->assertNull($this->migrator()->status()[0]['applied_at']);
    }

    #[Test]
    public function laisse_passer_un_fichier_qui_decline_la_transaction(): void
    {
        // Certains ordres la refusent — CREATE INDEX CONCURRENTLY en tête.
        // Le fichier le déclare, et prend alors ses responsabilités.
        $this->ecrire(
            '202601010000_sans_transaction.sql',
            "-- @sans-transaction\nCREATE TABLE recette_migration (a int);\n",
        );

        $this->assertCount(1, $this->migrator()->run());
    }

    #[Test]
    public function refuse_un_nom_de_fichier_sans_version(): void
    {
        // Sans version en tête, l'ordre d'application dépendrait de l'ordre du
        // système de fichiers — c'est-à-dire de rien de fiable.
        $this->ecrire('ajout_colonne.sql', 'SELECT 1;');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/doit commencer par un horodatage/');

        $this->migrator()->run();
    }

    #[Test]
    public function ne_bloque_pas_sur_une_migration_absente_du_depot(): void
    {
        $this->ecrire('202601010000_creation.sql', 'CREATE TABLE recette_migration (a int);');
        $this->migrator()->run();

        // La branche courante n'a pas encore ce fichier — cas normal quand on
        // revient en arrière. La base, elle, a bien l'état correspondant : il
        // n'y a rien à faire, et surtout rien à refuser.
        unlink($this->repertoire . '/202601010000_creation.sql');

        $this->assertSame([], $this->migrator()->run());
    }

    #[Test]
    public function un_repertoire_vide_est_un_cas_normal(): void
    {
        $this->assertSame([], $this->migrator()->run());
        $this->assertSame([], $this->migrator()->status());
    }
}
