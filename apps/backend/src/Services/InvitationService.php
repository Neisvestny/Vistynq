<?php

declare(strict_types=1);

namespace Vistynq\Services;

use Throwable;
use Vistynq\Config\Config;
use Vistynq\Repositories\AccessRepositoryInterface;
use Vistynq\Repositories\CanvasRepositoryInterface;
use Vistynq\Repositories\InvitationRepositoryInterface;
use Vistynq\Repositories\NoteRepositoryInterface;
use Vistynq\Repositories\UserRepositoryInterface;
use Vistynq\Support\ApiException;
use Vistynq\Support\Database;

/**
 * Приглашения и мгновенная выдача доступа (docs/api.md §Доступ,
 * docs/architecture.md §5).
 *
 * Аккаунт с таким email уже есть → строка в *_access сразу + письмо.
 * Аккаунта нет → pending-инвайт + письмо со ссылкой на регистрацию;
 * после подтверждения email pending-инвайты превращаются в доступы
 * (вызов из AuthService::verifyEmail).
 */
final class InvitationService
{
    private const ROLES = ['viewer', 'editor'];

    public function __construct(
        private readonly CanvasRepositoryInterface $canvases,
        private readonly NoteRepositoryInterface $notes,
        private readonly AccessRepositoryInterface $access,
        private readonly InvitationRepositoryInterface $invitations,
        private readonly UserRepositoryInterface $users,
        private readonly AccessService $accessService,
        private readonly MailServiceInterface $mail,
        private readonly Database $db,
        private readonly Config $config,
    ) {
    }

    /**
     * @return string granted|pending
     */
    public function inviteToCanvas(string $canvasId, string $inviterId, string $email, string $role): string
    {
        self::assertRole($role);
        $this->accessService->assertVerified($inviterId);
        $canvas = $this->accessService->requireCanvasManage($canvasId, $inviterId)['canvas'];
        $email = self::normalizeEmail($email);
        $this->assertNotSelf($inviterId, $email);

        $target = $this->users->findByEmail($email);
        if ($target === null) {
            $this->invitations->upsertPendingForCanvas($canvasId, $email, $role, $inviterId);
            $this->notifyInvite($email, 'canvas', (string) $canvas['title'], $role);

            return 'pending';
        }

        if ($target->id === (string) $canvas['owner_id']) {
            throw ApiException::validation(['email' => ['владелец уже имеет полный доступ']]);
        }
        if ($this->canvases->findCanvasAccess($canvasId, $target->id) !== null) {
            throw ApiException::conflict('Доступ уже выдан', ['email' => ['доступ уже существует']]);
        }

        $this->db->transaction(function () use ($canvasId, $email, $target, $role, $inviterId): void {
            $this->access->grantCanvas($canvasId, $target->id, $role, $inviterId);
            $pending = $this->invitations->findPendingForCanvasEmail($canvasId, $email);
            if ($pending !== null) {
                $this->invitations->accept((string) $pending['id']);
            }
        });

        $this->notifyGranted(
            $email,
            'canvas',
            (string) $canvas['title'],
            $role,
            $this->config->frontendUrl() . '/canvas/' . rawurlencode($canvasId),
        );

        return 'granted';
    }

    /**
     * @return string granted|pending
     */
    public function inviteToNote(string $noteId, string $inviterId, string $email, string $role): string
    {
        self::assertRole($role);
        $this->accessService->assertVerified($inviterId);
        $ctx = $this->accessService->requireNoteAccessManage($noteId, $inviterId);
        $note = $ctx['note'];
        $canvasId = (string) $note['canvas_id'];
        $email = self::normalizeEmail($email);
        $this->assertNotSelf($inviterId, $email);

        $target = $this->users->findByEmail($email);
        if ($target === null) {
            $this->invitations->upsertPendingForNote($noteId, $email, $role, $inviterId);
            $this->notifyInvite($email, 'note', (string) $note['title'], $role);

            return 'pending';
        }

        if ($target->id === (string) $note['author_id'] || $target->id === (string) $ctx['canvas']['owner_id']) {
            throw ApiException::validation(['email' => ['автор и владелец канваса уже имеют доступ']]);
        }
        if ($this->notes->findNoteAccess($noteId, $target->id) !== null) {
            throw ApiException::conflict('Доступ уже выдан', ['email' => ['доступ уже существует']]);
        }

        $this->db->transaction(function () use ($noteId, $email, $target, $role, $inviterId): void {
            $this->access->grantNote($noteId, $target->id, $role, $inviterId);
            $pending = $this->invitations->findPendingForNoteEmail($noteId, $email);
            if ($pending !== null) {
                $this->invitations->accept((string) $pending['id']);
            }
        });

        $this->notifyGranted(
            $email,
            'note',
            (string) $note['title'],
            $role,
            $this->config->frontendUrl() . '/canvas/' . rawurlencode($canvasId)
                . '?note=' . rawurlencode($noteId),
        );

        return 'granted';
    }

    /**
     * pending → accepted для всех инвайтов адреса + выдача доступов.
     * Идемпотентно: повторный вызов ничего не находит.
     *
     * @return int число принятых инвайтов
     */
    public function acceptPendingForEmail(string $email, string $userId): int
    {
        return $this->db->transaction(function () use ($email, $userId): int {
            $accepted = 0;
            foreach ($this->invitations->findPendingForEmail($email) as $row) {
                $grantedBy = $row['invited_by'] !== null ? (string) $row['invited_by'] : null;
                if ($row['canvas_id'] !== null) {
                    $this->access->grantCanvas(
                        (string) $row['canvas_id'],
                        $userId,
                        (string) $row['role'],
                        $grantedBy,
                    );
                } elseif ($row['note_id'] !== null) {
                    $this->access->grantNote(
                        (string) $row['note_id'],
                        $userId,
                        (string) $row['role'],
                        $grantedBy,
                    );
                } else {
                    continue;
                }
                $this->invitations->accept((string) $row['id']);
                $accepted++;
            }

            return $accepted;
        });
    }

    private function assertNotSelf(string $inviterId, string $email): void
    {
        $inviter = $this->users->findById($inviterId);
        if ($inviter !== null && strcasecmp($inviter->email, $email) === 0) {
            throw ApiException::validation(['email' => ['нельзя пригласить самого себя']]);
        }
    }

    private function notifyGranted(string $email, string $resourceType, string $title, string $role, string $link): void
    {
        try {
            $this->mail->sendAccessGrantedEmail($email, $resourceType, $title, $role, $link);
        } catch (Throwable $e) {
            // Письмо — best-effort: доступ уже в БД, 500 на клиенте вызвал бы
            // повторный invite и 409.
            error_log('[invite] granted mail failed: ' . $e->getMessage());
        }
    }

    private function notifyInvite(string $email, string $resourceType, string $title, string $role): void
    {
        $link = $this->config->frontendUrl() . '/register?email=' . rawurlencode($email);
        try {
            $this->mail->sendAccessInviteEmail($email, $resourceType, $title, $role, $link);
        } catch (Throwable $e) {
            error_log('[invite] invite mail failed: ' . $e->getMessage());
        }
    }

    private static function normalizeEmail(string $email): string
    {
        return trim($email);
    }

    private static function assertRole(string $role): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw ApiException::validation(['role' => ['допустимо viewer или editor']]);
        }
    }
}
