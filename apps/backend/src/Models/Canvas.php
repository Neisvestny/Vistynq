<?php

declare(strict_types=1);

namespace Vistynq\Models;

/**
 * Canvas entity DTO (docs/data-model.md: canvases table).
 * Read-only: constructed from repository rows, serialized to the
 * `Canvas` shape of docs/openapi.yaml.
 */
final class Canvas
{
    /**
     * @param array<string, mixed> $row canvases row (optionally with my_role)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $ownerId,
        public readonly string $accessLevel,
        public readonly ?string $publicSlug,
        public readonly string $myRole,
        public readonly string $createdAt,
        public readonly ?int $noteCount = null,
        public readonly ?string $updatedAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param string|null          $myRole resolved by AccessService when the row has no my_role column
     */
    public static function fromRow(array $row, ?string $myRole = null): self
    {
        $role = $myRole ?? (isset($row['my_role']) && is_string($row['my_role']) ? $row['my_role'] : null);

        return new self(
            id: (string) $row['id'],
            title: (string) $row['title'],
            ownerId: (string) $row['owner_id'],
            accessLevel: (string) $row['access_level'],
            publicSlug: $row['public_slug'] !== null ? (string) $row['public_slug'] : null,
            myRole: $role ?? 'none',
            createdAt: self::date((string) $row['created_at']),
            noteCount: isset($row['note_count']) && is_numeric($row['note_count'])
                ? (int) $row['note_count']
                : null,
            updatedAt: isset($row['updated_at']) && $row['updated_at'] !== null
                ? self::date((string) $row['updated_at'])
                : null,
        );
    }

    /**
     * Public API shape (docs/openapi.yaml → Canvas).
     *
     * `noteCount` приходит только из GET /canvases (выборка со счётчиком);
     * у графа/созданного канваса поля нет — omitted, чтобы не врать нулём.
     *
     * @return array{
     *     id: string,
     *     title: string,
     *     ownerId: string,
     *     access: array{level: string, publicSlug: string|null, myRole: string},
     *     createdAt: string,
     *     updatedAt?: string,
     *     noteCount?: int
     * }
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'title' => $this->title,
            'ownerId' => $this->ownerId,
            'access' => [
                'level' => $this->accessLevel,
                'publicSlug' => $this->publicSlug,
                'myRole' => $this->myRole,
            ],
            'createdAt' => $this->createdAt,
        ];

        // docs/api.md §GET /canvases: updatedAt обязателен в выдаче
        // (openapi → Canvas); опускается только если строки неоткуда взять.
        if ($this->updatedAt !== null) {
            $data['updatedAt'] = $this->updatedAt;
        }

        if ($this->noteCount !== null) {
            $data['noteCount'] = $this->noteCount;
        }

        return $data;
    }

    private static function date(string $value): string
    {
        return (new \DateTimeImmutable($value))->format(DATE_ATOM);
    }
}
