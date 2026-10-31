<?php

declare(strict_types=1);

use Vistynq\Controllers\AuthController;
use Vistynq\Controllers\CanvasController;
use Vistynq\Controllers\HealthController;
use Vistynq\Controllers\NoteController;
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

$router->add('GET', '/api/v1/canvases', [CanvasController::class, 'index']);
$router->add('POST', '/api/v1/canvases', [CanvasController::class, 'create']);
$router->add('GET', '/api/v1/canvases/{id}/graph', [CanvasController::class, 'graph']);
$router->add('PATCH', '/api/v1/canvases/{id}', [CanvasController::class, 'update']);
$router->add('DELETE', '/api/v1/canvases/{id}', [CanvasController::class, 'delete']);
$router->add('GET', '/api/v1/canvases/{id}/archive', [NoteController::class, 'archive']);
$router->add('GET', '/api/v1/canvases/{id}/search', [NoteController::class, 'search']);

$router->add('POST', '/api/v1/canvases/{id}/notes', [NoteController::class, 'create']);
$router->add('PATCH', '/api/v1/canvases/{id}/nodes/{noteId}', [NoteController::class, 'updatePosition']);

$router->add('GET', '/api/v1/notes/{id}', [NoteController::class, 'show']);
$router->add('PATCH', '/api/v1/notes/{id}', [NoteController::class, 'update']);
$router->add('PUT', '/api/v1/notes/{id}/body', [NoteController::class, 'updateBody']);
$router->add('DELETE', '/api/v1/notes/{id}', [NoteController::class, 'delete']);
$router->add('POST', '/api/v1/notes/{id}/restore', [NoteController::class, 'restore']);
$router->add('DELETE', '/api/v1/notes/{id}/purge', [NoteController::class, 'purge']);
