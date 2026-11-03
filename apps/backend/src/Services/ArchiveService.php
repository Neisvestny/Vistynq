<?php

declare(strict_types=1);

namespace Vistynq\Services;

use Vistynq\Models\Note;
use Vistynq\Repositories\DeletedNoteRepository;
use Vistynq\Repositories\NoteRepository;

/**
 * Архив канваса (docs/api.md §Канвасы, ROADMAP R12): «неактуально» — живые
 * is_relevant=false; «удалённые» — soft-deleted заметки плюс tombstone'ы
 * purged-заметок (две выборки не пересекаются: заметка либо в notes, либо
 * в deleted_notes).
 */
final class ArchiveService
{
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly DeletedNoteRepository $deletedNotes,
        private readonly AccessService $access,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $canvasId, string $userId): array
    {
        // ≥editor (docs/api.md): viewer видит канвас, но не архив → 403, не 404
        $this->access->requireCanvasEdit($canvasId, $userId);

        $notRelevant = array_map(
            static fn (array $row): array => Note::fromRow($row)->toArray(),
            $this->notes->findNotRelevant($canvasId),
        );

        $deleted = [];
        foreach ($this->notes->findDeleted($canvasId) as $row) {
            $deleted[] = Note::fromRow($row)->toArray();
        }
        foreach ($this->deletedNotes->findByCanvas($canvasId) as $row) {
            $deleted[] = Note::tombstoneToArray($row);
        }

        usort($deleted, static function (array $a, array $b): int {
            $left = strtotime((string) ($a['deletedAt'] ?? '')) ?: 0;
            $right = strtotime((string) ($b['deletedAt'] ?? '')) ?: 0;

            return $right <=> $left;
        });

        return [
            'notRelevant' => $notRelevant,
            'deleted' => $deleted,
        ];
    }
}
