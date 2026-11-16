<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

use PDO;
use Vistynq\Support\Database;

final class DirectLinkRepository implements DirectLinkRepositoryInterface
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Outgoing direct links of the given source notes.
     *
     * Only the source side is filtered in SQL: the caller already knows the
     * set of notes visible to the user and drops edges whose target is not
     * in it. Binding the target list as well would double the placeholder
     * count against a single bound array (that bug made /graph return 500).
     *
     * The target side carries canvas_id/deleted_at so GraphService can mark
     * a soft-deleted target as state=removed with to=id for ≥editor (R12);
     * a purged target cannot appear here (FK ON DELETE CASCADE removes the
     * link row itself).
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
            "SELECT dl.id, dl.from_note_id, dl.to_note_id,
                    t.canvas_id AS target_canvas_id, t.deleted_at AS target_deleted_at
               FROM direct_links dl
               LEFT JOIN notes t ON t.id = dl.to_note_id
              WHERE dl.from_note_id IN ($in)"
        );
        $stmt->execute(array_values($fromNoteIds));

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
