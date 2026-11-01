<?php

declare(strict_types=1);

namespace Vistynq\Services;

use PDO;
use Vistynq\Repositories\DeletedNoteRepository;
use Vistynq\Repositories\NoteRepository;
use Vistynq\Support\Database;

final class ArchiveService
{
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly DeletedNoteRepository $deletedNotes,
        private readonly AccessService $access,
        private readonly Database $db,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function get(string $canvasId, string $userId): array
    {
        $ctx = $this->access->getCanvasContext($canvasId, $userId);
        if (!$this->access->canEditCanvas($ctx['role'])) {
            throw \Vistynq\Support\ApiException::notFound();
        }

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id, title, canvas_id FROM notes WHERE canvas_id = :canvasId AND deleted_at IS NULL AND is_relevant = false');
        $stmt->execute(['canvasId' => $canvasId]);
        $notRelevant = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $deleted = $this->deletedNotes->findByCanvas($canvasId);

        return [
            'notRelevant' => array_map(fn ($n) => ['id' => $n['id'], 'title' => $n['title'], 'canvasId' => $n['canvas_id']], $notRelevant),
            'deleted' => array_map(fn ($n) => ['id' => $n['note_id'], 'title' => $n['title_snapshot'], 'canvasId' => $n['canvas_id'], 'deletedAt' => $n['deleted_at']], $deleted),
        ];
    }
}
