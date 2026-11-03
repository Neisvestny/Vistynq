<?php

declare(strict_types=1);

namespace Vistynq\Services;

use Vistynq\Repositories\CanvasRepository;
use Vistynq\Repositories\NoteRepository;
use Vistynq\Repositories\UserRepository;
use Vistynq\Support\ApiException;

/**
 * Единая точка принятия решений о доступе (docs/architecture.md §5).
 *
 * Соглашение об ответах:
 *  - объекта «не существует» для вызывающего → 404 (политика приватности,
 *    docs/ROADMAP.md R11: недоступное → 404, чтобы не раскрывать существование);
 *  - объект виден, но роли не хватает на действие → 403 (docs/openapi.yaml).
 *
 * Права на заметку = max(права на канвас, собственные права заметки);
 * автор заметки всегда может свою заметку (docs/api.md).
 */
final class AccessService
{
    public function __construct(
        private readonly CanvasRepository $canvases,
        private readonly NoteRepository $notes,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * Контекст канваса. 404 — канваса нет либо у вызывающего нет прав на чтение.
     *
     * @return array{role:string,canvas:array<string,mixed>}
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

        return ['role' => $role ?? 'none', 'canvas' => $canvas];
    }

    /**
     * Чтение + право редактировать канвас (≥editor). 403 при нехватке роли.
     *
     * @return array{role:string,canvas:array<string,mixed>}
     */
    public function requireCanvasEdit(string $canvasId, string $userId): array
    {
        $ctx = $this->getCanvasContext($canvasId, $userId);
        if (!$this->canEditCanvas($ctx['role'])) {
            throw ApiException::forbidden();
        }

        return $ctx;
    }

    /**
     * Управление канвасом (owner). 403 для editor/viewer.
     *
     * @return array{role:string,canvas:array<string,mixed>}
     */
    public function requireCanvasManage(string $canvasId, string $userId): array
    {
        $ctx = $this->getCanvasContext($canvasId, $userId);
        if (!$this->canManageCanvas($ctx['role'])) {
            throw ApiException::forbidden();
        }

        return $ctx;
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
     * Контекст заметки. 404 — заметки/канваса нет либо прав на чтение нет.
     *
     * @return array{
     *     note:array<string,mixed>,
     *     canvas:array<string,mixed>,
     *     role:string,
     *     effectiveEdit:bool,
     *     effectiveView:bool,
     *     isAuthor:bool,
     *     noteRole:string|null
     * }
     */
    public function getNoteContext(string $noteId, ?string $userId): array
    {
        $ctx = $this->buildNoteContext($noteId, $userId);
        if (!$ctx['effectiveView']) {
            throw ApiException::notFound();
        }

        return $ctx;
    }

    /**
     * Чтение + право редактировать заметку (редактор* из docs/api.md:
     * автор ∪ editor канваса ∪ editor-инвайт самой заметки). 403 при нехватке.
     *
     * @return array{
     *     note:array<string,mixed>,
     *     canvas:array<string,mixed>,
     *     role:string,
     *     effectiveEdit:bool,
     *     effectiveView:bool,
     *     isAuthor:bool,
     *     noteRole:string|null
     * }
     */
    public function requireNoteEdit(string $noteId, string $userId): array
    {
        $ctx = $this->getNoteContext($noteId, $userId);
        if (!$ctx['effectiveEdit']) {
            throw ApiException::forbidden();
        }

        return $ctx;
    }

    /**
     * Чтение + право управлять заметкой в рамках канваса — restore/purge
     * (docs/api.md: «≥editor канваса»; матрица прав: автор ✔¹, editor-инвайт
     * самой заметки ✖). 403 при нехватке.
     *
     * @return array{
     *     note:array<string,mixed>,
     *     canvas:array<string,mixed>,
     *     role:string,
     *     effectiveEdit:bool,
     *     effectiveView:bool,
     *     isAuthor:bool,
     *     noteRole:string|null
     * }
     */
    public function requireNoteManage(string $noteId, string $userId): array
    {
        $ctx = $this->getNoteContext($noteId, $userId);
        if (!$this->canEditCanvas($ctx['role']) && !$ctx['isAuthor']) {
            throw ApiException::forbidden();
        }

        return $ctx;
    }

    /**
     * Свёртка прав заметки в одну роль для NoteDetail.myRole
     * (docs/openapi.yaml: owner | author | editor | viewer | none).
     *
     * @param array{
     *     role:string,
     *     effectiveEdit:bool,
     *     effectiveView:bool,
     *     isAuthor:bool,
     *     noteRole:string|null
     * } $ctx
     */
    public function describeNoteRole(array $ctx): string
    {
        if ($ctx['role'] === 'owner' || $ctx['role'] === 'editor') {
            return $ctx['role'];
        }
        if ($ctx['isAuthor']) {
            return 'author';
        }
        if ($ctx['noteRole'] !== null) {
            return $ctx['noteRole'];
        }

        return $ctx['role'] === 'viewer' ? 'viewer' : 'none';
    }

    public function assertVerified(string $userId): void
    {
        $user = $this->users->findById($userId);
        if ($user === null || !$user->isEmailVerified()) {
            throw ApiException::forbidden('EMAIL_NOT_VERIFIED', 'Email не подтверждён');
        }
    }

    /**
     * Собирает контекст заметки без выброса по правам — чтобы вызывающий
     * мог отличить «нет доступа» (404) от «доступ есть, прав не хватает» (403).
     *
     * @return array{
     *     note:array<string,mixed>,
     *     canvas:array<string,mixed>,
     *     role:string,
     *     effectiveEdit:bool,
     *     effectiveView:bool,
     *     isAuthor:bool,
     *     noteRole:string|null
     * }
     */
    private function buildNoteContext(string $noteId, ?string $userId): array
    {
        $note = $this->notes->findById($noteId);
        if ($note === null) {
            throw ApiException::notFound();
        }
        $canvas = $this->canvases->findById($note['canvas_id']);
        if ($canvas === null) {
            // Заметка пережила канвас — недостижимо при каскаде, но 404 безопаснее 500.
            throw ApiException::notFound();
        }

        $canvasRole = $this->getCanvasRole($canvas, $userId);
        $noteRole = $userId !== null ? $this->notes->findNoteAccess($noteId, $userId) : null;
        $isAuthor = $userId !== null && $note['author_id'] === $userId;

        $effectiveView = $this->canViewCanvas($canvas, $canvasRole) || $noteRole !== null || $isAuthor;
        $effectiveEdit = $this->canEditCanvas($canvasRole) || $isAuthor || $noteRole === 'editor';

        return [
            'note' => $note,
            'canvas' => $canvas,
            'role' => $canvasRole ?? 'none',
            'effectiveEdit' => $effectiveEdit && $effectiveView,
            'effectiveView' => $effectiveView,
            'isAuthor' => $isAuthor,
            'noteRole' => $noteRole,
        ];
    }
}
