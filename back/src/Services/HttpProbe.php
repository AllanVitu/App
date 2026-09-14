<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Un appel de sonde : une requête HTTP bornée de toutes parts.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  CE QUE CETTE REQUÊTE N'A PAS LE DROIT DE FAIRE                         │
 * │                                                                         │
 * │  - se connecter ailleurs qu'à l'adresse que UrlGuard vient de vérifier  │
 * │    (CURLOPT_RESOLVE épingle le nom sur elle : aucune seconde            │
 * │    résolution, donc aucun rebond DNS vers le réseau interne) ;          │
 * │  - suivre une redirection, qui mènerait vers une adresse non vérifiée ; │
 * │  - parler autre chose que HTTP et HTTPS — ni file://, ni gopher:// ;    │
 * │  - passer par un proxy d'environnement, qui relaierait où il veut ;     │
 * │  - accepter un certificat TLS invalide, qui mesurerait n'importe qui ;  │
 * │  - télécharger plus de 64 Ko : on mesure une disponibilité, on ne       │
 * │    rapatrie pas un site ;                                               │
 * │  - durer plus que le délai réglé, dix secondes au plus.                 │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Les en-têtes et le corps de la réponse ne sont ni lus ni conservés : seuls
 * le code HTTP et la durée sortent d'ici. Rien de ce que renvoie le site
 * surveillé n'atteint la base ni l'écran.
 */
final class HttpProbe implements Prober
{
    private const MAX_BODY_BYTES = 65_536;

    private const CONNECT_TIMEOUT_MS = 3_000;

    public function __construct(private readonly UrlGuard $guard = new UrlGuard())
    {
    }

    public function call(string $url, string $method, int $timeoutMs, int $slowMs): array
    {
        try {
            $target = $this->guard->check($url);
        } catch (UnsafeUrl $refus) {
            return self::down(null, null, $refus->getMessage());
        }

        $received = 0;
        $handle   = curl_init();

        $options = [
            CURLOPT_URL               => $target['url'],
            CURLOPT_CUSTOMREQUEST     => $method,
            CURLOPT_NOBODY            => $method === 'HEAD',
            CURLOPT_PROTOCOLS         => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION    => false,
            CURLOPT_CONNECTTIMEOUT_MS => min(self::CONNECT_TIMEOUT_MS, $timeoutMs),
            CURLOPT_TIMEOUT_MS        => $timeoutMs,
            CURLOPT_NOPROXY           => '*',
            CURLOPT_SSL_VERIFYPEER    => true,
            CURLOPT_SSL_VERIFYHOST    => 2,
            CURLOPT_USERAGENT         => 'Relais-Sonde/1.0',
            CURLOPT_HEADER            => false,
            CURLOPT_WRITEFUNCTION     => static function (mixed $curl, string $chunk) use (&$received): int {
                $received += strlen($chunk);

                // Renvoyer moins que reçu interrompt le transfert : le code
                // HTTP est déjà connu, le reste ne sert à rien.
                return $received > self::MAX_BODY_BYTES ? 0 : strlen($chunk);
            },
        ];

        if (!$target['literal']) {
            $options[CURLOPT_RESOLVE]   = [sprintf('%s:%d:%s', $target['host'], $target['port'], $target['ip'])];
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }

        curl_setopt_array($handle, $options);
        curl_exec($handle);

        $errno  = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $ms     = (int) round((float) curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000);

        // L'interruption volontaire du corps n'est pas une panne.
        $interrompu = $errno === CURLE_WRITE_ERROR && $status > 0;

        if ($errno !== 0 && !$interrompu) {
            return self::down(null, $ms > 0 ? $ms : null, self::describe($errno));
        }

        if ($status === 0) {
            return self::down(null, $ms, 'Aucune réponse du serveur.');
        }

        if ($status >= 400) {
            return self::down($status, $ms, "Réponse HTTP {$status}.");
        }

        return [
            'outcome'     => $ms > $slowMs ? 'slow' : 'up',
            'http_status' => $status,
            'response_ms' => $ms,
            'error'       => null,
        ];
    }

    /**
     * @return array{outcome: 'down', http_status: ?int, response_ms: ?int, error: string}
     */
    private static function down(?int $status, ?int $ms, string $error): array
    {
        return ['outcome' => 'down', 'http_status' => $status, 'response_ms' => $ms, 'error' => $error];
    }

    /**
     * Ce qu'une personne peut faire du code d'erreur de cURL : le comprendre.
     */
    private static function describe(int $errno): string
    {
        return match ($errno) {
            CURLE_OPERATION_TIMEDOUT   => 'Délai dépassé : aucune réponse à temps.',
            CURLE_COULDNT_CONNECT      => 'Connexion refusée par le serveur.',
            CURLE_COULDNT_RESOLVE_HOST => 'Nom de domaine introuvable.',
            CURLE_SSL_CONNECT_ERROR,
            CURLE_SSL_CACERT           => 'Certificat TLS refusé.',
            CURLE_GOT_NOTHING          => 'Le serveur a fermé la connexion sans répondre.',
            default                    => "L'appel a échoué (erreur réseau {$errno}).",
        };
    }
}
