<?php

declare(strict_types=1);

namespace Vistynq\Services;

use Vistynq\Repositories\CanvasRepository;
use Vistynq\Repositories\NoteRepository;
use Vistynq\Repositories\UserRepository;
use Vistynq\Support\ApiException;

final class AccessService
{
    public function __construct(
        private readonly CanvasRepository $canvases,
        private readonly NoteRepository $notes,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @return array{role:string|null,canvas:array<string,mixed>}
     */
    public function getCanvasContext(string $canvasId, ?string $userId): array
    {
        $canvas = $this->canvases->findById($canvasId);
        if ($canvas === null) {
            throw ApiException::notFound();
        }

        $role = $this->getCanvasRole($canvas, $userId);
        if (!$this->canViewCanvas($canvas, $role)) {
            throw ApiException::notFound();
        }

        return ['role' => $role, 'canvas' => $canvas];
    }

    /**
     * @param array<string,mixed> $canvas
     */
    public function getCanvasRole(array $canvas, ?string $userId): ?string
    {
        if ($userId !== null && $canvas['owner_id'] === $userId) {
            return 'owner';
        }
        if ($userId !== null) {
            $accessRole = $this->canvases->findCanvasAccess($canvas['id'], $userId);
            if ($accessRole !== null) {
                return $accessRole;
            }
        }
        if ($canvas['access_level'] === 'public_link') {
            return 'viewer';
        }

        return null;
    }

    /**
     * @param array<string,mixed> $canvas
     */
    public function canViewCanvas(array $canvas, ?string $role): bool
    {
        if ($role === null) {
            return false;
        }

        return true;
    }

    public function canEditCanvas(?string $role): bool
    {
        return $role === 'owner' || $role === 'editor';
    }

    public function canManageCanvas(?string $role): bool
    {
        return $role === 'owner';
    }

    /**
     * @return array{note:array<string,mixed>,canvas:array<string,mixed>,role:string|null,effectiveEdit:bool,effectiveView:bool}
     */
    public function getNoteContext(string $noteId, ?string $userId): array
    {
        $note = $this->notes->findById($noteId);
        if ($note === null) {
            throw ApiException::notFound();
        }
        $canvas = $this->canvases->findById($note['canvas_id']);
        if ($canvas === null) {
            throw ApiException::notFound();
        }

        $canvasRole = $this->getCanvasRole($canvas, $userId);
        if (!$this->canViewCanvas($canvas, $canvasRole)) {
            throw ApiException::notFound();
        }

        $noteRole = null;
        if ($userId !== null) {
            $noteRole = $this->notes->findNoteAccess($noteId, $userId);
        }

        $isAuthor = $userId !== null && $note['author_id'] === $userId;
        $effectiveEdit = $this->canManageCanvas($canvasRole) || $canvasRole === 'editor' || $isAuthor || $noteRole === 'editor';
        $effectiveView = $effectiveEdit || $canvasRole === 'viewer' || $noteRole === 'viewer' || $isAuthor;

        if (!$effectiveView) {
            throw ApiException::notFound();
        }

        return [
            'note' => $note,
            'canvas' => $canvas,
            'role' => $canvasRole,
            'effectiveEdit' => $effectiveEdit,
            'effectiveView' => $effectiveView,
            'isAuthor' => $isAuthor,
        ];
    }

    public function assertVerified(string $userId): void
    {
        $user = $this->users->findById($userId);
        if ($user === null || !$user->isEmailVerified()) {
            throw ApiException::forbidden('EMAIL_NOT_VERIFIED', 'Email не подтверждён');
        }
    }
}
