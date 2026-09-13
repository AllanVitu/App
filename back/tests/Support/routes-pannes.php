<?php

declare(strict_types=1);

/**
 * Table de routage des tests de supervision : celle de l'application, plus
 * trois routes qui échouent à coup sûr.
 *
 * Partir de la vraie table plutôt que d'une table minimale : les tests
 * traversent aussi l'inscription, les espaces et l'ingestion, et une table
 * recopiée à la main finirait par diverger de celle qu'on déploie.
 */

use App\Core\Router;
use Tests\Support\PanneController;

/** @var Router $router */
$router = require __DIR__ . '/../../routes/api.php';

$router->get('/api/test/pannes/{id}', [PanneController::class, 'exception']);
$router->get('/api/test/transaction-avortee', [PanneController::class, 'transactionAvortee']);
$router->get('/api/test/defaut-de-code', [PanneController::class, 'defautDeCode']);

return $router;
