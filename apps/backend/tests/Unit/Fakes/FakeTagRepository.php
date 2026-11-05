<?php

declare(strict_types=1);

use Vistynq\Repositories\TagRepositoryInterface;

/**
 * In-memory fake for GraphService unit tests (roadmap R15/R17): no PDO,
 * rows are plain arrays seeded by the test.
 */
final class FakeTagRepository implements TagRepositoryInterface
{
    /** @var array<string, array<string,mixed>> */
    private array $tags = [];

    /** @var array<string, list<string>> noteId => [tagName, ...] */
    private array $noteTagNames = [];

    /**
     * @param array<string,mixed> $tag
     */
    public function add(array $tag): void
    {
        $this->tags[(string) $tag['id']] = $tag;
    }

    /**
     * @param list<string> $names
     */
    public function setNoteTagNames(string $noteId, array $names): void
    {
        $this->noteTagNames[$noteId] = $names;
    }

    public function findById(string $id): ?array
    {
        return $this->tags[$id] ?? null;
    }

    public function findManyByIds(array $ids): array
    {
        $rows = [];
        foreach ($ids as $id) {
            if (isset($this->tags[$id])) {
                $rows[] = $this->tags[$id];
            }
        }

        return $rows;
    }

    public function findCanvasTags(string $canvasId): array
    {
        $rows = [];
        foreach ($this->tags as $tag) {
            if ($tag['canvas_id'] === $canvasId) {
                $rows[] = $tag;
            }
        }

        return $rows;
    }

    public function create(string $canvasId, string $name, string $color): array
    {
        $row = ['id' => $this->uuid(), 'canvas_id' => $canvasId, 'name' => $name, 'color' => $color];
        $this->tags[$row['id']] = $row;

        return $row;
    }

    public function update(string $id, array $fields): ?array
    {
        if (!isset($this->tags[$id])) {
            return null;
        }
        $this->tags[$id] = $fields + $this->tags[$id];

        return $this->tags[$id];
    }

    public function delete(string $id): void
    {
        unset($this->tags[$id]);
    }

    public function findNoteTagNames(string $canvasId): array
    {
        return $this->noteTagNames;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
