<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

use Vistynq\Models\User;

/**
 * Contract of UserRepository (roadmap R17): services/controllers depend on
 * this instead of the concrete class, unit tests substitute in-memory fakes
 * (roadmap R15).
 */
interface UserRepositoryInterface
{
    public function findById(string $id): ?User;

    public function findByEmail(string $email): ?User;

    public function insert(string $email, string $passwordHash, string $displayName): User;

    public function markEmailVerified(string $id): void;

    public function updatePassword(string $userId, string $passwordHash): void;

    public function updateDisplayName(string $userId, string $displayName): void;

    public function setPendingEmail(string $userId, string $email): void;

    /**
     * Привязка/отвязка аватара (R17): null очищает ссылку.
     */
    public function setAvatarAttachmentId(string $userId, ?string $attachmentId): void;

    /**
     * Переносит pending_email в email (подтверждение смены адреса).
     * Нет строки с pending_email — ничего не происходит.
     */
    public function applyPendingEmail(string $userId): void;

    /**
     * Занят ли email у другого пользователя: колонка email или pending_email
     * (partial UNIQUE в 0004_align.sql).
     */
    public function emailTaken(string $email, string $exceptUserId): bool;
}
