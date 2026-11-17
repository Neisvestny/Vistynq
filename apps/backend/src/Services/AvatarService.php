<?php

declare(strict_types=1);

namespace Vistynq\Services;

use Throwable;
use Vistynq\Models\User;
use Vistynq\Repositories\AttachmentRepositoryInterface;
use Vistynq\Repositories\UserRepositoryInterface;
use Vistynq\Services\Storage\StorageService;
use Vistynq\Services\Storage\UploadValidator;
use Vistynq\Support\ApiException;
use Vistynq\Support\Database;
use Vistynq\Support\Uuid;

/**
 * Аватар пользователя (docs/api.md §Auth и профиль, R17).
 *
 * Строка attachments с note_id = NULL; users.avatar_attachment_id —
 * единственная ссылка. Замена/удаление удаляют старый объект только после
 * коммита БД.
 */
final class AvatarService
{
    public function __construct(
        private readonly AttachmentRepositoryInterface $attachments,
        private readonly UserRepositoryInterface $users,
        private readonly StorageService $storage,
        private readonly Database $db,
    ) {
    }

    /**
     * POST /profile/avatar: image ≤5 МБ, 201 {avatarUrl}.
     *
     * @param array<string, mixed>|null $file $_FILES['file']
     */
    public function upload(string $userId, ?array $file): string
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw ApiException::unauthorized();
        }

        $data = UploadValidator::inspect($file, imageOnly: true);
        $key = sprintf('avatars/%s/%s.%s', $userId, Uuid::v4(), $data->extension);

        $oldId = $user->avatarAttachmentId;
        $oldRow = $oldId !== null ? $this->attachments->findById($oldId) : null;

        $this->storage->put($key, $data->tmpPath, $data->mime);

        try {
            /** @var array<string, mixed> $newRow */
            $newRow = $this->db->transaction(function () use (
                $userId,
                $oldId,
                $key,
                $data,
            ): array {
                $row = $this->attachments->insert(
                    $userId,
                    null,
                    'avatar',
                    $key,
                    $data->originalName,
                    $data->mime,
                    $data->size,
                    $data->width,
                    $data->height,
                );
                $this->users->setAvatarAttachmentId($userId, (string) $row['id']);

                if ($oldId !== null) {
                    $this->attachments->delete($oldId);
                }

                return $row;
            });
        } catch (Throwable $e) {
            // Строка не закоммичена — объект-сирота не нужен.
            $this->storage->deleteQuietly([$key]);
            throw $e;
        }

        if ($oldRow !== null) {
            $this->storage->deleteQuietly([(string) $oldRow['s3_key']]);
        }

        return $this->storage->url((string) $newRow['s3_key'])['url'];
    }

    /**
     * DELETE /profile/avatar: идемпотентный 204.
     */
    public function delete(string $userId): void
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw ApiException::unauthorized();
        }

        $attachmentId = $user->avatarAttachmentId;
        if ($attachmentId === null) {
            return;
        }

        $row = $this->attachments->findById($attachmentId);

        $this->db->transaction(function () use ($userId, $attachmentId): void {
            $this->users->setAvatarAttachmentId($userId, null);
            $this->attachments->delete($attachmentId);
        });

        if ($row !== null) {
            $this->storage->deleteQuietly([(string) $row['s3_key']]);
        }
    }

    /**
     * Presigned/static URL текущего аватара для публичного JSON User.
     */
    public function urlForUser(User $user): ?string
    {
        if ($user->avatarAttachmentId === null) {
            return null;
        }

        $row = $this->attachments->findById($user->avatarAttachmentId);
        if ($row === null) {
            return null;
        }

        return $this->storage->url((string) $row['s3_key'])['url'];
    }
}
