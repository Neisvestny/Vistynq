<?php

declare(strict_types=1);

use Vistynq\Repositories\NoteRepositoryInterface;

/**
 * In-memory fake for AccessService unit tests (roadmap R15/R17).
 */
final class FakeNoteRepository implements NoteRepositoryInterface
{
    /** @var array<string, array<string,mixed>> */
    private array $notes = [];

    /** @var array<string, string> "noteId:user_id" => role */
    private array $access = [];

    /**
     * @param array<string,mixed> $note
     */
    public function add(array $note): void
    {
        $this->notes[(string) $note['id']] = $note;
    }

    public function grant(string $noteId, string $userId, string $role): void
    {
        $this->access[$noteId . ':' . $userId] = $role;
    }

    public function findById(string $id): ?array
    {
        return $this->notes[$id] ?? null;
    }

    public function findNoteAccess(string $noteId, string $userId): ?string
    {
        return $this->access[$noteId . ':' . $userId] ?? null;
    }
}
