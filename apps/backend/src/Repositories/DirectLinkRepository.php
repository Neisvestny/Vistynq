<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

use Vistynq\Support\Database;

final class DirectLinkRepository
{
    public function __construct(private readonly Database $db)
    {
    }
}
