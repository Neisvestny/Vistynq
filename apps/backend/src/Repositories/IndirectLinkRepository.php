<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

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
}
