<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

use PDO;
use Vistynq\Support\Database;

/**
 * attachments table (docs/data-model.md).
 */
final class AttachmentRepository implements AttachmentRepositoryInterface
{
    public function __construct(private readonly Database $db)
    {
    }

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
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO attachments
                (uploader_id, note_id, kind, s3_key, filename, mime, size_bytes, width, height)
             VALUES
                (:uploader_id, :note_id, :kind, :s3_key, :filename, :mime, :size_bytes, :width, :height)
             RETURNING *',
        );
        $stmt->execute([
            'uploader_id' => $uploaderId,
            'note_id' => $noteId,
            'kind' => $kind,
            's3_key' => $s3Key,
            'filename' => $filename,
            'mime' => $mime,
            'size_bytes' => $sizeBytes,
            'width' => $width,
            'height' => $height,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM attachments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function delete(string $id): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM attachments WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function deleteByNoteId(string $noteId): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM attachments WHERE note_id = :note_id');
        $stmt->execute(['note_id' => $noteId]);
    }

    public function deleteByCanvasId(string $canvasId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM attachments a
                   USING notes n
                   WHERE a.note_id = n.id
                     AND n.canvas_id = :canvas_id',
        );
        $stmt->execute(['canvas_id' => $canvasId]);
    }

    public function s3KeysByNoteId(string $noteId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT s3_key FROM attachments WHERE note_id = :note_id');
        $stmt->execute(['note_id' => $noteId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function s3KeysByCanvasId(string $canvasId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.s3_key
               FROM attachments a
               JOIN notes n ON n.id = a.note_id
              WHERE n.canvas_id = :canvas_id',
        );
        $stmt->execute(['canvas_id' => $canvasId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
