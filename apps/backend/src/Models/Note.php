<?php

declare(strict_types=1);

namespace Vistynq\Models;

/**
 * Note entity DTO (docs/data-model.md: notes table).
 * Read-only: constructed from repository rows, serialized to the
 * `Note` / `NoteDetail` shapes of docs/openapi.yaml.
 */
final class Note
{
    /**
     * @param list<string> $tagIds
     */
    public function __construct(
        public readonly string $id,
        public readonly string $canvasId,
        public readonly string $authorId,
        public readonly string $title,
        public readonly int $importance,
        public readonly bool $isRelevant,
        public readonly array $tagIds,
        public readonly float $x,
        public readonly float $y,
        public readonly int $width,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?string $deletedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row    notes row; may carry a `tag_ids` column
     *                                     (comma-joined string_agg of note_tags.tag_id)
     * @param list<string>         $tagIds fallback when the row has no tag_ids column
     */
    public static function fromRow(array $row, ?array $tagIds = null): self
    {
        $tags = $tagIds ?? self::parseTagIds($row['tag_ids'] ?? null);

        return new self(
            id: (string) $row['id'],
            canvasId: (string) $row['canvas_id'],
            authorId: (string) $row['author_id'],
            title: (string) $row['title'],
            importance: (int) $row['importance'],
            isRelevant: (bool) $row['is_relevant'],
            tagIds: $tags,
            x: (float) $row['x'],
            y: (float) $row['y'],
            width: (int) $row['width'],
            createdAt: self::date((string) $row['created_at']),
            updatedAt: self::date((string) $row['updated_at']),
            deletedAt: $row['deleted_at'] !== null ? self::date((string) $row['deleted_at']) : null,
        );
    }

    /**
     * Public API shape (docs/openapi.yaml → Note).
     *
     * @return array{
     *     id: string, canvasId: string, authorId: string, title: string,
     *     importance: int, isRelevant: bool, tagIds: list<string>,
     *     x: float, y: float, width: int,
     *     createdAt: string, updatedAt: string, deletedAt: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'canvasId' => $this->canvasId,
            'authorId' => $this->authorId,
            'title' => $this->title,
            'importance' => $this->importance,
            'isRelevant' => $this->isRelevant,
            'tagIds' => $this->tagIds,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'deletedAt' => $this->deletedAt,
        ];
    }

    /**
     * Detail shape (docs/openapi.yaml → NoteDetail = Note + bodyMd + myRole).
     *
     * @return array<string, mixed>
     */
    public function toDetailArray(string $bodyMd, string $myRole): array
    {
        return array_merge($this->toArray(), ['bodyMd' => $bodyMd, 'myRole' => $myRole]);
    }

    /**
     * Tombstone row has no manifest (docs/data-model.md: only canvas_id,
     * title_snapshot, deleted_at), so purged notes are serialized partially.
     * The Note schema has no required fields — a partial object is valid.
     *
     * @return array<string, mixed>
     */
    public static function tombstoneToArray(array $row): array
    {
        return [
            'id' => (string) $row['note_id'],
            'canvasId' => $row['canvas_id'] !== null ? (string) $row['canvas_id'] : null,
            'title' => (string) $row['title_snapshot'],
            'deletedAt' => self::date((string) $row['deleted_at']),
        ];
    }

    /**
     * @return list<string>
     */
    private static function parseTagIds(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '' || $raw === '{}') {
            return [];
        }

        return array_values(array_filter(
            explode(',', trim($raw, '{}')),
            static fn (string $id): bool => $id !== '',
        ));
    }

    private static function date(string $value): string
    {
        return (new \DateTimeImmutable($value))->format(DATE_ATOM);
    }
}
