<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

use Vistynq\Models\User;
use Vistynq\Support\Database;

/**
 * users table (docs/data-model.md).
 */
final class UserRepository
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
}
