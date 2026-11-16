<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

use Vistynq\Models\User;
use Vistynq\Support\Database;

/**
 * users table (docs/data-model.md).
 */
final class UserRepository implements UserRepositoryInterface
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findById(string $id): ?User
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : User::fromRow($row);
    }

    public function findByEmail(string $email): ?User
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        return $row === false ? null : User::fromRow($row);
    }

    public function insert(string $email, string $passwordHash, string $displayName): User
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users (email, password_hash, display_name)
             VALUES (:email, :password_hash, :display_name)
             RETURNING *',
        );
        $stmt->execute([
            'email' => $email,
            'password_hash' => $passwordHash,
            'display_name' => $displayName,
        ]);

        return User::fromRow($stmt->fetch());
    }

    public function markEmailVerified(string $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET email_verified_at = COALESCE(email_verified_at, now()), updated_at = now()
             WHERE id = :id',
        );
        $stmt->execute(['id' => $id]);
    }

    public function updatePassword(string $userId, string $passwordHash): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE users SET password_hash = :password_hash, updated_at = now() WHERE id = :id');
        $stmt->execute(['id' => $userId, 'password_hash' => $passwordHash]);
    }

    public function updateDisplayName(string $userId, string $displayName): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE users SET display_name = :display_name, updated_at = now() WHERE id = :id');
        $stmt->execute(['id' => $userId, 'display_name' => $displayName]);
    }

    public function setPendingEmail(string $userId, string $email): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE users SET pending_email = :pending_email, updated_at = now() WHERE id = :id');
        $stmt->execute(['id' => $userId, 'pending_email' => $email]);
    }

    public function applyPendingEmail(string $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET email = pending_email, pending_email = NULL, updated_at = now()
              WHERE id = :id AND pending_email IS NOT NULL',
        );
        $stmt->execute(['id' => $userId]);
    }

    public function emailTaken(string $email, string $exceptUserId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM users
              WHERE (email = :email OR pending_email = :email) AND id <> :id
              LIMIT 1',
        );
        $stmt->execute(['email' => $email, 'id' => $exceptUserId]);

        return $stmt->fetch() !== false;
    }
}
