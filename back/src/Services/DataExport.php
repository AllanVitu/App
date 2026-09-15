<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;
use Transliterator;
use ZipArchive;

/**
 * « Télécharger mes données » — droit d'accès (art. 15) et de portabilité
 * (art. 20) du RGPD.
 *
 * Ce que le fichier contient : tout ce qui se rattache à la personne — son
 * compte, ses préférences, ses espaces, ses sessions, ses tentatives de
 * connexion, ce qu'elle a écrit ou créé, ce qu'elle a fait.
 *
 * Ce qu'il ne contient JAMAIS : une empreinte de mot de passe, de jeton ou de
 * clé d'API. Un export se télécharge, se transmet, s'oublie dans un dossier :
 * il ne doit rien ouvrir. Un test cherche ces colonnes dans le fichier.
 *
 * Ce qu'il ne contient pas non plus : le travail des AUTRES. Un ticket
 * simplement attribué à la personne n'y figure que par son numéro, son titre
 * et son état — sa description appartient à qui l'a écrite.
 */
final class DataExport
{
    /** Colonnes JSON, relues comme des structures et non comme du texte. */
    private const JSON = ['notifications', 'preferences', 'labels', 'columns'];

    /** Colonnes booléennes, que le pilote rend en « t » / « f ». */
    private const BOOLEENS = ['successful', 'reduce_motion'];

