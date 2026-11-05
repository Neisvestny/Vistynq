<?php

declare(strict_types=1);

namespace Vistynq\Controllers;

use Vistynq\Http\Request;
use Vistynq\Http\Response;
use Vistynq\Support\Database;

/**
 * GET /healthz, GET /api/v1/healthz — liveness + database probe.
 * Behavior is contract-sensitive: compose/nginx healthchecks depend on it.
 *
 * Тонкий контроллер (roadmap R17): сама проба (отдельное соединение с
 * коротким таймаутом) живёт в Database::ping(), здесь только статус.
 */
final class HealthController
{
    public function __construct(private readonly Database $db)
    {
    }

    public function check(Request $request): Response
    {
        $reason = $this->db->ping(2);
        if ($reason === null) {
            return Response::json(['status' => 'ok']);
        }

        return Response::json(['status' => 'unavailable', 'error' => 'database', 'reason' => $reason], 503);
    }
}
