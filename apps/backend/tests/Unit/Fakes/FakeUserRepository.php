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

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
