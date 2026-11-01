<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

use DateTimeImmutable;
use PDO;
use Vistynq\Support\Database;

final class RefreshTokenRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{id:string,user_id:string,family_id:string,token_hash:string,expires_at:string,revoked_at:?string,replaced_by:?string}|null
     */
    public function findByHash(string $hash): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, user_id, family_id, token_hash, expires_at, revoked_at, replaced_by FROM refresh_tokens WHERE token_hash = :hash'
        );
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * LIKE findByHash, but takes a row lock (FOR UPDATE) inside a transaction
     * so concurrent rotations of the same refresh token are serialized.
     *
     * @return array{id:string,user_id:string,family_id:string,token_hash:string,expires_at:string,revoked_at:?string,replaced_by:?string}|null
     */
    public function findByHashForUpdate(string $hash): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, user_id, family_id, token_hash, expires_at, revoked_at, replaced_by
             FROM refresh_tokens
             WHERE token_hash = :hash
             FOR UPDATE'
        );
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function create(string $userId, string $familyId, string $hash, int $ttlSeconds): string
    {
        $expiresAt = (new DateTimeImmutable(sprintf('+%d seconds', $ttlSeconds)))->format('Y-m-d H:i:sP');
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO refresh_tokens (user_id, family_id, token_hash, expires_at) VALUES (:user_id, :family_id, :hash, :expires_at) RETURNING id'
        );
        $stmt->execute([
            'user_id' => $userId,
            'family_id' => $familyId,
            'hash' => $hash,
            'expires_at' => $expiresAt,
        ]);

        $id = $stmt->fetchColumn();

        return is_string($id) ? $id : '';
    }

    public function markReplaced(string $id, string $replacedById): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE refresh_tokens SET revoked_at = COALESCE(revoked_at, now()), replaced_by = :replaced_by WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'replaced_by' => $replacedById]);
    }

    public function revoke(string $id): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE refresh_tokens SET revoked_at = COALESCE(revoked_at, now()) WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function revokeFamily(string $familyId): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE refresh_tokens SET revoked_at = COALESCE(revoked_at, now()) WHERE family_id = :family_id AND revoked_at IS NULL');
        $stmt->execute(['family_id' => $familyId]);
    }

    public function revokeAllForUser(string $userId): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE refresh_tokens SET revoked_at = COALESCE(revoked_at, now()) WHERE user_id = :user_id AND revoked_at IS NULL');
        $stmt->execute(['user_id' => $userId]);
    }
}
