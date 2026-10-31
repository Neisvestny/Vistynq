<?php

declare(strict_types=1);

namespace Vistynq\Support;

use PDO;
use Vistynq\Config\Config;

/**
 * Lazy PDO holder registered in the container as a singleton.
 * Repositories depend on this instead of raw PDO (not autowirable).
 */
final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= new PDO(
            $this->config->dbDsn(),
            $this->config->dbUser(),
            $this->config->dbPass(),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 3,
            ],
        );
    }
}
