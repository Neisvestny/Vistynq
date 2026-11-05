<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

/**
 * Contract of DeletedNoteRepository (roadmap R17): tombstones of purged
 * notes (migrations/0002_domain.sql: no FK to notes, tombstone survives
 * its note by design).
 */
interface DeletedNoteRepositoryInterface
{
    /**
     * Tombstone survives its note by design (migrations/0002_domain.sql):
     * no FK to notes, created on purge only.
     */
    public function create(string $noteId, ?string $canvasId, string $title, ?string $deletedBy): void;

    /**
     * @return array<string,mixed>|null
     */
    public function findByNoteId(string $noteId): ?array;

    /**
     * @return list<array<string,mixed>>
     */
    public function findByCanvas(string $canvasId): array;

    public function delete(string $noteId): void;

    /**
     * Tombstones of a canvas that is being hard-deleted.
     */
    public function deleteForCanvas(string $canvasId): void;
}
