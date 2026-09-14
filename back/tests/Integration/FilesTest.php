<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\FileController;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\UploadedFile;
use App\Services\FileStorage;
use App\Services\SignedUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApiTestCase;
use Tests\Support\Images;

/**
 * Des fichiers réels : photos de profil et images de versions.
 *
 * La moitié de ce fichier vérifie ce qui ne passe PAS ou ne RESTE pas — un
 * script déguisé en image, une position GPS, une photo remplacée, le visage
 * d'un compte effacé. C'est là que se jouent la sécurité et le droit à
 * l'effacement ; le cas nominal, lui, se voit tout seul à l'écran.
 */
final class FilesTest extends ApiTestCase
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

    // -----------------------------------------------------------------------
    //  Photo de profil
    // -----------------------------------------------------------------------

    #[Test]
    public function une_photo_perd_sa_position_gps_et_se_sert_par_une_adresse_signee(): void
    {
        $compte = $this->register();
        $photo  = Images::jpeg(64, 64, [[0xE1, "Exif\0\0GPSLatitude 48.8566 N"]]);

        $reponse = $this->televerserAvatar($compte['token'], $photo, 'moi.jpg');

        $this->assertSame(200, $reponse['status']);

        $adresse = (string) $reponse['body']['data']['avatar_url'];
        $this->assertMatchesRegularExpression('#^/api/files/[0-9a-f-]{36}\?expires=\d+&signature=[\w-]+$#', $adresse);

        $rangee = $this->octetsRanges($this->identifiant($adresse));
        $this->assertStringNotContainsString('GPSLatitude', $rangee);

        $servie = $this->suivre($adresse);
        $this->assertSame(200, $servie['status']);
        $this->assertSame($rangee, $servie['output']);

        // L'équipe voit la même photo, par sa propre adresse signée.
        $membres = $this->call('GET', '/api/organizations/members', [], $this->bearer($compte['token']));
        $this->assertSame(
            $this->identifiant($adresse),
            $this->identifiant((string) $membres['body']['data'][0]['avatar_url']),
        );
    }

    #[Test]
    public function changer_de_photo_efface_l_ancienne_jusque_sur_le_disque(): void
    {
        $compte = $this->register();

        $premiere = $this->identifiant((string) $this->televerserAvatar($compte['token'], Images::png(8, 8))['body']['data']['avatar_url']);
        $cheminPremiere = (new FileStorage())->pathFor($this->cle($premiere));

        $seconde = $this->identifiant((string) $this->televerserAvatar($compte['token'], Images::png(9, 9))['body']['data']['avatar_url']);

        $this->assertSame(1, $this->compter('SELECT count(*) FROM stored_files'));
        $this->assertSame(1, $this->compter('SELECT count(*) FROM stored_file_tombstones'));
        $this->assertFileExists($cheminPremiere, 'les octets partent avec la purge, pas avant');

        (new FileStorage())->purge();

        $this->assertFileDoesNotExist($cheminPremiere);
        $this->assertFileExists((new FileStorage())->pathFor($this->cle($seconde)));
        $this->assertSame(0, $this->compter('SELECT count(*) FROM stored_file_tombstones'));
    }

    #[Test]
    public function retirer_sa_photo_rend_les_initiales_et_libere_le_disque(): void
    {
        $compte = $this->register();
        $this->televerserAvatar($compte['token'], Images::png());

        $reponse = $this->call('DELETE', '/api/profile/avatar', [], $this->bearer($compte['token']));

        $this->assertSame(200, $reponse['status']);
        $this->assertNull($reponse['body']['data']['avatar_url']);
        $this->assertSame(0, $this->compter('SELECT count(*) FROM stored_files'));
        $this->assertSame(1, $this->compter('SELECT count(*) FROM stored_file_tombstones'));
    }

    /**
     * Le cas qui justifie les pierres tombales : la suppression passe par une
     * CASCADE, où aucun code PHP ne s'exécute. Sans le déclencheur, le visage
     * d'un compte effacé resterait sur le disque.
     */
    #[Test]
    public function supprimer_son_compte_emporte_sa_photo_jusque_sur_le_disque(): void
    {
        $compte = $this->register();
        $id     = $this->identifiant((string) $this->televerserAvatar($compte['token'], Images::png())['body']['data']['avatar_url']);
        $chemin = (new FileStorage())->pathFor($this->cle($id));

        $suppression = $this->call('DELETE', '/api/profile', ['password' => 'Motdepasse1'], $this->bearer($compte['token']));
        $this->assertSame(204, $suppression['status']);

        (new FileStorage())->purge();

        $this->assertFileDoesNotExist($chemin);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function deguisements(): iterable
    {
        yield 'SVG porteur de script, nommé en PNG' => [
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>',
            'photo.png',
        ];
        yield 'script PHP, nommé en JPEG' => ['<?php system($_GET["c"]); ?>', 'avatar.jpg'];
        yield 'page HTML, nommée en WebP' => ['<!doctype html><script>fetch("/api/auth/me")</script>', 'moi.webp'];
    }

    #[Test]
    #[DataProvider('deguisements')]
    public function un_fichier_deguise_en_image_est_refuse_et_rien_n_est_range(string $contenu, string $nom): void
    {
        $compte  = $this->register();
        $reponse = $this->televerserAvatar($compte['token'], $contenu, $nom);

        $this->assertSame(422, $reponse['status']);
        $this->assertStringContainsString('Format refusé', (string) $reponse['body']['errors']['avatar']);
        $this->assertSame(0, $this->compter('SELECT count(*) FROM stored_files'));
    }

    #[Test]
    public function une_image_endommagee_est_refusee(): void
    {
        $compte = $this->register();

        // Un PNG que la détection de type RECONNAÎT — signature et en-tête
        // intacts — mais dont la structure s'arrête avant son bloc de fin.
        //
        // Une signature suivie d'octets quelconques ne suffisait pas : sans
        // bloc d'en-tête, libmagic n'y voit pas un PNG, et le fichier était
        // refusé plus tôt, comme format inconnu. Le test ne vérifiait donc
        // pas ce qu'il annonçait.
        $reponse = $this->televerserAvatar($compte['token'], substr(Images::png(8, 8), 0, -12));

        $this->assertSame(422, $reponse['status']);
        $this->assertStringContainsString('endommagé', (string) $reponse['body']['errors']['avatar']);
    }

    #[Test]
    public function une_photo_trop_lourde_est_refusee_avant_d_etre_lue(): void
    {
        $compte  = $this->register();
        $reponse = $this->televerserAvatar($compte['token'], str_repeat('a', 2 * 1024 * 1024 + 1));

        $this->assertSame(422, $reponse['status']);
        $this->assertStringContainsString('dépasse 2 Mo', (string) $reponse['body']['errors']['avatar']);
    }

    #[Test]
    public function le_nom_envoye_ne_choisit_jamais_le_chemin(): void
    {
        $compte = $this->register();
        $this->televerserAvatar($compte['token'], Images::png(), '../../etc/passwd.png');

        $ligne = Database::connection()->query('SELECT storage_key, original_name FROM stored_files')->fetch();

        $this->assertIsArray($ligne);
        $this->assertSame('passwd.png', $ligne['original_name']);
        $this->assertMatchesRegularExpression('#^[a-f0-9]{2}/[a-f0-9]{2}/[a-f0-9]{32}$#', (string) $ligne['storage_key']);
    }

    #[Test]
    public function le_changement_de_photo_est_plafonne(): void
    {
        $compte = $this->register();

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(200, $this->televerserAvatar($compte['token'], Images::png())['status']);
        }

        $this->assertSame(429, $this->televerserAvatar($compte['token'], Images::png())['status']);
    }

    // -----------------------------------------------------------------------
    //  Adresses signées
    // -----------------------------------------------------------------------

    #[Test]
    public function un_lien_altere_expire_ou_detourne_ne_sert_rien(): void
    {
        $compte  = $this->register();
        $adresse = (string) $this->televerserAvatar($compte['token'], Images::png())['body']['data']['avatar_url'];
        $id      = $this->identifiant($adresse);

        parse_str((string) parse_url($adresse, PHP_URL_QUERY), $query);
        $expires   = (string) $query['expires'];
        $signature = (string) $query['signature'];

        $refus = [
            'signature altérée' => $this->callRaw('GET', "/api/files/{$id}", query: ['expires' => $expires, 'signature' => strrev($signature)]),
            'autre fichier'     => $this->callRaw('GET', '/api/files/7c9e6679-7425-40de-944b-e07fc1f90ae7', query: ['expires' => $expires, 'signature' => $signature]),
            'échéance repoussée' => $this->callRaw('GET', "/api/files/{$id}", query: ['expires' => (string) ((int) $expires + 86_400), 'signature' => $signature]),
            'lien expiré'       => $this->suivre(SignedUrl::forFile($id, time() - 3 * 86_400)),
            'aucun paramètre'   => $this->callRaw('GET', "/api/files/{$id}"),
        ];

        foreach ($refus as $cas => $reponse) {
            $this->assertSame(403, $reponse['status'], $cas);
            $this->assertStringNotContainsString("\x89PNG", $reponse['output'], $cas);
        }
    }

    #[Test]
    public function les_en_tetes_empechent_le_fichier_de_devenir_autre_chose_qu_une_image(): void
    {
        $entetes = FileController::headersFor([
            'media_type'    => 'image/png',
            'byte_size'     => 42,
            'original_name' => 'Écran d’accueil.png',
            'sha256'        => str_repeat('a', 64),
        ], 10_000, 6_400);

        $this->assertSame('image/png', $entetes['Content-Type']);
        $this->assertSame('nosniff', $entetes['X-Content-Type-Options']);
        $this->assertStringContainsString('sandbox', $entetes['Content-Security-Policy']);
        $this->assertStringContainsString("default-src 'none'", $entetes['Content-Security-Policy']);
        $this->assertSame('private, max-age=3600', $entetes['Cache-Control']);
        $this->assertSame('same-site', $entetes['Cross-Origin-Resource-Policy']);
        $this->assertStringContainsString("filename*=UTF-8''%C3%89cran", $entetes['Content-Disposition']);
        $this->assertStringNotContainsString('’', explode(';', $entetes['Content-Disposition'])[1]);
    }

    // -----------------------------------------------------------------------
    //  Versions de design
    // -----------------------------------------------------------------------

    #[Test]
    public function une_version_porte_son_image_et_donne_son_apercu_au_fichier(): void
    {
        $compte  = $this->register();
        $fichier = $this->creerFichierDesign($compte['token']);

        $reponse = $this->call(
            'POST',
            "/api/design/files/{$fichier}/versions",
            ['label' => 'Maquette validée'],
            $this->bearer($compte['token']),
            files: ['file' => $this->envoi(Images::png(40, 30, ['tEXt' => "Author\0Alice Martin"]), 'accueil.png')],
        );

        $this->assertSame(201, $reponse['status']);

        $image = $reponse['body']['data']['asset'];
        $this->assertSame('image/png', $image['media_type']);
        $this->assertSame([40, 30], [$image['width'], $image['height']]);
        $this->assertSame('accueil.png', $image['name']);

        $servie = $this->suivre((string) $image['url']);
        $this->assertSame(200, $servie['status']);
        $this->assertStringNotContainsString('Alice Martin', $servie['output']);

        $liste = $this->call('GET', '/api/design/files', [], $this->bearer($compte['token']));
        $this->assertSame(
            $this->identifiant((string) $image['url']),
            $this->identifiant((string) $liste['body']['data'][0]['preview_url']),
        );
    }

    #[Test]
    public function une_version_sans_image_ne_fait_pas_disparaitre_l_apercu(): void
    {
        $compte  = $this->register();
        $fichier = $this->creerFichierDesign($compte['token']);
        $entetes = $this->bearer($compte['token']);

        $this->call('POST', "/api/design/files/{$fichier}/versions", [], $entetes, files: ['file' => $this->envoi(Images::png())]);
        $this->call('POST', "/api/design/files/{$fichier}/versions", ['notes' => 'Couleurs revues à l’oral'], $entetes);

        $detail = $this->call('GET', "/api/design/files/{$fichier}", [], $entetes)['body']['data'];

        $this->assertNotNull($detail['preview_url']);
        $this->assertNull($detail['history'][0]['asset'], 'la dernière version ne porte qu’une note');
        $this->assertNotNull($detail['history'][1]['asset']);
    }

    #[Test]
    public function un_autre_espace_ne_peut_pas_joindre_d_image_au_fichier(): void
    {
        $alice   = $this->register('alice@test.local');
        $bob     = $this->register('bob@test.local');
        $fichier = $this->creerFichierDesign($alice['token']);

        $reponse = $this->call(
            'POST',
            "/api/design/files/{$fichier}/versions",
            [],
            $this->bearer($bob['token']),
            files: ['file' => $this->envoi(Images::png())],
        );

        $this->assertSame(404, $reponse['status']);
        $this->assertSame(0, $this->compter('SELECT count(*) FROM stored_files'), 'rien ne doit être rangé avant le refus');
    }

    #[Test]
    public function le_quota_d_un_espace_est_tenu(): void
    {
        $compte  = $this->register();
        $stockage = new FileStorage(quotaBytes: 100);

        $stockage->store($this->envoi(Images::png(2, 2)), 'design', $compte['org'], null, $compte['id']);

        try {
            $stockage->store($this->envoi(Images::png(2, 2)), 'design', $compte['org'], null, $compte['id']);
            $this->fail('le second fichier aurait dû dépasser le quota');
        } catch (HttpException $refus) {
            $this->assertSame(422, $refus->getStatus());
            $this->assertStringContainsString('limite de stockage', $refus->getErrors()['file']);
        }

        $this->assertSame(1, $this->compter('SELECT count(*) FROM stored_files'));
    }

    // -----------------------------------------------------------------------

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function televerserAvatar(string $token, string $octets, string $nom = 'photo.png'): array
    {
        return $this->call('POST', '/api/profile/avatar', [], $this->bearer($token), files: [
            'avatar' => $this->envoi($octets, $nom),
        ]);
    }

    private function envoi(string $octets, string $nom = 'image.png'): UploadedFile
    {
        $chemin = (string) tempnam(sys_get_temp_dir(), 'envoi');
        file_put_contents($chemin, $octets);

        $this->temporaires[] = $chemin;

        return new UploadedFile($nom, $chemin, strlen($octets));
    }

    private function creerFichierDesign(string $token): string
    {
        $reponse = $this->call('POST', '/api/design/files', ['name' => 'Accueil'], $this->bearer($token));
        $this->assertSame(201, $reponse['status']);

        return (string) $reponse['body']['data']['id'];
    }

    /**
     * Suit une adresse signée comme le ferait une balise <img>.
     *
     * @return array{status: int, output: string}
     */
    private function suivre(string $adresse): array
    {
        parse_str((string) parse_url($adresse, PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        return $this->callRaw('GET', (string) parse_url($adresse, PHP_URL_PATH), query: $query);
    }

    private function identifiant(string $adresse): string
    {
        $this->assertMatchesRegularExpression('#/api/files/([0-9a-f-]{36})#', $adresse);
        preg_match('#/api/files/([0-9a-f-]{36})#', $adresse, $trouve);

        return $trouve[1];
    }

    private function cle(string $fichierId): string
    {
        $statement = Database::connection()->prepare('SELECT storage_key FROM stored_files WHERE id = :id');
        $statement->execute(['id' => $fichierId]);

        return (string) $statement->fetchColumn();
    }

    private function octetsRanges(string $fichierId): string
    {
        return (string) file_get_contents((new FileStorage())->pathFor($this->cle($fichierId)));
    }

    private function compter(string $sql): int
    {
        return (int) Database::connection()->query($sql)->fetchColumn();
    }
}
