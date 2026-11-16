<?php

declare(strict_types=1);

use Vistynq\Controllers\AccessController;
use Vistynq\Controllers\AuthController;
use Vistynq\Controllers\CanvasController;
use Vistynq\Controllers\HealthController;
use Vistynq\Controllers\LinkController;
use Vistynq\Controllers\NoteController;
use Vistynq\Controllers\ProfileController;
use Vistynq\Controllers\SharedController;
use Vistynq\Controllers\TagController;
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
$router->add('PATCH', '/api/v1/profile', [ProfileController::class, 'update']);

$router->add('GET', '/api/v1/canvases', [CanvasController::class, 'index']);
$router->add('POST', '/api/v1/canvases', [CanvasController::class, 'create']);
$router->add('GET', '/api/v1/canvases/{id}/graph', [CanvasController::class, 'graph']);
$router->add('PATCH', '/api/v1/canvases/{id}', [CanvasController::class, 'update']);
$router->add('DELETE', '/api/v1/canvases/{id}', [CanvasController::class, 'delete']);
$router->add('GET', '/api/v1/canvases/{id}/archive', [NoteController::class, 'archive']);
$router->add('GET', '/api/v1/canvases/{id}/search', [NoteController::class, 'search']);

$router->add('POST', '/api/v1/canvases/{id}/notes', [NoteController::class, 'create']);
$router->add('POST', '/api/v1/canvases/{id}/notes/from-text', [NoteController::class, 'fromText']);
$router->add('POST', '/api/v1/canvases/{id}/notes/derive', [NoteController::class, 'derive']);
$router->add('PATCH', '/api/v1/canvases/{id}/nodes/{noteId}', [NoteController::class, 'updatePosition']);

$router->add('GET', '/api/v1/notes/{id}', [NoteController::class, 'show']);
$router->add('PATCH', '/api/v1/notes/{id}', [NoteController::class, 'update']);
$router->add('PUT', '/api/v1/notes/{id}/body', [NoteController::class, 'updateBody']);
$router->add('DELETE', '/api/v1/notes/{id}', [NoteController::class, 'delete']);
$router->add('POST', '/api/v1/notes/{id}/restore', [NoteController::class, 'restore']);
$router->add('DELETE', '/api/v1/notes/{id}/purge', [NoteController::class, 'purge']);

$router->add('GET', '/api/v1/canvases/{id}/tags', [TagController::class, 'index']);
$router->add('POST', '/api/v1/canvases/{id}/tags', [TagController::class, 'create']);
$router->add('PATCH', '/api/v1/tags/{id}', [TagController::class, 'update']);
$router->add('DELETE', '/api/v1/tags/{id}', [TagController::class, 'delete']);

$router->add('POST', '/api/v1/links/direct', [LinkController::class, 'create']);
$router->add('DELETE', '/api/v1/links/direct/{id}', [LinkController::class, 'delete']);
$router->add('POST', '/api/v1/links/direct/{id}/reverse', [LinkController::class, 'reverse']);

// Доступ и публикация (R16): участники, инвайты, уровень публичности.
$router->add('GET', '/api/v1/canvases/{id}/access', [AccessController::class, 'listCanvas']);
$router->add('PUT', '/api/v1/canvases/{id}/access-level', [AccessController::class, 'setCanvasLevel']);
$router->add('POST', '/api/v1/canvases/{id}/access/invite', [AccessController::class, 'inviteCanvas']);
$router->add('PATCH', '/api/v1/canvases/{id}/access/{userId}', [AccessController::class, 'updateCanvasRole']);
$router->add('DELETE', '/api/v1/canvases/{id}/access/{userId}', [AccessController::class, 'revokeCanvas']);
$router->add('DELETE', '/api/v1/canvases/{id}/invitations/{invitationId}', [AccessController::class, 'revokeCanvasInvitation']);

$router->add('GET', '/api/v1/notes/{id}/access', [AccessController::class, 'listNote']);
$router->add('PUT', '/api/v1/notes/{id}/visibility', [AccessController::class, 'setVisibility']);
$router->add('POST', '/api/v1/notes/{id}/access/invite', [AccessController::class, 'inviteNote']);
$router->add('PATCH', '/api/v1/notes/{id}/access/{userId}', [AccessController::class, 'updateNoteRole']);
$router->add('DELETE', '/api/v1/notes/{id}/access/{userId}', [AccessController::class, 'revokeNote']);
$router->add('DELETE', '/api/v1/notes/{id}/invitations/{invitationId}', [AccessController::class, 'revokeNoteInvitation']);

// Публичные read-only страницы (R16): без Bearer.
$router->add('GET', '/api/v1/shared/canvases/{slug}', [SharedController::class, 'canvas']);
$router->add('GET', '/api/v1/shared/canvases/{slug}/notes/{noteId}', [SharedController::class, 'note']);
