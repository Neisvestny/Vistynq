<?php

declare(strict_types=1);

namespace Vistynq\Services;

use Throwable;
use Vistynq\Models\Attachment;
use Vistynq\Repositories\AttachmentRepositoryInterface;
use Vistynq\Services\Storage\StorageService;
use Vistynq\Services\Storage\UploadValidator;
use Vistynq\Support\ApiException;
use Vistynq\Support\Uuid;

/**
 * Вложения заметок (docs/api.md §Вложения, docs/ROADMAP.md R17).
 *
 * Upload идёт через бэкенд: права проверяются до файла, mime — по
 * содержимому, ключ не содержит оригинального имени. Объект S3 удаляется
 * после коммита строки; ошибка S3 не откатывает БД.
 */
final class AttachmentService
{
    public function __construct(
        private readonly AttachmentRepositoryInterface $attachments,
        private readonly AccessService $access,
        private readonly StorageService $storage,
    ) {
    }

    /**
     * POST /notes/{id}/attachments.
     *
     * @param array<string, mixed>|null $file $_FILES['file']
     *
     * @return array<string, mixed> Attachment (docs/openapi.yaml)
     */
    public function uploadToNote(string $noteId, string $userId, ?array $file): array
    {
        $this->access->assertVerified($userId);
        $ctx = $this->access->requireNoteEdit($noteId, $userId);
        if (($ctx['note']['deleted_at'] ?? null) !== null) {
            // В корзину файлы не грузим: restore должен возвращать заметку
            // в том виде, в котором её удалили.
            throw ApiException::notFound();
        }

        $data = UploadValidator::inspect($file, imageOnly: false);
        $canvasId = (string) $ctx['note']['canvas_id'];
        $key = sprintf('canvases/%s/notes/%s/%s.%s', $canvasId, $noteId, Uuid::v4(), $data->extension);

        $this->storage->put($key, $data->tmpPath, $data->mime);

        try {
            $row = $this->attachments->insert(
                $userId,
                $noteId,
                $data->kind,
                $key,
                $data->originalName,
                $data->mime,
                $data->size,
                $data->width,
                $data->height,
            );
        } catch (Throwable $e) {
            // Компенсация: объекта без строки быть не должно.
            $this->storage->deleteQuietly([$key]);
            throw $e;
        }

        return Attachment::fromRow($row)->toArray($this->storage->url($key)['url']);
    }

    /**
     * GET /attachments/{id}/url.
     *
     * @return array{url: string, expiresIn: int}
     */
    public function url(string $attachmentId, string $userId): array
    {
        $row = $this->attachments->findById($attachmentId);
        if ($row === null) {
            throw ApiException::notFound();
        }

        $noteId = $row['note_id'] !== null ? (string) $row['note_id'] : null;
        if ($noteId === null) {
            // Аватар: ссылку видит только загрузчик (docs/api.md: аватар
            // управляется через /profile/avatar).
            if ((string) $row['uploader_id'] !== $userId) {
                throw ApiException::notFound();
            }
        } else {
            $ctx = $this->access->getNoteContext($noteId, $userId);
            if (($ctx['note']['deleted_at'] ?? null) !== null) {
                // В корзине вложения недоступны, как и сама заметка (R17).
                throw ApiException::notFound();
            }
        }

        return $this->storage->url((string) $row['s3_key']);
    }

    /**
     * DELETE /attachments/{id}.
     */
    public function delete(string $attachmentId, string $userId): void
    {
        $row = $this->attachments->findById($attachmentId);
        if ($row === null || $row['note_id'] === null) {
            // Аватар удаляется только через DELETE /profile/avatar.
            throw ApiException::notFound();
        }

        $ctx = $this->access->requireNoteEdit((string) $row['note_id'], $userId);
        if (($ctx['note']['deleted_at'] ?? null) !== null) {
            // Soft-deleted заметка: вложения не меняются до restore/purge.
            throw ApiException::notFound();
        }

        $this->attachments->delete($attachmentId);

        // После коммита: если S3 недоступен, останется orphan-объект,
        // но строка БД уже удалена — источник истины БД.
        $this->storage->deleteQuietly([(string) $row['s3_key']]);
    }
}