    /**
     * @return array<string, mixed>
     */
    public function forUser(string $userId): array
    {
        return [
            'format'    => 'relais.export.v1',
            'genere_le' => gmdate('Y-m-d\TH:i:s\Z'),

            'compte' => $this->premiere(
                'SELECT id, email, full_name, role, email_verified_at, last_login_at, created_at, updated_at,
                        terms_accepted_at, terms_accepted_version, two_factor_enabled_at
                   FROM users WHERE id = :id',
                $userId,
            ),

            'preferences' => $this->premiere(
                'SELECT theme, language, timezone, density, reduce_motion, notifications, preferences, updated_at
                   FROM user_settings WHERE user_id = :id',
                $userId,
            ),

            'photo' => $this->premiere(
                'SELECT f.media_type, f.byte_size, f.width, f.height, f.created_at
                   FROM users u JOIN stored_files f ON f.id = u.avatar_file_id
                  WHERE u.id = :id',
                $userId,
            ),

            'espaces' => $this->lignes(
                'SELECT o.name AS espace, m.role, m.created_at AS membre_depuis
                   FROM memberships m JOIN organizations o ON o.id = m.organization_id
                  WHERE m.user_id = :id
                  ORDER BY m.created_at',
                $userId,
            ),

            'sessions' => $this->lignes(
                'SELECT created_at, expires_at, revoked_at, ip_address::text AS ip_address, user_agent
                   FROM refresh_tokens WHERE user_id = :id
                  ORDER BY created_at DESC',
                $userId,
            ),

            'tentatives_de_connexion' => $this->lignes(
                'SELECT attempted_at, action, successful, ip_address::text AS ip_address
                   FROM login_attempts
                  WHERE email = (SELECT email FROM users WHERE id = :id)
                  ORDER BY attempted_at DESC',
                $userId,
            ),

            'invitations_envoyees' => $this->lignes(
                'SELECT o.name AS espace, i.email::text AS email, i.role, i.created_at, i.expires_at, i.accepted_at
                   FROM invitations i JOIN organizations o ON o.id = i.organization_id
                  WHERE i.invited_by = :id
                  ORDER BY i.created_at DESC',
                $userId,
            ),

            'contenus' => [
                'tickets_ecrits' => $this->lignes(
                    'SELECT o.name AS espace, t.number, t.title, t.description, t.status, t.priority, t.project,
                            to_jsonb(t.labels) AS labels, t.due_date, t.created_at, t.updated_at, t.deleted_at
                       FROM tickets t JOIN organizations o ON o.id = t.organization_id
                      WHERE t.created_by = :id
                      ORDER BY t.created_at',
                    $userId,
                ),
                'tickets_attribues' => $this->lignes(
                    'SELECT o.name AS espace, t.number, t.title, t.status
                       FROM tickets t JOIN organizations o ON o.id = t.organization_id
                      WHERE t.assigned_to = :id AND t.deleted_at IS NULL
                      ORDER BY t.number',
                    $userId,
                ),
                'commentaires' => $this->lignes(
                    'SELECT o.name AS espace, t.number AS ticket, c.body, c.created_at, c.deleted_at
                       FROM ticket_comments c
                       JOIN tickets t ON t.id = c.ticket_id
                       JOIN organizations o ON o.id = c.organization_id
                      WHERE c.author_id = :id
                      ORDER BY c.created_at',
                    $userId,
                ),
                'pages_de_documentation' => $this->lignes(
                    'SELECT o.name AS espace, p.title, p.body, p.created_at, p.updated_at, p.deleted_at
                       FROM doc_pages p JOIN organizations o ON o.id = p.organization_id
                      WHERE p.created_by = :id OR p.updated_by = :id
                      ORDER BY p.created_at',
                    $userId,
                ),
                'deploiements' => $this->lignes(
                    'SELECT o.name AS espace, d.environment, d.branch, d.commit_sha, d.commit_message, d.status,
                            d.created_at, d.deleted_at
                       FROM deployments d JOIN organizations o ON o.id = d.organization_id
                      WHERE d.created_by = :id
                      ORDER BY d.created_at',
                    $userId,
                ),
                'fichiers_de_design' => $this->lignes(
                    'SELECT o.name AS espace, f.name, f.kind, f.description, f.created_at, f.deleted_at
                       FROM design_files f JOIN organizations o ON o.id = f.organization_id
                      WHERE f.created_by = :id
                      ORDER BY f.created_at',
                    $userId,
                ),
                'versions_de_design' => $this->lignes(
                    'SELECT f.name AS fichier, v.number, v.label, v.notes, v.created_at
                       FROM design_versions v JOIN design_files f ON f.id = v.file_id
                      WHERE v.created_by = :id
                      ORDER BY v.created_at',
                    $userId,
                ),
                'sondes' => $this->lignes(
                    'SELECT o.name AS espace, p.name, p.url, p.method, p.interval_seconds, p.created_at, p.deleted_at
                       FROM probes p JOIN organizations o ON o.id = p.organization_id
                      WHERE p.created_by = :id
                      ORDER BY p.created_at',
                    $userId,
                ),
                'tables_backend' => $this->lignes(
                    'SELECT o.name AS espace, b.name, b.description, b.columns, b.created_at, b.deleted_at
                       FROM backend_tables b JOIN organizations o ON o.id = b.organization_id
                      WHERE b.created_by = :id
                      ORDER BY b.created_at',
                    $userId,
                ),
                // Le préfixe seul, jamais l'empreinte : il suffit à reconnaître
                // une clé, et ne permet pas de la reconstituer.
                'cles_api' => $this->lignes(
                    'SELECT o.name AS espace, k.label, k.scope, k.token_prefix, k.last_used_at, k.revoked_at, k.created_at
                       FROM backend_api_keys k JOIN organizations o ON o.id = k.organization_id
                      WHERE k.created_by = :id
                      ORDER BY k.created_at',
                    $userId,
                ),
                'elements' => $this->lignes(
                    'SELECT o.name AS espace, i.title, i.description, i.status, i.created_at, i.deleted_at
                       FROM module_items i JOIN organizations o ON o.id = i.organization_id
                      WHERE i.created_by = :id
                      ORDER BY i.created_at',
                    $userId,
                ),
            ],

            'historique' => $this->lignes(
                'SELECT o.name AS espace, a.module, a.action, a.subject_ref, a.subject_title, a.happened_at
                   FROM activity a JOIN organizations o ON o.id = a.organization_id
                  WHERE a.actor_id = :id
                  ORDER BY a.happened_at DESC',
                $userId,
            ),
        ];
    }

