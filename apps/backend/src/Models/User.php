<?php

declare(strict_types=1);

namespace Vistynq\Models;

use DateTimeImmutable;

/**
 * User entity DTO (docs/data-model.md: users table).
 * Read-only: constructed from repository rows, serialized via toArray().
 */
final class User
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly string $displayName,
        public readonly ?string $passwordHash,
        public readonly ?string $avatarAttachmentId,
        public readonly ?DateTimeImmutable $emailVerifiedAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            email: (string) $row['email'],
            displayName: (string) $row['display_name'],
            passwordHash: isset($row['password_hash']) ? (string) $row['password_hash'] : null,
            avatarAttachmentId: $row['avatar_attachment_id'] !== null ? (string) $row['avatar_attachment_id'] : null,
            emailVerifiedAt: self::dateOrNull($row['email_verified_at'] ?? null),
            createdAt: self::date((string) $row['created_at']),
            updatedAt: self::dateOrNull($row['updated_at'] ?? null),
        );
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    /**
     * Public API shape (never exposes password_hash).
     *
     * @return array{
     *     id: string,
     *     email: string,
     *     displayName: string,
     *     avatarAttachmentId: string|null,
     *     emailVerified: bool,
     *     createdAt: string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'displayName' => $this->displayName,
            'avatarAttachmentId' => $this->avatarAttachmentId,
            'emailVerified' => $this->isEmailVerified(),
            'createdAt' => $this->createdAt->format(DATE_ATOM),
        ];
    }

    private static function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }

    private static function dateOrNull(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable((string) $value);
    }
}
