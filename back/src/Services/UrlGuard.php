<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ClientIp;
use Closure;

/**
 * Ce qu'une sonde a le droit d'appeler.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  UNE SONDE EST UNE REQUÊTE QUE LE SERVEUR ÉMET POUR LE COMPTE D'AUTRUI  │
 * │                                                                         │
 * │  Sans garde, « http://169.254.169.254/latest/meta-data/ » lirait les    │
 * │  identifiants du cloud qui héberge Relais, « http://db:5432 » sonderait │
 * │  la base, « http://localhost:9000 » parlerait à PHP-FPM. C'est une      │
 * │  falsification de requête côté serveur (SSRF), et le formulaire         │
 * │  « ajouter une sonde » en serait la porte d'entrée.                     │
 * │                                                                         │
 * │  Trois verrous, parce qu'aucun ne suffit seul :                         │
 * │                                                                         │
 * │   1. L'ADRESSE : http ou https, un hôte, aucun identifiant dans l'URL.  │
 * │   2. LA RÉSOLUTION : toutes les adresses du nom doivent être publiques. │
 * │   3. L'ÉPINGLAGE : la connexion part vers l'adresse VÉRIFIÉE, sans      │
 * │      nouvelle résolution (cf. HttpProbe). Sinon, un DNS malveillant     │
 * │      répond une adresse publique à la vérification et 127.0.0.1 à la    │
 * │      connexion, quelques millisecondes plus tard.                       │
 * │                                                                         │
 * │  Et les redirections ne sont jamais suivies : chacune rouvrirait les    │
 * │  trois questions vers une adresse que personne n'a vérifiée.            │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * La résolution se limite à IPv4 (gethostbynamel) : c'est l'adresse vérifiée
 * que la sonde utilise, et une vérification qui porterait sur un jeu
 * d'adresses et une connexion qui en choisirait un autre ne vérifierait rien.
 */
final class UrlGuard
{
    /**
     * Ce que FILTER_FLAG_NO_PRIV_RANGE et FILTER_FLAG_NO_RES_RANGE laissent
     * passer selon la version de PHP. Redondant par endroits, et c'est voulu :
     * la liste se lit sans connaître le détail de ces drapeaux.
     */
    private const BLOCKED = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',   // partage d'adresses des opérateurs (CGNAT)
        '127.0.0.0/8',
        '169.254.0.0/16',  // lien local — dont les métadonnées des clouds
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',   // bancs de test réseau
        '224.0.0.0/4',     // multidiffusion
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        '::ffff:0:0/96',   // IPv4 déguisée en IPv6
        '64:ff9b::/96',    // traduction NAT64
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    /** Des noms qui ne désignent jamais l'Internet public. */
    private const PRIVATE_SUFFIXES = ['localhost', '.localhost', '.local', '.internal', '.lan', '.home.arpa'];

    /**
     * @param ?Closure(string): list<string> $resolver nom -> adresses IPv4 ;
     *        par défaut le DNS du système. Injecté dans les tests : un test
     *        qui dépendrait du réseau échouerait dans un train.
     */
    public function __construct(private readonly ?Closure $resolver = null)
    {
    }

    /**
     * Vérifie une adresse et renvoie ce qu'il faut pour s'y connecter sans la
     * résoudre une seconde fois.
     *
     * « resolve: false » vérifie tout sauf la résolution DNS : c'est la
     * vérification d'un formulaire d'enregistrement. Un nom peut changer
     * d'adresse demain ; la vérification qui protège est celle de l'appel,
     * refaite à chaque fois. L'adresse rendue est alors vide.
     *
     * @return array{url: string, scheme: string, host: string, port: int, ip: string, literal: bool}
     *
     * @throws UnsafeUrl
     */
    public function check(string $url, bool $resolve = true): array
    {
        if (strlen($url) > 400 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            throw new UnsafeUrl("L'adresse contient des caractères non autorisés.");
        }

        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeUrl("L'adresse est illisible : elle doit commencer par http:// ou https://.");
        }

        $scheme = strtolower($parts['scheme']);

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeUrl('Seules les adresses http et https peuvent être sondées.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrl("Une adresse de sonde ne peut pas contenir d'identifiants.");
        }

        $host = strtolower(rtrim(trim($parts['host'], '[]'), '.'));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if ($host === '') {
            throw new UnsafeUrl("L'adresse n'indique aucun serveur.");
        }

        foreach (self::PRIVATE_SUFFIXES as $suffix) {
            if ($host === ltrim($suffix, '.') || str_ends_with($host, $suffix)) {
                throw new UnsafeUrl(self::refusal());
            }
        }

        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;

        if (!$literal && !$resolve) {
            return ['url' => $url, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => '', 'literal' => false];
        }

        $addresses = $literal ? [$host] : $this->resolve($host);

        if ($addresses === []) {
            throw new UnsafeUrl('Ce nom de domaine ne correspond à aucune adresse.');
        }

        // TOUTES les adresses : un nom qui en publie une publique et une
        // privée laisserait le hasard du répartiteur choisir la seconde.
        foreach ($addresses as $ip) {
            if (!self::isPublic($ip)) {
                throw new UnsafeUrl(self::refusal());
            }
        }

        return [
            'url'     => $url,
            'scheme'  => $scheme,
            'host'    => $host,
            'port'    => $port,
            'ip'      => $addresses[0],
            'literal' => $literal,
        ];
    }

    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (self::BLOCKED as $range) {
            if (ClientIp::inRange($ip, $range)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Le même message pour « privé », « réservé » et « local » : dire lequel,
     * c'est cartographier le réseau interne une question à la fois.
     */
    private static function refusal(): string
    {
        return 'Cette adresse désigne un réseau privé ou réservé : une sonde ne peut pas l\'appeler.';
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($host);
        }

        $addresses = gethostbynamel($host);

        return $addresses === false ? [] : $addresses;
    }
}
