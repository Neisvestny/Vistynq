<?php

declare(strict_types=1);

namespace Vistynq\Controllers;

use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Middleware\AuthMiddleware;
use Vistynq\Services\SharingService;
use Vistynq\Support\Validator;

/**
 * Доступ и публикация (docs/api.md §Доступ, R16): тонкий HTTP-слой,
 * права и транзакции — в SharingService/InvitationService.
 */
final class AccessController
{
    public function __construct(private readonly SharingService $sharing)
    {
    }

    public function listCanvas(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $canvasId = (string) $request->routeParam('id');

        return Response::json($this->sharing->listCanvasAccess($canvasId, $userId));
    }

    public function setCanvasLevel(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $canvasId = (string) $request->routeParam('id');
        $data = $request->json();
        Validator::assert($data, ['level' => 'required|in:private,public']);

        return Response::json($this->sharing->setCanvasLevel($canvasId, $userId, (string) $data['level']));
    }

    public function inviteCanvas(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $canvasId = (string) $request->routeParam('id');
        [$email, $role] = self::invitePayload($request);

        $status = $this->sharing->inviteToCanvas($canvasId, $userId, $email, $role);

        return Response::json(['status' => $status], 201);
    }

    public function updateCanvasRole(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $canvasId = (string) $request->routeParam('id');
        $targetUserId = (string) $request->routeParam('userId');
        $role = self::rolePayload($request);

        $this->sharing->updateCanvasRole($canvasId, $userId, $targetUserId, $role);

        return Response::noContent();
    }

    public function revokeCanvas(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $canvasId = (string) $request->routeParam('id');
        $targetUserId = (string) $request->routeParam('userId');

        $this->sharing->revokeCanvas($canvasId, $userId, $targetUserId);

        return Response::noContent();
    }

    public function revokeCanvasInvitation(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $canvasId = (string) $request->routeParam('id');
        $invitationId = (string) $request->routeParam('invitationId');

        $this->sharing->revokeCanvasInvitation($canvasId, $userId, $invitationId);

        return Response::noContent();
    }

    public function listNote(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $noteId = (string) $request->routeParam('id');

        return Response::json($this->sharing->listNoteAccess($noteId, $userId));
    }

    public function setVisibility(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $noteId = (string) $request->routeParam('id');
        $data = $request->json();
        Validator::assert($data, ['visibility' => 'required|in:public,private']);

        return Response::json($this->sharing->setVisibility($noteId, $userId, (string) $data['visibility']));
    }

    public function inviteNote(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $noteId = (string) $request->routeParam('id');
        [$email, $role] = self::invitePayload($request);

        $status = $this->sharing->inviteToNote($noteId, $userId, $email, $role);

        return Response::json(['status' => $status], 201);
    }

    public function updateNoteRole(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $noteId = (string) $request->routeParam('id');
        $targetUserId = (string) $request->routeParam('userId');
        $role = self::rolePayload($request);

        $this->sharing->updateNoteRole($noteId, $userId, $targetUserId, $role);

        return Response::noContent();
    }

    public function revokeNote(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $noteId = (string) $request->routeParam('id');
        $targetUserId = (string) $request->routeParam('userId');

        $this->sharing->revokeNote($noteId, $userId, $targetUserId);

        return Response::noContent();
    }

    public function revokeNoteInvitation(Request $request): Response
    {
        $userId = AuthMiddleware::userId($request);
        $noteId = (string) $request->routeParam('id');
        $invitationId = (string) $request->routeParam('invitationId');

        $this->sharing->revokeNoteInvitation($noteId, $userId, $invitationId);

        return Response::noContent();
    }

    /**
     * @return array{0: string, 1: string} email, role
     */
    private static function invitePayload(Request $request): array
    {
        $data = $request->json();
        Validator::assert($data, [
            'email' => 'required|email|maxLength:254',
            'role' => 'required|in:viewer,editor',
        ]);

        return [trim((string) $data['email']), (string) $data['role']];
    }

    private static function rolePayload(Request $request): string
    {
        $data = $request->json();
        Validator::assert($data, ['role' => 'required|in:viewer,editor']);

        return (string) $data['role'];
    }
}
