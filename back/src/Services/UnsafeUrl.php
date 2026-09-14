<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Une adresse qu'une sonde n'a pas le droit d'appeler.
 *
 * Le message est destiné à la personne qui a saisi l'adresse : il dit ce qui
 * ne va pas, jamais ce que le réseau interne contient.
 */
final class UnsafeUrl extends RuntimeException
{
}
