<?php

declare(strict_types=1);

namespace Vistynq\Controllers;

use PDO;
use Throwable;
use Vistynq\Config\Config;
use Vistynq\Http\Request;
use Vistynq\Http\Response;

/**
 * GET /healthz, GET /api/v1/healthz — liveness + database probe.
 * Behavior is contract-sensitive: compose/nginx healthchecks depend on it.
 */
final class HealthController
{
    public function __construct(private readonly Config $config)
    {
    }

    public function check(Request $request): Response
    {
        $reason = $this->databaseFailureReason();
        if ($reason === null) {
            return Response::json(['status' => 'ok']);
        }

        return Response::json(['status' => 'unavailable', 'error' => 'database', 'reason' => $reason], 503);
    }

    private function databaseFailureReason(): ?string
    {
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            error_log('[healthz] pdo_pgsql extension is not loaded');

            return 'pdo_pgsql_missing';
        }

        try {
            $pdo = new PDO(
                $this->config->dbDsn(),
                $this->config->dbUser(),
                $this->config->dbPass(),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 2,
                ],
            );
            $pdo->query('SELECT 1');
        } catch (Throwable $e) {
            error_log('[healthz] database check failed: ' . $e->getMessage());

            return 'database_unreachable';
        }

        return null;
    }
}
