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

    public function add(User $user): void
    {
        $this->users[$user->id] = $user;
    }

    public function findById(string $id): ?User
    {
        return $this->users[$id] ?? null;
    }
}
