<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

/**
 * Contract of CanvasRepository (roadmap R17): services/controllers depend on
 * this instead of the concrete class, unit tests substitute in-memory fakes
 * (roadmap R15).
 */
interface CanvasRepositoryInterface
{
    /**
     * @return array<string,mixed>|null
     */
    public function findById(string $id): ?array;

    /**
     * @return list<array<string,mixed>>
     */
    public function findForUser(string $userId): array;

    /**
     * @return array<string,mixed> the inserted row
     */
    public function create(string $ownerId, string $title): array;

    /**
     * Whitelisted columns only — mass assignment of owner_id / access_level /
     * public_slug must stay impossible.
     *
     * @param array<string,mixed> $fields
     */
    public function update(string $id, array $fields): void;

    /**
     * Hard delete with cascade (docs/ROADMAP.md R11, docs/data-model.md).
     */
    public function delete(string $id): void;

    /**
     * Role from canvas_access invite row, or null when no invite exists.
     */
    public function findCanvasAccess(string $canvasId, string $userId): ?string;
}
