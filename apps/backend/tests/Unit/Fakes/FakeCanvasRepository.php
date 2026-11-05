<?php

declare(strict_types=1);

use Vistynq\Repositories\CanvasRepositoryInterface;

/**
 * In-memory fake for AccessService unit tests (roadmap R15/R17): no PDO,
 * rows are plain arrays seeded by the test.
 */
final class FakeCanvasRepository implements CanvasRepositoryInterface
{
    /** @var array<string, array<string,mixed>> */
    private array $canvases = [];

    /** @var array<string, string> "canvasId:user_id" => role */
    private array $access = [];

    /**
     * @param array<string,mixed> $canvas
     */
    public function add(array $canvas): void
    {
        $this->canvases[(string) $canvas['id']] = $canvas;
    }

    public function grant(string $canvasId, string $userId, string $role): void
    {
        $this->access[$canvasId . ':' . $userId] = $role;
    }

    public function findById(string $id): ?array
    {
        return $this->canvases[$id] ?? null;
    }

    public function findCanvasAccess(string $canvasId, string $userId): ?string
    {
        return $this->access[$canvasId . ':' . $userId] ?? null;
    }
}
