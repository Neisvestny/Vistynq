<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

use Vistynq\Models\User;

/**
 * Read-side contract of UserRepository used by AccessService (roadmap R17).
 */
interface UserRepositoryInterface
{
    public function findById(string $id): ?User;
}
