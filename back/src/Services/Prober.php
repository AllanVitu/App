<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Ce qui appelle une adresse pour le compte d'une sonde.
 *
 * Une interface pour une seule implémentation réelle (HttpProbe), et c'est
 * délibéré : le worker se teste avec une sonde de laboratoire qui répond ce
 * qu'on lui dicte. Un test qui appellerait Internet échouerait dans un train,
 * et ne pourrait jamais provoquer à la demande la panne qu'il veut vérifier.
 */
interface Prober
{
    /**
     * @return array{outcome: 'up'|'slow'|'down', http_status: ?int, response_ms: ?int, error: ?string}
     */
    public function call(string $url, string $method, int $timeoutMs, int $slowMs): array;
}
