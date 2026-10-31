<?php

declare(strict_types=1);

use Vistynq\Controllers\AuthController;
use Vistynq\Controllers\HealthController;
use Vistynq\Http\Router;

/** @var Router $router */

$router->add('GET', '/healthz', [HealthController::class, 'check']);
$router->add('GET', '/api/v1/healthz', [HealthController::class, 'check']);

$router->add('POST', '/api/v1/auth/register', [AuthController::class, 'register']);
$router->add('POST', '/api/v1/auth/verify-email', [AuthController::class, 'verifyEmail']);
$router->add('POST', '/api/v1/auth/resend-verification', [AuthController::class, 'resendVerification']);
$router->add('POST', '/api/v1/auth/login', [AuthController::class, 'login']);
$router->add('POST', '/api/v1/auth/refresh', [AuthController::class, 'refresh']);
$router->add('POST', '/api/v1/auth/logout', [AuthController::class, 'logout']);
$router->add('POST', '/api/v1/auth/forgot-password', [AuthController::class, 'forgotPassword']);
$router->add('POST', '/api/v1/auth/reset-password', [AuthController::class, 'resetPassword']);
$router->add('GET', '/api/v1/auth/me', [AuthController::class, 'me']);
