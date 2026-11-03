<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

use PDO;
use Vistynq\Support\Database;

final class IndirectLinkRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function deleteForSource(string $fromNoteId): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM indirect_links WHERE from_note_id = :fromNoteId');
        $stmt->execute(['fromNoteId' => $fromNoteId]);
    }

    public function insert(string $fromNoteId, ?string $toNoteId, string $rawTarget, bool $resolved): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO indirect_links (from_note_id, to_note_id, raw_target, resolved) VALUES (:from_note_id, :to_note_id, :raw_target, :resolved)'
        );
        $stmt->execute([
            'from_note_id' => $fromNoteId,
            'to_note_id' => $toNoteId,
            'raw_target' => $rawTarget,
            'resolved' => $resolved ? 'true' : 'false',
        ]);
    }

    /**
     * Outgoing wikilinks of the given source notes, with the target's canvas
     * and deletion state so the caller can tell apart:
     *  - target missing (purged / never resolved)  → «ссылка удалена»
     *  - target visible                             → edge ok
     *  - target soft-deleted in the same canvas     → «ссылка удалена» (restore marker)
     *  - target in another canvas                   → edge dropped entirely
     *
     * @param list<string> $fromNoteIds
     * @return list<array<string,mixed>>
     */
    public function findEdgesBySource(array $fromNoteIds): array
    {
        if ($fromNoteIds === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($fromNoteIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT il.id, il.from_note_id, il.to_note_id, il.raw_target, il.resolved,'
            . ' t.canvas_id AS target_canvas_id, t.deleted_at AS target_deleted_at'
            . ' FROM indirect_links il LEFT JOIN notes t ON t.id = il.to_note_id'
            . " WHERE il.from_note_id IN ($in)"
        );
        $stmt->execute(array_values($fromNoteIds));

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
