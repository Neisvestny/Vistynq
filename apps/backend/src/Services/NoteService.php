<?php

declare(strict_types=1);

namespace Vistynq\Services;

use Throwable;
use Vistynq\Repositories\DeletedNoteRepository;
use Vistynq\Repositories\IndirectLinkRepository;
use Vistynq\Repositories\NoteRepository;
use Vistynq\Support\ApiException;
use Vistynq\Support\Database;

final class NoteService
{
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly DeletedNoteRepository $deletedNotes,
        private readonly IndirectLinkRepository $indirectLinks,
        private readonly AccessService $access,
        private readonly LinkParser $parser,
        private readonly Database $db,
    ) {
    }

    public function create(string $canvasId, string $userId, ?string $title, float $x = 0, float $y = 0): string
    {
        $this->access->assertVerified($userId);
        $ctx = $this->access->getCanvasContext($canvasId, $userId);
        if (!$this->access->canEditCanvas($ctx['role'])) {
            throw ApiException::notFound();
        }

        $title = $title !== null && $title !== '' ? $title : 'Без названия';

        return $this->notes->create($canvasId, $userId, $title, $x, $y);
    }

    /**
     * @return array<string,mixed>
     */
    public function get(string $noteId, ?string $userId): array
    {
        $ctx = $this->access->getNoteContext($noteId, $userId);
        $note = $ctx['note'];
        $tags = $this->notes->findTags($noteId);

        return [
            'note' => [
                'id' => $note['id'],
                'canvasId' => $note['canvas_id'],
                'authorId' => $note['author_id'],
                'title' => $note['title'],
                'bodyMd' => $note['body_md'],
                'importance' => (int) $note['importance'],
                'isRelevant' => (bool) $note['is_relevant'],
                'x' => (float) $note['x'],
                'y' => (float) $note['y'],
                'width' => (int) $note['width'],
                'tags' => array_map(fn ($t) => $t['id'], $tags),
                'deletedAt' => $note['deleted_at'],
                'createdAt' => $note['created_at'],
                'updatedAt' => $note['updated_at'],
            ],
            'access' => [
                'canEdit' => $ctx['effectiveEdit'],
                'myRole' => $ctx['role'],
            ],
        ];
    }

    public function updateManifest(string $noteId, string $userId, array $data): void
    {
        $ctx = $this->access->getNoteContext($noteId, $userId);
        if (!$ctx['effectiveEdit']) {
            throw ApiException::notFound();
        }

        $update = [];
        if (array_key_exists('title', $data)) {
            $update['title'] = $data['title'];
        }
        if (array_key_exists('importance', $data)) {
            $update['importance'] = $data['importance'];
        }
        if (array_key_exists('isRelevant', $data)) {
            $update['is_relevant'] = (bool) $data['isRelevant'];
        }
        if ($update !== []) {
            $this->notes->update($noteId, $update);
        }
        if (array_key_exists('tagIds', $data) && is_array($data['tagIds'])) {
            $this->notes->syncTags($noteId, $data['tagIds']);
        }
    }

    public function updateBody(string $noteId, string $userId, string $bodyMd): void
    {
        $ctx = $this->access->getNoteContext($noteId, $userId);
        if (!$ctx['effectiveEdit']) {
            throw ApiException::notFound();
        }
        $note = $ctx['note'];
        $this->notes->updateBody($noteId, $bodyMd);
        $this->parser->parseAndStore($note['canvas_id'], $noteId, $bodyMd);
    }

    public function updatePosition(string $canvasId, string $noteId, string $userId, ?float $x, ?float $y, ?int $width): void
    {
        $ctx = $this->access->getCanvasContext($canvasId, $userId);
        if (!$this->access->canEditCanvas($ctx['role'])) {
            throw ApiException::notFound();
        }

        $note = $this->notes->findById($noteId);
        if ($note === null || $note['canvas_id'] !== $canvasId) {
            throw ApiException::notFound();
        }

        $update = [];
        if ($x !== null) {
            $update['x'] = $x;
        }
        if ($y !== null) {
            $update['y'] = $y;
        }
        if ($width !== null) {
            $update['width'] = $width;
        }
        if ($update !== []) {
            $this->notes->update($noteId, $update);
        }
    }

    public function softDelete(string $noteId, string $userId): void
    {
        $ctx = $this->access->getNoteContext($noteId, $userId);
        if (!$ctx['effectiveEdit']) {
            throw ApiException::notFound();
        }
        $this->notes->softDelete($noteId);
    }

    public function restore(string $noteId, string $userId): void
    {
        $ctx = $this->access->getNoteContext($noteId, $userId);
        if (!$this->access->canEditCanvas($ctx['role']) && $ctx['role'] !== 'editor') {
            throw ApiException::notFound();
        }
        $this->notes->restore($noteId);
    }

    public function purge(string $noteId, string $userId): void
    {
        $ctx = $this->access->getNoteContext($noteId, $userId);
        if (!$this->access->canEditCanvas($ctx['role'])) {
            throw ApiException::notFound();
        }
        $note = $ctx['note'];
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->deletedNotes->create($noteId, $note['canvas_id'], $note['title'], $userId);
            $pdo->prepare('DELETE FROM notes WHERE id = :id')->execute(['id' => $noteId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
