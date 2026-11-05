<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

use Vistynq\Support\Database;

/**
 * auth_tokens table: one-time tokens for email verification and password reset.
 * Only the sha256 hash of a token is stored (docs/data-model.md).
 */
final class AuthTokenRepository implements AuthTokenRepositoryInterface
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(string $userId, string $purpose, string $tokenHash, int $ttlSeconds): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO auth_tokens (user_id, purpose, token_hash, expires_at)
             VALUES (:user_id, :purpose, :token_hash, now() + make_interval(secs => :ttl))',
        );
        $stmt->execute([
            'user_id' => $userId,
            'purpose' => $purpose,
            'token_hash' => $tokenHash,
            'ttl' => $ttlSeconds,
        ]);
    }

    /**
     * @return array{id: string, user_id: string}|null
     */
    public function findValid(string $tokenHash, string $purpose): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, user_id FROM auth_tokens
             WHERE token_hash = :token_hash AND purpose = :purpose
               AND used_at IS NULL AND expires_at > now()',
        );
        $stmt->execute(['token_hash' => $tokenHash, 'purpose' => $purpose]);
        $row = $stmt->fetch();

        return $row === false ? null : ['id' => (string) $row['id'], 'user_id' => (string) $row['user_id']];
    }

    public function markUsed(string $id): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE auth_tokens SET used_at = now() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function invalidatePending(string $userId, string $purpose): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE auth_tokens SET used_at = now()
             WHERE user_id = :user_id AND purpose = :purpose AND used_at IS NULL',
        );
        $stmt->execute(['user_id' => $userId, 'purpose' => $purpose]);
    }
}
