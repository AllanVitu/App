<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Terms;
use App\Core\Database;
use App\Core\UploadedFile;
use App\Services\FileStorage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;
use Tests\Support\Images;
use ZipArchive;

/**
 * « Télécharger mes données », et l'acceptation des nouvelles conditions.
 *
 * L'archive doit tout contenir de ce qui se rattache au compte — y compris les
 * fichiers déposés, octet pour octet —, rien de ce qui ouvrirait quelque chose,
 * et rien du travail des autres.
 */
final class DataExportTest extends ApiTestCase
{
    /** @var list<string> */
    private array $temporaires = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaires as $chemin) {
            @unlink($chemin);
        }

        parent::tearDown();
    }

    #[Test]
    public function l_archive_contient_ce_qui_se_rattache_au_compte(): void
    {
        $session = $this->register('export@test.local');
        $entete  = $this->bearer($session['token']);

        $ticket = $this->call('POST', '/api/tickets', ['title' => 'Écrit pour l’export'], $entete)['body']['data'];
        $this->call('POST', "/api/tickets/{$ticket['id']}/comments", ['body' => 'Mon commentaire exporté'], $entete);
        $this->call('POST', '/api/docs', ['title' => 'Ma procédure'], $entete);

        ['donnees' => $donnees, 'entrees' => $entrees] = $this->exporter($session['token']);

        $this->assertContains('donnees.json', $entrees);
        $this->assertContains('LISEZMOI.txt', $entrees);

        $this->assertSame('relais.export.v1', $donnees['format']);
        $this->assertSame('export@test.local', $donnees['compte']['email']);
        $this->assertSame('T', substr((string) $donnees['compte']['created_at'], 10, 1), 'des dates ISO 8601');

        $this->assertContains('Écrit pour l’export', array_column($donnees['contenus']['tickets_ecrits'], 'title'));
        $this->assertSame(['Mon commentaire exporté'], array_column($donnees['contenus']['commentaires'], 'body'));
        $this->assertContains('Ma procédure', array_column($donnees['contenus']['pages_de_documentation'], 'title'));

        $this->assertNotEmpty($donnees['espaces']);
        $this->assertNotEmpty($donnees['sessions']);
        $this->assertNotEmpty($donnees['historique']);
        $this->assertSame([], $donnees['fichiers']);
    }

    #[Test]
    public function les_fichiers_deposes_sont_dans_l_archive_octet_pour_octet(): void
    {
        $session = $this->register('fichiers-export@test.local');
        $entete  = $this->bearer($session['token']);

        $photo = $this->call('POST', '/api/profile/avatar', [], $entete, files: ['avatar' => $this->envoi(Images::png(12, 12))]);
        $this->assertSame(200, $photo['status']);

        $fichier = $this->call('POST', '/api/design/files', ['name' => 'Page d’accueil'], $entete)['body']['data']['id'];
        $version = $this->call(
            'POST',
            "/api/design/files/{$fichier}/versions",
            ['label' => 'Passe typographique'],
            $entete,
            files: ['file' => $this->envoi(Images::png(40, 30), 'accueil.png')],
        );
        $this->assertSame(201, $version['status']);

        ['zip' => $zip, 'donnees' => $donnees, 'entrees' => $entrees] = $this->exporter($session['token']);

        // Le numéro RÉEL de la version porteuse d'image : créer un fichier de
        // maquette en ouvre déjà une première, et l'archive nomme chaque image
        // d'après sa version — le test n'a pas à le deviner.
        $numero = $this->valeur(
            'SELECT v.number FROM design_versions v WHERE v.created_by = :id AND v.asset_id IS NOT NULL',
            $session['id'],
        );

        $attendus = [
            'fichiers/photo-de-profil.png' => $this->octetsSurLeDisque(
                'SELECT s.storage_key FROM users u JOIN stored_files s ON s.id = u.avatar_file_id WHERE u.id = :id',
                $session['id'],
            ),
            "fichiers/design/page-d-accueil/v{$numero}-passe-typographique.png" => $this->octetsSurLeDisque(
                'SELECT s.storage_key FROM design_versions v JOIN stored_files s ON s.id = v.asset_id WHERE v.created_by = :id',
                $session['id'],
            ),
        ];

        foreach ($attendus as $chemin => $octets) {
            $this->assertSame(
                $octets,
                $zip->getFromName($chemin),
                "{$chemin} : les octets stockés, à l'identique — l'archive contient : " . implode(', ', $entrees),
            );
        }

        // Et chacun se retrouve dans donnees.json, avec son chemin dans l'archive.
        $this->assertEqualsCanonicalizing(array_keys($attendus), array_column($donnees['fichiers'], 'chemin'));
        $this->assertSame([true, true], array_column($donnees['fichiers'], 'present'));
    }

    #[Test]
    public function l_archive_ne_contient_aucune_empreinte_de_secret(): void
    {
        $session = $this->register('secrets@test.local');

        ['donnees' => $donnees, 'entrees' => $entrees] = $this->exporter($session['token']);

        // Les CLÉS, et non le texte : une table Backend peut très bien décrire
        // une colonne « password_hash ». Ce qui ne doit jamais sortir, c'est
        // une empreinte elle-même.
        $cles    = [];
        $valeurs = [];

        array_walk_recursive($donnees, static function (mixed $valeur, int|string $cle) use (&$cles, &$valeurs): void {
            $cles[] = (string) $cle;

            if (is_string($valeur)) {
                $valeurs[] = $valeur;
            }
        });

        $this->assertNotContains('password_hash', $cles);
        $this->assertNotContains('token_hash', $cles);
        $this->assertSame([], preg_grep('/^\$2[aby]\$[0-9]{2}\$/', $valeurs));

        $this->assertSame([], array_values(array_filter(
            $entrees,
            static fn (string $entree): bool => !in_array($entree, ['donnees.json', 'LISEZMOI.txt'], true) && !str_starts_with($entree, 'fichiers/'),
        )));
    }

    #[Test]
    public function le_travail_des_autres_n_y_figure_pas(): void
    {
        $hote   = $this->register('hote-export@test.local');
        $membre = $this->membre($hote, 'collegue-export@test.local');

        $ecrit = $this->call('POST', '/api/tickets', [
            'title'       => 'Confié à l’hôte',
            'description' => 'Description écrite par la collègue',
            'assigned_to' => $hote['id'],
        ], $this->bearer($membre['token']));

        $this->assertSame(201, $ecrit['status'], json_encode($ecrit['body']) ?: '');

        // Une maquette publiée par la collègue, dans le même espace.
        $fichier = $this->call('POST', '/api/design/files', ['name' => 'Maquette de la collègue'], $this->bearer($membre['token']))['body']['data']['id'];
        $this->call('POST', "/api/design/files/{$fichier}/versions", [], $this->bearer($membre['token']), files: ['file' => $this->envoi(Images::png())]);

        ['donnees' => $donnees, 'entrees' => $entrees] = $this->exporter($hote['token']);

        // Le ticket confié y figure par son numéro, son titre et son état…
        $this->assertSame([$ecrit['body']['data']['number']], array_column($donnees['contenus']['tickets_attribues'], 'number'));
        $this->assertSame([], $donnees['contenus']['tickets_ecrits']);

        // …jamais par ce que quelqu'un d'autre y a écrit, ni par ses fichiers.
        $this->assertStringNotContainsString('Description écrite par la collègue', (string) json_encode($donnees));
        $this->assertSame([], array_values(array_filter($entrees, static fn (string $entree): bool => str_starts_with($entree, 'fichiers/'))));
    }

    #[Test]
    public function l_export_se_limite_a_dix_par_heure(): void
    {
        $entete = $this->bearer($this->register('frequence@test.local')['token']);

        for ($i = 1; $i <= 10; $i++) {
            $this->assertSame(200, $this->callRaw('GET', '/api/profile/export', [], $entete)['status'], "export n° {$i}");
        }

        $this->assertSame(429, $this->callRaw('GET', '/api/profile/export', [], $entete)['status']);
    }

    #[Test]
    public function les_nouvelles_conditions_s_acceptent_sans_choisir_leur_numero(): void
    {
        $session = $this->register('nouvelles-conditions@test.local');
        $entete  = $this->bearer($session['token']);

        Database::connection()
            ->prepare("UPDATE users SET terms_accepted_version = '1.0' WHERE id = :id")
            ->execute(['id' => $session['id']]);

        $this->assertSame(422, $this->call('POST', '/api/profile/terms', ['accepted' => false], $entete)['status']);

        // Un numéro envoyé par le client est ignoré : on accepte le texte publié.
        $acceptee = $this->call('POST', '/api/profile/terms', ['accepted' => true, 'version' => '9.9'], $entete);

        $this->assertSame(200, $acceptee['status']);
        $this->assertSame(Terms::CURRENT_VERSION, $acceptee['body']['data']['terms_version']);
    }

    #[Test]
    public function il_faut_etre_connecte(): void
    {
        $this->assertSame(401, $this->callRaw('GET', '/api/profile/export')['status']);
        $this->assertSame(401, $this->call('POST', '/api/profile/terms', ['accepted' => true])['status']);
    }

    /**
     * Télécharge l'archive et l'ouvre.
     *
     * @return array{zip: ZipArchive, donnees: array<string, mixed>, entrees: list<string>}
     */
    private function exporter(string $token): array
    {
        $reponse = $this->callRaw('GET', '/api/profile/export', [], $this->bearer($token));

        $this->assertSame(200, $reponse['status'], substr($reponse['output'], 0, 300));

        $chemin = (string) tempnam(sys_get_temp_dir(), 'export-test');
        file_put_contents($chemin, $reponse['output']);
        $this->temporaires[] = $chemin;

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($chemin) === true, 'une archive ZIP lisible');

        $json = $zip->getFromName('donnees.json');
        $this->assertIsString($json);

        $entrees = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entrees[] = (string) $zip->getNameIndex($i);
        }

        return [
            'zip'     => $zip,
            'donnees' => json_decode($json, true, 512, JSON_THROW_ON_ERROR),
            'entrees' => $entrees,
        ];
    }

    private function octetsSurLeDisque(string $sql, string $userId): string
    {
        return (string) file_get_contents((new FileStorage())->pathFor($this->valeur($sql, $userId)));
    }

    private function valeur(string $sql, string $userId): string
    {
        $statement = Database::connection()->prepare($sql);
        $statement->execute(['id' => $userId]);

        return (string) $statement->fetchColumn();
    }

    private function envoi(string $octets, string $nom = 'image.png'): UploadedFile
    {
        $chemin = (string) tempnam(sys_get_temp_dir(), 'envoi');
        file_put_contents($chemin, $octets);

        $this->temporaires[] = $chemin;

        return new UploadedFile($nom, $chemin, strlen($octets));
    }
}
