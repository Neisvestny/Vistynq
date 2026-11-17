<?php

declare(strict_types=1);

use Vistynq\Models\User;
use Vistynq\Repositories\UserRepositoryInterface;

/**
 * In-memory fake for AccessService unit tests (roadmap R15/R17).
 */
final class FakeUserRepository implements UserRepositoryInterface
{
    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, string> email => userId */
    private array $byEmail = [];

    public function add(User $user): void
    {
        $this->users[$user->id] = $user;
        $this->byEmail[$user->email] = $user->id;
    }

    public function findById(string $id): ?User
    {
        return $this->users[$id] ?? null;
    }

    public function findByEmail(string $email): ?User
    {
        $id = $this->byEmail[$email] ?? null;

        return $id === null ? null : $this->users[$id] ?? null;
    }

    public function insert(string $email, string $passwordHash, string $displayName): User
    {
        $user = new User(
            id: $this->uuid(),
            email: $email,
            displayName: $displayName,
            passwordHash: $passwordHash,
            avatarAttachmentId: null,
            emailVerifiedAt: null,
            createdAt: new DateTimeImmutable(),
        );
        $this->add($user);

        return $user;
    }

    public function markEmailVerified(string $id): void
    {
        $user = $this->users[$id] ?? null;
        if ($user === null) {
            return;
        }

        $this->users[$id] = new User(
            id: $user->id,
            email: $user->email,
            displayName: $user->displayName,
            passwordHash: $user->passwordHash,
            avatarAttachmentId: $user->avatarAttachmentId,
            emailVerifiedAt: new DateTimeImmutable(),
            createdAt: $user->createdAt,
            updatedAt: $user->updatedAt,
        );
    }

    public function updatePassword(string $userId, string $passwordHash): void
    {
        // Фейк не хранит смены пароля: контракт покрыт, поведение не проверяется.
    }

    public function updateDisplayName(string $userId, string $displayName): void
    {
        $user = $this->users[$userId] ?? null;
        if ($user === null) {
            return;
        }

        unset($this->byEmail[$user->email]);
        $this->users[$userId] = $this->rebuild($user, displayName: $displayName);
        $this->byEmail[$this->users[$userId]->email] = $userId;
    }

    public function setPendingEmail(string $userId, string $email): void
    {
        $user = $this->users[$userId] ?? null;
        if ($user === null) {
            return;
        }

        $this->users[$userId] = $this->rebuild($user, pendingEmail: $email);
    }

    public function setAvatarAttachmentId(string $userId, ?string $attachmentId): void
    {
        $user = $this->users[$userId] ?? null;
        if ($user === null) {
            return;
        }

        $this->users[$userId] = $this->rebuild($user, avatarAttachmentId: $attachmentId, setAvatar: true);
    }

    public function applyPendingEmail(string $userId): void
    {
        $user = $this->users[$userId] ?? null;
        if ($user === null || $user->pendingEmail === null) {
            return;
        }

        unset($this->byEmail[$user->email]);
        $this->users[$userId] = $this->rebuild($user, email: $user->pendingEmail, clearPending: true);
        $this->byEmail[$user->pendingEmail] = $userId;
    }

    public function emailTaken(string $email, string $exceptUserId): bool
    {
        foreach ($this->users as $id => $user) {
            if ($id === $exceptUserId) {
                continue;
            }
            if (strcasecmp($user->email, $email) === 0 || strcasecmp((string) $user->pendingEmail, $email) === 0) {
                return true;
            }
        }

        return false;
    }

    private function rebuild(
        User $user,
        ?string $email = null,
        ?string $displayName = null,
        ?string $pendingEmail = null,
        bool $clearPending = false,
        ?string $avatarAttachmentId = null,
        bool $setAvatar = false,
    ): User {
        return new User(
            id: $user->id,
            email: $email ?? $user->email,
            displayName: $displayName ?? $user->displayName,
            passwordHash: $user->passwordHash,
            avatarAttachmentId: $setAvatar ? $avatarAttachmentId : $user->avatarAttachmentId,
            emailVerifiedAt: $user->emailVerifiedAt,
            createdAt: $user->createdAt,
            updatedAt: new DateTimeImmutable(),
            pendingEmail: $clearPending ? null : ($pendingEmail ?? $user->pendingEmail),
        );
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
