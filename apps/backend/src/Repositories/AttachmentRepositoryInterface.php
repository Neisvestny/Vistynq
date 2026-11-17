<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

/**
 * attachments table (docs/data-model.md). Строки возвращаются как есть,
 * DTO собирается в Models\Attachment на границе сервиса.
 */
interface AttachmentRepositoryInterface
{
    /**
     * @return array<string, mixed> созданная строка (RETURNING *)
     */
    public function insert(
        string $uploaderId,
        ?string $noteId,
        string $kind,
        string $s3Key,
        string $filename,
        string $mime,
        int $sizeBytes,
        ?int $width,
        ?int $height,
    ): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array;

    public function delete(string $id): void;

    /**
     * Удаление метаданных всех вложений заметки (R17).
     *
     * attachments.note_id — ON DELETE SET NULL, а не CASCADE, поэтому строки
     * нужно удалять до hard delete заметки, иначе останутся сироты.
     */
    public function deleteByNoteId(string $noteId): void;

    /**
     * Удаление метаданных всех вложений заметок канваса (R17).
     */
    public function deleteByCanvasId(string $canvasId): void;

    /**
     * Ключи всех вложений заметки — до каскадного hard delete (R17).
     *
     * @return list<string>
     */
    public function s3KeysByNoteId(string $noteId): array;

    /**
     * Ключи всех вложений канваса — до каскадного delete (R17).
     *
     * @return list<string>
     */
    public function s3KeysByCanvasId(string $canvasId): array;
}
