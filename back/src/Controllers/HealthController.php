<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Env;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use Throwable;

/**
 * Sonde de disponibilité — utilisée par les vérifications d'infrastructure.
 * Route publique, mais ne divulgue rien d'exploitable.
 */
final class HealthController
{
    /**
     * GET /api/health
     */
    public function index(Request $request): void
    {
        $databaseUp = true;

        try {
            Database::connection()->query('SELECT 1');
        } catch (Throwable) {
            $databaseUp = false;
        }

        Response::json([
            'status'      => $databaseUp ? 'ok' : 'degraded',
            'environment' => Env::get('APP_ENV', 'development'),
            'php'         => PHP_VERSION,
            'database'    => $databaseUp ? 'up' : 'down',
            'timestamp'   => gmdate('c'),
        ], $databaseUp ? 200 : 503);
    }
}
