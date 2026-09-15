<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Secrets;
use App\Core\Database;
use App\Core\HttpException;

/**
 * Limitation de débit générique, par fenêtre fixe.
 *
 * ThrottleService garde son rôle : il compte des ÉCHECS d'authentification par
 * adresse et par IP, et un succès les efface. Ce service-ci compte des APPELS,
 * quelle qu'en soit l'issue — par compte, par IP, par clé.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  LA CLÉ N'EST JAMAIS STOCKÉE EN CLAIR                                   │
 * │                                                                         │
 * │  Compter par adresse IP, c'est a priori écrire des adresses IP en base. │
 * │  Le compteur n'a pourtant besoin que de reconnaître la même clé d'un    │
 * │  appel à l'autre : une empreinte HMAC y suffit, et une fuite de la      │
 * │  table ne révèle ni qui ni d'où. C'est une pseudonymisation au sens de  │
 * │  l'article 4.5 du RGPD — sans la clé, l'empreinte ne se rattache à      │
 * │  personne.                                                              │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * FENÊTRE FIXE, et sa limite est assumée : un appelant peut concentrer deux
 * quotas de part et d'autre d'une frontière. Une fenêtre glissante demanderait
 * une ligne par appel ; pour contenir un abus, deux fois un quota raisonnable
 * reste raisonnable.
 */
final class RateLimiter
{
    /**
     * Compte un appel, et refuse au-delà du quota.
     *
     * @throws HttpException 429, accompagnée de « Retry-After »
     */
    public function hit(string $scope, string $key, int $max, int $windowSeconds): void
    {
        $now   = time();
        $start = intdiv($now, $windowSeconds) * $windowSeconds;

        // Une seule instruction. Lire le compteur puis l'écrire laisserait deux
        // appels simultanés lire la même valeur et passer tous les deux.
        $statement = Database::connection()->prepare(
            'INSERT INTO rate_limits (bucket, window_start)
             VALUES (:bucket, to_timestamp(:start))
             ON CONFLICT (bucket, window_start) DO UPDATE SET hits = rate_limits.hits + 1
             RETURNING hits',
        );

        $statement->execute(['bucket' => $this->bucket($scope, $key), 'start' => $start]);

        if ((int) $statement->fetchColumn() <= $max) {
            return;
        }

        $retry = max(1, $start + $windowSeconds - $now);

        if (!headers_sent()) {
            header('Retry-After: ' . $retry);
        }

        throw HttpException::tooManyRequests(sprintf('Trop de requêtes. Réessayez %s.', $this->delay($retry)));
    }

    /**
     * Les fenêtres closes depuis plus d'un jour n'ont plus rien à compter.
     *
     * @return int nombre de compteurs supprimés
     */
    public function purge(): int
    {
        return (int) Database::connection()->exec(
            "DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL '1 day'",
        );
    }

    private function bucket(string $scope, string $key): string
    {
        // Le séparateur NUL empêche deux couples différents de produire la même
        // chaîne : « a » + « bc » et « ab » + « c ».
        return hash_hmac('sha256', $scope . "\0" . $key, Secrets::derive('rate-limit'));
    }

    private function delay(int $seconds): string
    {
        return $seconds < 90
            ? "dans {$seconds} secondes"
            : 'dans ' . (int) ceil($seconds / 60) . ' minutes';
    }
}
