<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Évolution du schéma après le premier déploiement.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  CE QUI MANQUAIT, ET POURQUOI C'ÉTAIT BLOQUANT                          │
 * │                                                                         │
 * │  Les fichiers « database/init/*.sql » sont joués par PostgreSQL À LA    │
 * │  CRÉATION DU VOLUME, et à ce moment-là seulement. Passé le premier      │
 * │  déploiement, ajouter une colonne n'avait aucun chemin : il restait     │
 * │  psql à la main sur la production, ou détruire les données.             │
 * │                                                                         │
 * │  Le répertoire « database/migrations » existait — avec un .gitkeep, et  │
 * │  aucun code pour le lire.                                               │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * LA RÈGLE, ET ELLE EST SIMPLE :
 *
 *   « init/ » est la LIGNE DE BASE. Elle est figée : on n'y touche plus.
 *   « migrations/ » est TOUT CE QUI VIENT APRÈS.
 *
 * Sur un volume neuf, PostgreSQL joue la ligne de base puis ce migrateur
 * applique la suite. Sur un volume existant, la ligne de base est déjà là et
 * seule la suite s'applique. Les deux chemins convergent vers le même schéma,
 * ce qui est la seule propriété qui compte.
 *
 * TROIS GARDE-FOUS, chacun pour un accident déjà vu ailleurs :
 *
 *  1. UN VERROU CONSULTATIF. Deux conteneurs qui démarrent ensemble
 *     tenteraient la même migration en même temps. « pg_advisory_lock » les
 *     met en file : le second attend, constate que tout est appliqué, et
 *     repart.
 *
 *  2. UNE EMPREINTE PAR FICHIER. Modifier une migration DÉJÀ APPLIQUÉE est
 *     l'erreur classique : elle ne sera pas rejouée, et le schéma de la
 *     production diverge en silence de ce que dit le dépôt. Le migrateur
 *     refuse de démarrer plutôt que de laisser passer.
 *
 *  3. UNE TRANSACTION PAR MIGRATION. PostgreSQL sait annuler du DDL, ce qui
 *     est rare et précieux : une migration qui échoue à mi-parcours ne laisse
 *     pas un schéma à moitié transformé. Les rares ordres qui refusent la
 *     transaction (CREATE INDEX CONCURRENTLY) se déclarent en tête de fichier
 *     avec « -- @sans-transaction ».
 */
final class Migrator
{
    /**
     * Clé du verrou consultatif.
     *
     * Arbitraire mais STABLE : c'est le fait que tous les conteneurs
     * choisissent le même nombre qui les met en file.
     */
    private const LOCK_KEY = 4172025;

