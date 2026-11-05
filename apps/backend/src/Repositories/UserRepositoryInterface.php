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
}
