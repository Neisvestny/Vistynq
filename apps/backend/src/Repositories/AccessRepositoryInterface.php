<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

/**
 * Contract of AccessRepository (roadmap R16): canvas_access / note_access
 * grants and the combined access lists (grants + pending invitations).
 */
interface AccessRepositoryInterface
{
    /**
     * Участники канваса + pending-инвайты, отсортированы по email.
     *
     * @return list<array{
     *     user_id: string|null,
     *     email: string,
     *     role: string,
     *     invitation_id: string|null,
     *     pending: bool
     * }>
     */
    public function listCanvasEntries(string $canvasId): array;

    /**
     * @return list<array{
     *     user_id: string|null,
     *     email: string,
     *     role: string,
     *     invitation_id: string|null,
     *     pending: bool
     * }>
     */
    public function listNoteEntries(string $noteId): array;

    /**
     * Upsert роли участника канваса (повторный grant меняет роль).
     */
    public function grantCanvas(string $canvasId, string $userId, string $role, ?string $grantedBy): void;

    public function grantNote(string $noteId, string $userId, string $role, ?string $grantedBy): void;

    /**
     * @return bool false, если гранта не было
     */
    public function updateCanvasRole(string $canvasId, string $userId, string $role): bool;

    public function updateNoteRole(string $noteId, string $userId, string $role): bool;

    /**
     * @return bool false, если гранта не было
     */
    public function revokeCanvas(string $canvasId, string $userId): bool;

    public function revokeNote(string $noteId, string $userId): bool;
}
