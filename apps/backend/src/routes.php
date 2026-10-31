<?php

declare(strict_types=1);

use Vistynq\Controllers\HealthController;
use Vistynq\Http\Router;

/** @var Router $router */

$router->add('GET', '/healthz', [HealthController::class, 'check']);
$router->add('GET', '/api/v1/healthz', [HealthController::class, 'check']);
