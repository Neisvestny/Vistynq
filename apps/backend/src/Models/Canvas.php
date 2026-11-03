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
        );
    }

    /**
     * Public API shape (docs/openapi.yaml → Canvas).
     *
     * @return array{
     *     id: string,
     *     title: string,
     *     ownerId: string,
     *     access: array{level: string, publicSlug: string|null, myRole: string},
     *     createdAt: string
     * }
     */
    public function toArray(): array
    {
        return [
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
    }

    private static function date(string $value): string
    {
        return (new \DateTimeImmutable($value))->format(DATE_ATOM);
    }
}
