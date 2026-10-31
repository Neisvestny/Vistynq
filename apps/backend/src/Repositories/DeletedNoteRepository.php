<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

use PDO;
use Vistynq\Support\Database;

final class DeletedNoteRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function create(string $noteId, ?string $canvasId, string $title, ?string $deletedBy): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO deleted_notes (note_id, canvas_id, title_snapshot, deleted_by) VALUES (:note_id, :canvas_id, :title, :deleted_by) ON CONFLICT (note_id) DO NOTHING'
        );
        $stmt->execute([
            'note_id' => $noteId,
            'canvas_id' => $canvasId,
            'title' => $title,
            'deleted_by' => $deletedBy,
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function findByCanvas(string $canvasId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM deleted_notes WHERE canvas_id = :canvasId ORDER BY deleted_at DESC');
        $stmt->execute(['canvasId' => $canvasId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function delete(string $noteId): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM deleted_notes WHERE note_id = :noteId');
        $stmt->execute(['noteId' => $noteId]);
    }
}