    /**
     * L'archive remise à la personne : ses données en JSON, et les fichiers
     * qu'elle a DÉPOSÉS — sa photo, les images des versions de maquettes
     * qu'elle a publiées.
     *
     * Ces fichiers sont des données qu'elle a fournies elle-même : la
     * portabilité (RGPD, art. 20) les couvre, pas seulement leur description.
     * Chacun figure aussi dans donnees.json, avec son chemin dans l'archive — et
     * « present: false » si ses octets manquent sur le disque, plutôt que de
     * l'omettre sans le dire.
     *
     * Renvoie le chemin d'un fichier TEMPORAIRE : à l'appelant de l'effacer une
     * fois envoyé, puisqu'il contient toutes les données du compte.
     */
    public function archive(string $userId): string
    {
        $stockage = new FileStorage();
        $fichiers = [];
        $pris     = [];

        foreach ($this->fichiersDeposes($userId) as $ligne) {
            $source = $stockage->pathFor($ligne['storage_key']);

            $fichiers[] = [
                'chemin'  => $this->nomLibre($this->nomDansArchive($ligne), $pris),
                'origine' => $ligne['origine'],
                'type'    => $ligne['media_type'],
                'taille'  => $ligne['byte_size'],
                'present' => is_file($source),
                'source'  => $source,
            ];
        }

        $donnees = $this->forUser($userId);
        $donnees['fichiers'] = array_map(
            static fn (array $fichier): array => array_diff_key($fichier, ['source' => true]),
            $fichiers,
        );

        $chemin = tempnam(sys_get_temp_dir(), 'relais-export-');
        $zip    = new ZipArchive();

        if ($chemin === false || $zip->open($chemin, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Archive d\'export impossible à créer.');
        }

        $zip->addFromString(
            'donnees.json',
            json_encode($donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        $zip->addFromString('LISEZMOI.txt', self::LISEZMOI);

        foreach ($fichiers as $fichier) {
            if (!$fichier['present']) {
                continue;
            }

            $zip->addFile($fichier['source'], $fichier['chemin']);
            // Des images, déjà compressées : les recompresser coûterait du
            // temps sans rien faire gagner.
            $zip->setCompressionName($fichier['chemin'], ZipArchive::CM_STORE);
        }

        if (!$zip->close()) {
            @unlink($chemin);

            throw new RuntimeException('Archive d\'export impossible à écrire.');
        }

        return $chemin;
    }

    private const LISEZMOI = <<<'TXT'
        Vos données Relais
        ==================

        donnees.json   Tout ce qui se rattache à votre compte : profil, préférences,
                       espaces, sessions, tentatives de connexion, invitations
                       envoyées, contenus créés, historique. Dates en ISO 8601 (UTC).
        fichiers/      Les fichiers que vous avez déposés : votre photo de profil et
                       les images des versions de maquettes que vous avez publiées.

        Aucune empreinte de mot de passe, de jeton ou de clé n'y figure : cette
        archive ne permet d'ouvrir aucune session.

        Elle contient en revanche vos données personnelles. Conservez-la en lieu
        sûr, et supprimez-la quand vous n'en avez plus besoin.
        TXT;

    private const EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /**
     * @return list<array{origine: string, storage_key: string, media_type: string, byte_size: int, fichier: ?string, numero: ?int, libelle: ?string}>
     */
    private function fichiersDeposes(string $userId): array
    {
        $statement = Database::connection()->prepare(
            "SELECT 'photo' AS origine, s.storage_key, s.media_type, s.byte_size,
                    NULL::text AS fichier, NULL::integer AS numero, NULL::text AS libelle
               FROM users u
               JOIN stored_files s ON s.id = u.avatar_file_id
              WHERE u.id = :id
             UNION ALL
             SELECT 'design', s.storage_key, s.media_type, s.byte_size, f.name, v.number, v.label
               FROM design_versions v
               JOIN design_files f ON f.id = v.file_id
               JOIN stored_files s ON s.id = v.asset_id
              WHERE v.created_by = :id
              ORDER BY 1, 5, 6",
        );
        $statement->execute(['id' => $userId]);

        return array_map(
            static fn (array $ligne): array => [
                'origine'     => (string) $ligne['origine'],
                'storage_key' => (string) $ligne['storage_key'],
                'media_type'  => (string) $ligne['media_type'],
                'byte_size'   => (int) $ligne['byte_size'],
                'fichier'     => $ligne['fichier'] !== null ? (string) $ligne['fichier'] : null,
                'numero'      => $ligne['numero'] !== null ? (int) $ligne['numero'] : null,
                'libelle'     => $ligne['libelle'] !== null ? (string) $ligne['libelle'] : null,
            ],
            $statement->fetchAll(),
        );
    }

    /**
     * Un nom lisible, qu'on reconnaît en ouvrant l'archive :
     * « fichiers/design/page-d-accueil/v2-passe-typographique.png ».
     *
     * @param array{origine: string, media_type: string, fichier: ?string, numero: ?int, libelle: ?string} $ligne
     */
    private function nomDansArchive(array $ligne): string
    {
        $extension = self::EXTENSIONS[$ligne['media_type']] ?? 'bin';

        if ($ligne['origine'] === 'photo') {
            return "fichiers/photo-de-profil.{$extension}";
        }

        $version = 'v' . ($ligne['numero'] ?? 0);
        $libelle = $this->slug((string) $ligne['libelle']);

        return sprintf(
            'fichiers/design/%s/%s.%s',
            $this->slug((string) $ligne['fichier']) ?: 'sans-nom',
            $libelle !== '' ? "{$version}-{$libelle}" : $version,
            $extension,
        );
    }

    /**
     * Deux maquettes du même nom ne s'écrasent pas dans l'archive : la
     * seconde reçoit un suffixe.
     *
     * @param array<string, true> $pris
     */
    private function nomLibre(string $nom, array &$pris): string
    {
        $candidat = $nom;
        $rang     = 2;

        while (isset($pris[$candidat])) {
            $candidat = preg_replace('/(\.[a-z0-9]+)$/', "-{$rang}$1", $nom) ?? "{$nom}-{$rang}";
            $rang++;
        }

        $pris[$candidat] = true;

        return $candidat;
    }

    /**
     * En ASCII : un nom accentué dans une archive ZIP s'affiche mal selon
     * l'outil qui l'ouvre.
     */
    private function slug(string $texte): string
    {
        $ascii = Transliterator::create('Any-Latin; Latin-ASCII; Lower()')?->transliterate($texte);

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', is_string($ascii) ? $ascii : strtolower($texte)), '-');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function premiere(string $sql, string $userId): ?array
    {
        return $this->lignes($sql, $userId)[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lignes(string $sql, string $userId): array
    {
        $statement = Database::connection()->prepare($sql);
        $statement->execute(['id' => $userId]);

        return array_map($this->normaliser(...), $statement->fetchAll());
    }

    /**
     * Dates en ISO 8601, JSON en structures, booléens en booléens : un export
     * se relit par une machine, pas seulement par un humain.
     *
     * @param  array<string, mixed> $ligne
     * @return array<string, mixed>
     */
    private function normaliser(array $ligne): array
    {
        foreach ($ligne as $cle => $valeur) {
            if ($valeur === null) {
                continue;
            }

            if (in_array($cle, self::JSON, true)) {
                $ligne[$cle] = json_decode((string) $valeur, true);
            } elseif (in_array($cle, self::BOOLEENS, true)) {
                $ligne[$cle] = Database::toBool($valeur);
            } elseif (str_ends_with((string) $cle, '_at') || $cle === 'membre_depuis') {
                $ligne[$cle] = Database::toIso($valeur);
            }
        }

        return $ligne;
    }
}
