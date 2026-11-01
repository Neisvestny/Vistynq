<?php

declare(strict_types=1);

namespace Vistynq\Services;

use Vistynq\Repositories\IndirectLinkRepository;
use Vistynq\Repositories\NoteRepository;
use Vistynq\Support\Database;

final class LinkParser
{
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly IndirectLinkRepository $indirectLinks,
        private readonly Database $db,
    ) {
    }

    public function parseAndStore(string $canvasId, string $noteId, string $bodyMd): void
    {
        $this->indirectLinks->deleteForSource($noteId);

        preg_match_all('/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/', $bodyMd, $matches, PREG_SET_ORDER);
        if ($matches === []) {
            return;
        }

        $pdo = $this->db->pdo();

        foreach ($matches as $match) {
            $rawTarget = trim($match[1]);
            $toNoteId = null;
            $resolved = false;

            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $rawTarget)) {
                $candidate = $this->notes->findById($rawTarget);
                if ($candidate !== null && $candidate['canvas_id'] === $canvasId && $candidate['deleted_at'] === null) {
                    $toNoteId = $candidate['id'];
                    $resolved = true;
                }
            }

            if (!$resolved) {
                $stmt = $pdo->prepare('SELECT id FROM notes WHERE canvas_id = :canvasId AND lower(title) = lower(:title) AND deleted_at IS NULL LIMIT 1');
                $stmt->execute(['canvasId' => $canvasId, 'title' => $rawTarget]);
                $found = $stmt->fetchColumn();
                if (is_string($found) && $found !== '') {
                    $toNoteId = $found;
                    $resolved = true;
                }
            }

            $this->indirectLinks->insert($noteId, $toNoteId, '[[' . $rawTarget . ']]', $resolved);
        }
    }
}
