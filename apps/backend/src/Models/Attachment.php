<?php

declare(strict_types=1);

namespace Vistynq\Models;

/**
 * Attachment entity DTO (docs/data-model.md: attachments table).
 * Read-only: constructed from repository rows, serialized to the
 * `Attachment` shape of docs/openapi.yaml. s3_key наружу не отдаётся.
 */
final class Attachment
{
    public function __construct(
        public readonly string $id,
        public readonly string $uploaderId,
        public readonly ?string $noteId,
        public readonly string $kind,
        public readonly string $s3Key,
        public readonly string $filename,
        public readonly string $mime,
        public readonly int $sizeBytes,
        public readonly ?int $width,
        public readonly ?int $height,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            uploaderId: (string) $row['uploader_id'],
            noteId: $row['note_id'] !== null ? (string) $row['note_id'] : null,
            kind: (string) $row['kind'],
            s3Key: (string) $row['s3_key'],
            filename: (string) $row['filename'],
            mime: (string) $row['mime'],
            sizeBytes: (int) $row['size_bytes'],
            width: $row['width'] !== null ? (int) $row['width'] : null,
            height: $row['height'] !== null ? (int) $row['height'] : null,
        );
    }

    /**
     * Public API shape (docs/openapi.yaml → Attachment).
     *
     * @return array{
     *     id: string,
     *     noteId: string|null,
     *     kind: string,
     *     filename: string,
     *     mime: string,
     *     sizeBytes: int,
     *     width: int|null,
     *     height: int|null,
     *     url: string
     * }
     */
    public function toArray(string $url): array
    {
        return [
            'id' => $this->id,
            'noteId' => $this->noteId,
            'kind' => $this->kind,
            'filename' => $this->filename,
            'mime' => $this->mime,
            'sizeBytes' => $this->sizeBytes,
            'width' => $this->width,
            'height' => $this->height,
            'url' => $url,
        ];
    }
}
