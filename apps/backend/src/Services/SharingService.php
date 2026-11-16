<?php

declare(strict_types=1);

namespace Vistynq\Services;

use PDOException;
use RuntimeException;
use Vistynq\Repositories\AccessRepositoryInterface;
use Vistynq\Repositories\CanvasRepositoryInterface;
use Vistynq\Repositories\InvitationRepositoryInterface;
use Vistynq\Repositories\NoteRepositoryInterface;
use Vistynq\Support\ApiException;
use Vistynq\Support\Slugger;

/**
 * Управление доступом к канвасу/заметке (docs/api.md §Доступ, R16):
 * уровень публичности, видимость заметки, список участников, смена роли,
 * отзыв доступа и ожидающего инвайта. Выдача по email — InvitationService.
 *
 * Права: канвасом управляет только owner; заметкой — owner канваса или
 * автор заметки (canvas-editor не управляет видимостью и доступами).
 */
final class SharingService
{
    private const ROLES = ['viewer', 'editor'];
    private const LEVELS = ['private', 'public'];
    private const VISIBILITIES = ['public', 'private'];
    private const SLUG_ATTEMPTS = 5;

    public function __construct(
        private readonly CanvasRepositoryInterface $canvases,
        private readonly NoteRepositoryInterface $notes,
        private readonly AccessRepositoryInterface $access,
        private readonly InvitationRepositoryInterface $invitations,
        private readonly AccessService $accessService,
        private readonly InvitationService $invitationService,
    ) {
    }

    /**
     * @return list<array{
     *     userId: string|null,
     *     email: string,
     *     role: string,
     *     pending: bool,
     *     invitationId: string|null
     * }>
     */
    public function listCanvasAccess(string $canvasId, string $userId): array
    {
        $this->accessService->requireCanvasManage($canvasId, $userId);

        return $this->mapEntries($this->access->listCanvasEntries($canvasId));
    }

    /**
     * @return array{level:string,publicSlug:string|null}
     */
    public function setCanvasLevel(string $canvasId, string $userId, string $level): array
    {
        self::assertLevel($level);
        $this->accessService->assertVerified($userId);
        $this->accessService->requireCanvasManage($canvasId, $userId);

        if ($level === 'public') {
            return ['level' => 'public', 'publicSlug' => $this->assignPublicSlug($canvasId)];
        }

        $this->canvases->setAccessLevel($canvasId, 'private', null);

        return ['level' => 'private', 'publicSlug' => null];
    }

    /**
     * @return string granted|pending
     */
    public function inviteToCanvas(string $canvasId, string $userId, string $email, string $role): string
    {
        return $this->invitationService->inviteToCanvas($canvasId, $userId, $email, $role);
    }

    public function updateCanvasRole(string $canvasId, string $userId, string $targetUserId, string $role): void
    {
        self::assertRole($role);
        $this->accessService->assertVerified($userId);
        $this->accessService->requireCanvasManage($canvasId, $userId);

        if (!$this->access->updateCanvasRole($canvasId, $targetUserId, $role)) {
            throw ApiException::notFound('Участник не найден');
        }
    }

    public function revokeCanvas(string $canvasId, string $userId, string $targetUserId): void
    {
        $this->accessService->assertVerified($userId);
        $this->accessService->requireCanvasManage($canvasId, $userId);

        if (!$this->access->revokeCanvas($canvasId, $targetUserId)) {
            throw ApiException::notFound('Участник не найден');
        }
    }

    public function revokeCanvasInvitation(string $canvasId, string $userId, string $invitationId): void
    {
        $this->accessService->assertVerified($userId);
        $this->accessService->requireCanvasManage($canvasId, $userId);

        $invitation = $this->invitations->findPendingById($invitationId);
        if ($invitation === null || (string) ($invitation['canvas_id'] ?? '') !== $canvasId) {
            throw ApiException::notFound('Инвайт не найден');
        }
        if (!$this->invitations->revoke($invitationId)) {
            throw ApiException::notFound('Инвайт не найден');
        }
    }

