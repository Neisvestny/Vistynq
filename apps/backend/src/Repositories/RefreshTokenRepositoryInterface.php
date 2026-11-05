<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

/**
 * Contract of RefreshTokenRepository (roadmap R17): rotating refresh tokens
 * with family-based reuse detection (docs/data-model.md).
 */
interface RefreshTokenRepositoryInterface
{
    /**
     * @return array{id:string,user_id:string,family_id:string,token_hash:string,expires_at:string,revoked_at:?string,replaced_by:?string}|null
     */
    public function findByHash(string $hash): ?array;

    /**
     * LIKE findByHash, but takes a row lock (FOR UPDATE) inside a transaction
     * so concurrent rotations of the same refresh token are serialized.
     *
     * @return array{id:string,user_id:string,family_id:string,token_hash:string,expires_at:string,revoked_at:?string,replaced_by:?string}|null
     */
    public function findByHashForUpdate(string $hash): ?array;

    public function create(string $userId, string $familyId, string $hash, int $ttlSeconds): string;

    public function markReplaced(string $id, string $replacedById): void;

    public function revoke(string $id): void;

    public function revokeFamily(string $familyId): void;

    public function revokeAllForUser(string $userId): void;
}
