<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

/**
 * Contract of AuthTokenRepository (roadmap R17): one-time tokens for email
 * verification and password reset; only the sha256 hash is stored.
 */
interface AuthTokenRepositoryInterface
{
    public function create(string $userId, string $purpose, string $tokenHash, int $ttlSeconds): void;

    /**
     * @return array{id: string, user_id: string}|null
     */
    public function findValid(string $tokenHash, string $purpose): ?array;

    public function markUsed(string $id): void;

    public function invalidatePending(string $userId, string $purpose): void;
}