    /**
     * @return list<array{
     *     userId: string|null,
     *     email: string,
     *     role: string,
     *     pending: bool,
     *     invitationId: string|null
     * }>
     */
    public function listNoteAccess(string $noteId, string $userId): array
    {
        $this->accessService->requireNoteAccessManage($noteId, $userId);

        return $this->mapEntries($this->access->listNoteEntries($noteId));
    }

    /**
     * @return array{visibility:string}
     */
    public function setVisibility(string $noteId, string $userId, string $visibility): array
    {
        self::assertVisibility($visibility);
        $this->accessService->assertVerified($userId);
        $this->accessService->requireNoteAccessManage($noteId, $userId);
        $this->notes->setVisibility($noteId, $visibility);

        return ['visibility' => $visibility];
    }

    /**
     * @return string granted|pending
     */
    public function inviteToNote(string $noteId, string $userId, string $email, string $role): string
    {
        return $this->invitationService->inviteToNote($noteId, $userId, $email, $role);
    }

    public function updateNoteRole(string $noteId, string $userId, string $targetUserId, string $role): void
    {
        self::assertRole($role);
        $this->accessService->assertVerified($userId);
        $this->accessService->requireNoteAccessManage($noteId, $userId);

        if (!$this->access->updateNoteRole($noteId, $targetUserId, $role)) {
            throw ApiException::notFound('Участник не найден');
        }
    }

    public function revokeNote(string $noteId, string $userId, string $targetUserId): void
    {
        $this->accessService->assertVerified($userId);
        $this->accessService->requireNoteAccessManage($noteId, $userId);

        if (!$this->access->revokeNote($noteId, $targetUserId)) {
            throw ApiException::notFound('Участник не найден');
        }
    }

    public function revokeNoteInvitation(string $noteId, string $userId, string $invitationId): void
    {
        $this->accessService->assertVerified($userId);
        $this->accessService->requireNoteAccessManage($noteId, $userId);

        $invitation = $this->invitations->findPendingById($invitationId);
        if ($invitation === null || (string) ($invitation['note_id'] ?? '') !== $noteId) {
            throw ApiException::notFound('Инвайт не найден');
        }
        if (!$this->invitations->revoke($invitationId)) {
            throw ApiException::notFound('Инвайт не найден');
        }
    }

    /**
     * Каждый PUT public ротирует slug: старая ссылка перестаёт работать
     * (docs/api.md). Коллизия UNIQUE(public_slug) — повтор генерации.
     */
    private function assignPublicSlug(string $canvasId): string
    {
        for ($attempt = 1; $attempt <= self::SLUG_ATTEMPTS; $attempt++) {
            $slug = Slugger::generate();
            try {
                $this->canvases->setAccessLevel($canvasId, 'public', $slug);

                return $slug;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23505' || $attempt === self::SLUG_ATTEMPTS) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Не удалось сгенерировать уникальный публичный slug');
    }

    /**
     * @param list<array{
     *     user_id: string|null,
     *     email: string,
     *     role: string,
     *     invitation_id: string|null,
     *     pending: bool
     * }> $rows
     *
     * @return list<array{
     *     userId: string|null,
     *     email: string,
     *     role: string,
     *     pending: bool,
     *     invitationId: string|null
     * }>
     */
    private function mapEntries(array $rows): array
    {
        return array_map(
            static fn (array $row): array => [
                'userId' => $row['user_id'] !== null ? (string) $row['user_id'] : null,
                'email' => (string) $row['email'],
                'role' => (string) $row['role'],
                'pending' => (bool) $row['pending'],
                'invitationId' => $row['invitation_id'] !== null ? (string) $row['invitation_id'] : null,
            ],
            $rows,
        );
    }

    private static function assertRole(string $role): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw ApiException::validation(['role' => ['допустимо viewer или editor']]);
        }
    }

    private static function assertLevel(string $level): void
    {
        if (!in_array($level, self::LEVELS, true)) {
            throw ApiException::validation(['level' => ['допустимо private или public']]);
        }
    }

    private static function assertVisibility(string $visibility): void
    {
        if (!in_array($visibility, self::VISIBILITIES, true)) {
            throw ApiException::validation(['visibility' => ['допустимо public или private']]);
        }
    }
}
