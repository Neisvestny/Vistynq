<?php

declare(strict_types=1);

use Vistynq\Repositories\NoteRepositoryInterface;

/**
 * In-memory fake for AccessService/GraphService unit tests (roadmap R15/R17):
 * no PDO, rows are plain arrays seeded by the test.
 */
final class FakeNoteRepository implements NoteRepositoryInterface
{
    /** @var array<string, array<string,mixed>> */
    private array $notes = [];

    /** @var array<string, string> "noteId:user_id" => role */
    private array $access = [];

    /** @var array<string, list<string>> noteId => tagIds */
    private array $noteTags = [];

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

    public function create(string $canvasId, string $authorId, string $title, float $x, float $y): string
    {
        $id = $this->uuid();
        $this->notes[$id] = [
            'id' => $id,
            'canvas_id' => $canvasId,
            'author_id' => $authorId,
            'title' => $title,
            'x' => $x,
            'y' => $y,
            'width' => 200,
            'importance' => 3,
            'is_relevant' => true,
            'body_md' => '',
            'deleted_at' => null,
        ];

        return $id;
    }

    public function update(string $id, array $fields): void
    {
        if (isset($this->notes[$id])) {
            $this->notes[$id] = $fields + $this->notes[$id];
        }
    }

    public function updateBody(string $id, string $bodyMd): void
    {
        if (isset($this->notes[$id])) {
            $this->notes[$id]['body_md'] = $bodyMd;
        }
    }

    public function softDelete(string $id): void
    {
        if (isset($this->notes[$id]) && $this->notes[$id]['deleted_at'] === null) {
            $this->notes[$id]['deleted_at'] = (new DateTimeImmutable())->format(DATE_ATOM);
        }
    }

    public function restore(string $id): void
    {
        if (isset($this->notes[$id])) {
            $this->notes[$id]['deleted_at'] = null;
        }
    }

    public function hardDelete(string $id): void
    {
        unset($this->notes[$id], $this->noteTags[$id]);
    }

    public function findNoteAccess(string $noteId, string $userId): ?string
    {
        return $this->access[$noteId . ':' . $userId] ?? null;
    }

    public function findGraphNotes(string $canvasId, ?string $userId): array
    {
        $rows = [];
        foreach ($this->notes as $note) {
            if ($note['canvas_id'] === $canvasId
                && ($note['deleted_at'] ?? null) === null
                && $this->canSee($note, $userId)
            ) {
                $rows[] = $note;
            }
        }

        return $rows;
    }

    public function findNotRelevant(string $canvasId, string $userId): array
    {
        return array_values(array_filter(
            $this->notes,
            fn (array $n): bool => $n['canvas_id'] === $canvasId
                && ($n['deleted_at'] ?? null) === null
                && empty($n['is_relevant'])
                && $this->canSee($n, $userId),
        ));
    }

    public function findDeleted(string $canvasId, string $userId): array
    {
        return array_values(array_filter(
            $this->notes,
            fn (array $n): bool => $n['canvas_id'] === $canvasId
                && ($n['deleted_at'] ?? null) !== null
                && $this->canSee($n, $userId),
        ));
    }

    public function search(string $canvasId, ?string $userId, string $q): array
    {
        $q = mb_strtolower($q);

        return array_values(array_filter(
            $this->notes,
            fn (array $n): bool => $n['canvas_id'] === $canvasId
                && ($n['deleted_at'] ?? null) === null
                && $this->canSee($n, $userId)
                && str_contains(mb_strtolower((string) $n['title']), $q),
        ));
    }

    /**
     * docs/data-model.md §notes: private — только автор, владелец канваса
     * (владелец в фейке не моделируется — канваса здесь нет) и обладатели
     * note_access; аноним — только public. Строки без ключа visibility
     * (существующие юнит-фикстуры) считаются public.
     */
    private function canSee(array $note, ?string $userId): bool
    {
        if (($note['visibility'] ?? 'public') !== 'private') {
            return true;
        }
        if ($userId === null) {
            return false;
        }

        return ($note['author_id'] ?? null) === $userId
            || $this->findNoteAccess((string) $note['id'], $userId) !== null;
    }

    public function findVisibleIdsInCanvas(string $canvasId, array $ids): array
    {
        $visible = [];
        foreach ($ids as $id) {
            $note = $this->notes[$id] ?? null;
            if ($note !== null && $note['canvas_id'] === $canvasId && ($note['deleted_at'] ?? null) === null) {
                $visible[] = $id;
            }
        }

        return $visible;
    }

    public function findVisibleByLowerTitles(string $canvasId, array $titles): array
    {
        $byLower = [];
        foreach ($this->notes as $note) {
            if ($note['canvas_id'] !== $canvasId || ($note['deleted_at'] ?? null) !== null) {
                continue;
            }
            $key = mb_strtolower((string) $note['title']);
            if (in_array($key, $titles, true) && !isset($byLower[$key])) {
                $byLower[$key] = (string) $note['id'];
            }
        }

        return $byLower;
    }

    public function findTags(string $noteId): array
    {
        $rows = [];
        foreach ($this->noteTags[$noteId] ?? [] as $tagId) {
            $rows[] = ['id' => $tagId, 'name' => $tagId, 'color' => '#000000'];
        }

        return $rows;
    }

    public function syncTags(string $noteId, array $tagIds): void
    {
        $this->noteTags[$noteId] = array_values(array_filter($tagIds, 'is_string'));
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
