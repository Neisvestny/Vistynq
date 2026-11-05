<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

/**
 * Read-side contract of NoteRepository used by AccessService (roadmap R17).
 */
interface NoteRepositoryInterface
{
    /**
     * @return array<string,mixed>|null
     */
    public function findById(string $id): ?array;

    /**
     * Role from note_access invite row, or null when no invite exists.
     */
    public function findNoteAccess(string $noteId, string $userId): ?string;
}
