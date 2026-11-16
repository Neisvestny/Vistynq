<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

/**
 * Contract of InvitationRepository (roadmap R16): pending invitations on
 * canvas/note email, and their transition to accepted/revoked.
 */
interface InvitationRepositoryInterface
{
    /**
     * @return array<string,mixed>|null
     */
    public function findPendingForCanvasEmail(string $canvasId, string $email): ?array;

    /**
     * @return array<string,mixed>|null
     */
    public function findPendingForNoteEmail(string $noteId, string $email): ?array;

    /**
     * @return array<string,mixed> the stored invitation row
     */
    public function upsertPendingForCanvas(string $canvasId, string $email, string $role, ?string $invitedBy): array;

    /**
     * @return array<string,mixed> the stored invitation row
     */
    public function upsertPendingForNote(string $noteId, string $email, string $role, ?string $invitedBy): array;

    /**
     * @return array<string,mixed>|null
     */
    public function findPendingById(string $invitationId): ?array;

    /**
     * Все pending-инвайты адреса с блокировкой строк (вызывать в транзакции).
     *
     * @return list<array<string,mixed>>
     */
    public function findPendingForEmail(string $email): array;

    public function accept(string $invitationId): void;

    /**
     * @return bool false, если инвайт уже не pending
     */
    public function revoke(string $invitationId): bool;
}
