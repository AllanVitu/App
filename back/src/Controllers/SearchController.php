<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\SearchService;

/**
 * Recherche transverse aux cinq modules.
 *
 * Un seul endpoint, volontairement : le client ne doit pas avoir à interroger
 * cinq API et à fusionner les réponses. Le tri par date appartient à la base,
 * qui seule voit l'ensemble (cf. SearchService).
 */
final class SearchController
{
    /**
     * GET /api/search?q=…
     */
    public function index(Request $request): void
    {
        $terme = trim($request->queryParam('q') ?? '');

        Response::json(
            (new SearchService())->search($request->organizationId(), $terme),
            200,
            // Le terme est renvoyé pour que le client puisse ignorer une
            // réponse périmée : on tape plus vite que le réseau ne répond, et
            // sans cela une réponse lente à « re » écraserait celle de
            // « refresh ».
            ['query' => $terme],
        );
    }
}
