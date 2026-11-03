<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

use PDO;
use Vistynq\Support\Database;

final class DirectLinkRepository
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
            "SELECT id, from_note_id, to_note_id FROM direct_links WHERE from_note_id IN ($in)"
        );
        $stmt->execute(array_values($fromNoteIds));

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
