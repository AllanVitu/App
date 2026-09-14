<?php

declare(strict_types=1);

namespace App\Core;

use App\Config\Env;

/**
 * L'adresse IP d'un client, et qui a le droit de l'affirmer.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  « X-Forwarded-For » EST UN EN-TÊTE COMME UN AUTRE                      │
 * │                                                                         │
 * │  N'importe quel client l'écrit. Request::ip() le lisait sans condition, │
 * │  et prenait son PREMIER élément : chaque requête choisissait donc son   │
 * │  adresse, et le verrou de connexion par IP se rouvrait à chaque         │
 * │  tentative en changeant une ligne.                                      │
 * │                                                                         │
 * │  L'adresse retenue est désormais celle qui se connecte. Seul un         │
 * │  intermédiaire déclaré dans TRUSTED_PROXIES peut en affirmer une autre, │
 * │  et la chaîne se lit alors de DROITE à gauche : chaque proxy ajoute à   │
 * │  la fin ce qu'il a vu, et tout ce qui précède a pu être écrit par le    │
 * │  client. Le client est le premier maillon qui n'est pas de confiance.   │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
final class ClientIp
{
    /**
     * @param list<string> $trustedProxies adresses ou blocs CIDR
     */
    public static function resolve(?string $remoteAddress, ?string $forwardedFor, array $trustedProxies): ?string
    {
        if ($remoteAddress === null || !self::isIp($remoteAddress)) {
            return null;
        }

        if ($forwardedFor === null || !self::isTrusted($remoteAddress, $trustedProxies)) {
            return $remoteAddress;
        }

        $client = $remoteAddress;

        foreach (array_reverse(explode(',', $forwardedFor)) as $hop) {
            $hop = trim($hop);

            // Un maillon illisible rompt la chaîne : rien de ce qui le précède
            // n'a été écrit par un intermédiaire dont on soit sûr.
            if (!self::isIp($hop)) {
                return $client;
            }

            $client = $hop;

            if (!self::isTrusted($hop, $trustedProxies)) {
                return $hop;
            }
        }

        return $client;
    }

    /**
     * Vrai si l'adresse appartient au bloc (« 10.0.0.0/8 », « fd00::/8 ») ou
     * lui est égale (« 192.0.2.10 »). Un bloc illisible ne contient rien.
     */
    public static function inRange(string $ip, string $range): bool
    {
        $range = trim($range);

        if ($range === '') {
            return false;
        }

        [$subnet, $bits] = str_contains($range, '/') ? explode('/', $range, 2) : [$range, null];

        if (!self::isIp($ip) || !self::isIp($subnet)) {
            return false;
        }

        $address = inet_pton($ip);
        $block   = inet_pton($subnet);

        // Une adresse IPv4 n'appartient à aucun bloc IPv6, et inversement.
        if ($address === false || $block === false || strlen($address) !== strlen($block)) {
            return false;
        }

        $width = strlen($address) * 8;

        if ($bits === null) {
            $prefix = $width;
        } elseif (preg_match('/^\d{1,3}$/', $bits) === 1 && (int) $bits <= $width) {
            $prefix = (int) $bits;
        } else {
            return false;
        }

        $whole = intdiv($prefix, 8);

        if (strncmp($address, $block, $whole) !== 0) {
            return false;
        }

        $rest = $prefix % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($address[$whole]) & $mask) === (ord($block[$whole]) & $mask);
    }

    /**
     * TRUSTED_PROXIES, séparées par des virgules. Vide par défaut : aucun
     * intermédiaire n'est cru sans avoir été désigné.
     *
     * @return list<string>
     */
    public static function trustedProxies(): array
    {
        $raw = Env::get('TRUSTED_PROXIES');

        if ($raw === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $p): bool => $p !== ''));
    }

    /**
     * @param list<string> $trustedProxies
     */
    private static function isTrusted(string $ip, array $trustedProxies): bool
    {
        foreach ($trustedProxies as $range) {
            if (self::inRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private static function isIp(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }
}
