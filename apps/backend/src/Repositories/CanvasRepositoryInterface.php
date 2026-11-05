<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

/**
 * Read-side contract of CanvasRepository used by AccessService (roadmap R17:
 * interfaces let unit tests substitute in-memory fakes for R15).
 */
interface CanvasRepositoryInterface
{
    /**
     * @return array<string,mixed>|null
     */
    public function findById(string $id): ?array;

    /**
     * Role from canvas_access invite row, or null when no invite exists.
     */
    public function findCanvasAccess(string $canvasId, string $userId): ?string;
}