    /** Marqueur de tête de fichier pour les ordres qui refusent la transaction. */
    private const SANS_TRANSACTION = '@sans-transaction';

    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? dirname(__DIR__, 2) . '/database/migrations';
    }

    /**
     * Applique les migrations en attente.
     *
     * @param  callable(string): void|null $log
     * @return list<string> versions appliquées, dans l'ordre
     */
    public function run(?callable $log = null): array
    {
        $log ??= static fn (string $line) => null;
        $pdo = Database::connection();

        // Le verrou est pris AVANT la lecture de l'état : sans cela, deux
        // conteneurs pourraient lire « rien d'appliqué » simultanément.
        $pdo->exec('SELECT pg_advisory_lock(' . self::LOCK_KEY . ')');

        try {
            $this->ensureTable();

            $appliquees = $this->applied();
            $fichiers   = $this->files();

            $this->assertUnchanged($appliquees, $fichiers);

            $faites = [];

            foreach ($fichiers as ['version' => $version, 'path' => $chemin]) {
                if (isset($appliquees[$version])) {
                    continue;
                }

                $this->apply($version, $chemin, $log);
                $faites[] = $version;
            }

            if ($faites === []) {
                $log('Schéma à jour — aucune migration en attente.');
            }

            return $faites;
        } finally {
            // Dans un « finally » : une migration qui échoue ne doit pas
            // laisser le verrou pris, sinon le redémarrage suivant attend
            // indéfiniment.
            $pdo->exec('SELECT pg_advisory_unlock(' . self::LOCK_KEY . ')');
        }
    }

    /**
     * État de chaque migration connue, appliquée ou non.
     *
     * @return list<array{version: string, name: string, applied_at: ?string}>
     */
    public function status(): array
    {
        $this->ensureTable();

        $appliquees = $this->applied();
        $etat       = [];

        foreach ($this->files() as ['version' => $version, 'path' => $chemin]) {
            $etat[] = [
                'version'    => $version,
                'name'       => self::nameOf($chemin),
                'applied_at' => $appliquees[$version]['applied_at'] ?? null,
            ];
        }

        return $etat;
    }

    // -----------------------------------------------------------------------

    private function ensureTable(): void
    {
        Database::connection()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                 version     varchar(64) PRIMARY KEY,
                 name        text        NOT NULL,
                 checksum    char(64)    NOT NULL,
                 applied_at  timestamptz NOT NULL DEFAULT NOW(),
                 duration_ms integer     NOT NULL
             )',
        );
    }

    /**
     * @return array<string, array{checksum: string, applied_at: string}>
     */
    private function applied(): array
    {
        $rows  = Database::connection()->query(
            'SELECT version, checksum, applied_at FROM schema_migrations',
        )->fetchAll();
        $index = [];

        foreach ($rows as $row) {
            $index[(string) $row['version']] = [
                'checksum'   => (string) $row['checksum'],
                'applied_at' => (string) $row['applied_at'],
            ];
        }

        return $index;
    }

    /**
     * Fichiers de migration, indexés par version et triés.
     *
     * Le nom porte la version en tête : « 202609091200_file_de_taches.sql ».
     * Un horodatage plutôt qu'une séquence — deux personnes qui écrivent une
     * migration la même semaine ne se disputent pas le même numéro.
     *
     * UNE LISTE, ET NON UNE TABLE INDEXÉE PAR VERSION. En PHP, une clé de
     * tableau qui ressemble à un entier EN DEVIENT un : « 202601010000 »
     * ressortirait en int, et le typage des méthodes appelées ensuite
     * échouerait. Le piège se contourne par un transtypage à chaque lecture ;
     * il disparaît en ne créant pas la clé.
     *
     * @return list<array{version: string, path: string}> trié par version
     */
    private function files(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $trouves = [];

        foreach (glob($this->directory . '/*.sql') ?: [] as $chemin) {
            $base = basename($chemin, '.sql');

            if (preg_match('/^(\d{8,14})_/', $base, $trouve) !== 1) {
                throw new \RuntimeException(
                    "Migration « {$base} » : le nom doit commencer par un horodatage, "
                    . 'par exemple « 202609091200_ajout_colonne.sql ».',
                );
            }

            $trouves[] = ['version' => $trouve[1], 'path' => $chemin];
        }

        usort($trouves, static fn (array $a, array $b): int => strcmp($a['version'], $b['version']));

        return $trouves;
    }

    /**
     * Refuse de continuer si une migration déjà appliquée a été modifiée.
     *
     * @param array<string, array{checksum: string, applied_at: string}> $appliquees
     * @param list<array{version: string, path: string}>                 $fichiers
     */
    private function assertUnchanged(array $appliquees, array $fichiers): void
    {
        foreach ($fichiers as ['version' => $version, 'path' => $chemin]) {
            $trace = $appliquees[$version] ?? null;

            if ($trace === null) {
                // Pas encore appliquée : il n'y a rien à comparer.
                continue;
            }

            $actuel = self::checksum($chemin);

            if ($actuel !== $trace['checksum']) {
                throw new \RuntimeException(
                    "Migration « {$version} » modifiée après avoir été appliquée le "
                    . "{$trace['applied_at']}. Elle ne sera pas rejouée : le schéma de "
                    . "cette base a divergé de ce que dit le dépôt.\n"
                    . '  Rétablissez le fichier, et écrivez une NOUVELLE migration pour '
                    . 'le changement voulu.',
                );
            }
        }
    }

    /**
     * Remplace commentaires et littéraux par des espaces, en gardant les sauts
     * de ligne : ne reste que du SQL exécutable, aux mêmes numéros de ligne.
     *
     * ┌───────────────────────────────────────────────────────────────────────┐
     * │  SANS CELA, AUCUNE MIGRATION NE POURRAIT DÉFINIR DE FONCTION          │
     * │                                                                       │
     * │  Le corps d'une fonction PL/pgSQL commence par « BEGIN », en début de │
     * │  ligne. Le garde-fou ci-dessous, appliqué au fichier brut, refusait   │
     * │  donc toute migration contenant un CREATE FUNCTION — alors même que   │
     * │  ce BEGIN est du TEXTE entre délimiteurs dollar, pas un ordre.        │
     * │                                                                       │
     * │  Un balayage plutôt qu'une succession d'expressions régulières, parce │
     * │  qu'aucun ordre de passage n'est bon : retirer les commentaires en    │
     * │  premier coupe une chaîne contenant « -- », retirer les chaînes en    │
     * │  premier fait démarrer une fausse chaîne sur l'apostrophe de          │
     * │  « -- l'index ».                                                      │
     * └───────────────────────────────────────────────────────────────────────┘
     */
    private static function codeNu(string $sql): string
    {
        $sortie   = '';
        $longueur = strlen($sql);
        $i        = 0;

        while ($i < $longueur) {
            $reste = substr($sql, $i);

            // Délimiteur dollar : $$ ou $balise$. Tout court jusqu'au même.
            if (preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $reste, $ouvrant) === 1) {
                $fin = strpos($sql, $ouvrant[0], $i + strlen($ouvrant[0]));
                $fin = $fin === false ? $longueur : $fin + strlen($ouvrant[0]);

                $sortie .= self::blanchir(substr($sql, $i, $fin - $i));
                $i = $fin;

                continue;
            }

            // Commentaire de ligne : jusqu'au saut, non compris.
            if (str_starts_with($reste, '--')) {
                $fin = strpos($sql, "\n", $i);
                $fin = $fin === false ? $longueur : $fin;

                $sortie .= self::blanchir(substr($sql, $i, $fin - $i));
                $i = $fin;

                continue;
            }

            // Commentaire de bloc. PostgreSQL les imbrique ; on suit le compte.
            if (str_starts_with($reste, '/*')) {
                $profondeur = 1;
                $j          = $i + 2;

                while ($j < $longueur && $profondeur > 0) {
                    if (str_starts_with(substr($sql, $j, 2), '/*')) {
                        ++$profondeur;
                        $j += 2;
                    } elseif (str_starts_with(substr($sql, $j, 2), '*/')) {
                        --$profondeur;
                        $j += 2;
                    } else {
                        ++$j;
                    }
                }

                $sortie .= self::blanchir(substr($sql, $i, $j - $i));
                $i = $j;

                continue;
            }

            // Chaîne ou identifiant entre guillemets. Le délimiteur doublé ne
            // ferme pas : 'l''index' est UNE chaîne, pas deux.
            if ($sql[$i] === "'" || $sql[$i] === '"') {
                $quote = $sql[$i];
                $j     = $i + 1;

                while ($j < $longueur) {
                    if ($sql[$j] !== $quote) {
                        ++$j;

                        continue;
                    }

                    if (($sql[$j + 1] ?? '') === $quote) {
                        $j += 2;

                        continue;
                    }

                    ++$j;

                    break;
                }

                $sortie .= self::blanchir(substr($sql, $i, $j - $i));
                $i = $j;

                continue;
            }

            $sortie .= $sql[$i];
            ++$i;
        }

        return $sortie;
    }

    /** Tout devient espace, sauf les sauts de ligne. */
    private static function blanchir(string $fragment): string
    {
        return preg_replace('/[^\n]/', ' ', $fragment) ?? '';
    }

    /**
     * @param callable(string): void $log
     */
    private function apply(string $version, string $fichier, callable $log): void
    {
        $sql  = file_get_contents($fichier);
        $nom  = self::nameOf($fichier);
        $pdo  = Database::connection();

        if ($sql === false) {
            throw new \RuntimeException("Migration « {$version} » illisible.");
        }

        $transactionnelle = !str_contains($sql, self::SANS_TRANSACTION);

        // ┌───────────────────────────────────────────────────────────────────┐
        // │  LA TRANSACTION APPARTIENT AU MIGRATEUR                           │
        // │                                                                   │
        // │  Un « COMMIT » au milieu du fichier valide ce qui précède et      │
        // │  laisse le migrateur sans transaction à valider : PDO lève « There │
        // │  is no active transaction », et surtout la garantie d'atomicité   │
        // │  annoncée plus haut serait fausse — une migration à moitié jouée  │
        // │  resterait à moitié jouée.                                        │
        // │                                                                   │
        // │  Les fichiers de « init/ » en contiennent, eux, parce que         │
        // │  PostgreSQL les joue directement. La règle diffère ici, et mieux  │
        // │  vaut la dire au premier essai que la laisser découvrir.          │
        // └───────────────────────────────────────────────────────────────────┘
        if ($transactionnelle && preg_match('/^\s*(BEGIN|COMMIT|ROLLBACK)\b/mi', self::codeNu($sql)) === 1) {
            throw new \RuntimeException(
                "Migration « {$version} » : retirez les BEGIN / COMMIT. Le migrateur ouvre "
                . "déjà une transaction et la valide lui-même.\n"
                . '  Pour un ordre qui refuse la transaction (CREATE INDEX CONCURRENTLY), '
                . 'déclarez « -- ' . self::SANS_TRANSACTION . ' » en tête de fichier.',
            );
        }

        $debut = microtime(true);

        $log("  → {$version}  {$nom}");

        if ($transactionnelle) {
            $pdo->beginTransaction();
        }

        try {
            $pdo->exec($sql);

            $duree = (int) round((microtime(true) - $debut) * 1000);

            $pdo->prepare(
                'INSERT INTO schema_migrations (version, name, checksum, duration_ms)
                 VALUES (:version, :name, :checksum, :duration)',
            )->execute([
                'version'  => $version,
                'name'     => $nom,
                'checksum' => self::checksum($fichier),
                'duration' => $duree,
            ]);

            if ($transactionnelle) {
                $pdo->commit();
            }

            $log("     appliquée en {$duree} ms");
        } catch (\Throwable $erreur) {
            if ($transactionnelle && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw new \RuntimeException(
                "Migration « {$version} » échouée : " . $erreur->getMessage(),
                0,
                $erreur,
            );
        }
    }

    /** Nom lisible : ce qui suit l'horodatage, tirets bas en espaces. */
    private static function nameOf(string $fichier): string
    {
        $base = basename($fichier, '.sql');

        return str_replace('_', ' ', (string) preg_replace('/^\d+_/', '', $base));
    }

    private static function checksum(string $fichier): string
    {
        // Les fins de ligne sont normalisées : un fichier passé par un poste
        // Windows ne doit pas paraître modifié.
        $contenu = (string) file_get_contents($fichier);

        return hash('sha256', str_replace("\r\n", "\n", $contenu));
    }
}
