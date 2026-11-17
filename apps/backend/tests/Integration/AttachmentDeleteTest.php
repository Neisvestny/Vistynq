<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use Vistynq\Http\Response;

/**
 * Удаление вложений (R17): объект S3 удаляется после коммита БД; soft delete
 * заметки объекты сохраняет, purge и удаление канваса — удаляют.
 */
final class AttachmentDeleteTest extends IntegrationTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $ownerId;
    private string $editorId;
    private string $viewerId;
    private string $strangerId;
    private string $canvasId;
    private string $noteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireStorage();

        $this->ownerId = $this->createUser();
        $this->editorId = $this->createUser();
        $this->viewerId = $this->createUser();
        $this->strangerId = $this->createUser();

        $this->canvasId = $this->createCanvas($this->ownerId, 'Удаление вложений');
        $this->grantCanvasAccess($this->canvasId, $this->editorId, 'editor');
        $this->grantCanvasAccess($this->canvasId, $this->viewerId, 'viewer');
        $this->noteId = $this->createNote($this->canvasId, $this->ownerId, 'Заметка');
    }

    public function testAuthorDeletesAttachmentAndObject(): void
    {
        [$id, $key] = $this->createAttachment();

        $response = $this->deleteAttachment($id, $this->ownerId);

        $this->assertSame(204, $response->status());
        $this->assertNull($this->attachments->findById($id));
        $this->assertStorageMissing($key);
    }

    public function testCanvasEditorCanDeleteAttachmentOfAnotherUploader(): void
    {
        [$id, $key] = $this->createAttachment($this->ownerId);

        $this->assertSame(204, $this->deleteAttachment($id, $this->editorId)->status());
        $this->assertStorageMissing($key);
    }

    public function testDeleteEnforcesAccess(): void
    {
        [$viewerCase] = $this->createAttachment();
        $this->assertApiStatus(
            403,
            fn (): Response => $this->deleteAttachment($viewerCase, $this->viewerId),
            'viewer',
        );

        [$strangerCase] = $this->createAttachment();
        $this->assertApiStatus(
            404,
            fn (): Response => $this->deleteAttachment($strangerCase, $this->strangerId),
            'stranger',
        );

        $this->assertApiStatus(404, fn (): Response => $this->deleteAttachment($this->uuid(), $this->ownerId), 'unknown');
    }

    public function testAvatarRowCannotBeDeletedThroughAttachmentEndpoint(): void
    {
        $this->avatarService->upload($this->ownerId, $this->uploadedFile($this->png(), 'a.png'));
        $stmt = $this->db->pdo()->prepare('SELECT avatar_attachment_id FROM users WHERE id = :id');
        $stmt->execute(['id' => $this->ownerId]);
        $avatarId = (string) $stmt->fetchColumn();
        $this->assertNotSame('', $avatarId);

        $this->assertApiStatus(
            404,
            fn (): Response => $this->deleteAttachment($avatarId, $this->ownerId),
        );
        $this->assertNotNull($this->attachments->findById($avatarId), 'аватар удаляется только через /profile/avatar');
    }

    public function testSoftDeleteKeepsObjectUntilPurge(): void
    {
        [$id, $key] = $this->createAttachment();

        $this->noteController->delete($this->request(
            'DELETE',
            "/api/v1/notes/{$this->noteId}",
            ['id' => $this->noteId],
            null,
            [],
            [],
            $this->ownerId,
        ));

        $this->assertNotNull($this->attachments->findById($id), 'soft delete сохраняет вложения');
        $this->assertTrue($this->storage->exists($key), 'soft delete сохраняет объекты');

        // В корзине вложения недоступны: ни ссылка, ни удаление.
        $this->assertApiStatus(404, fn (): Response => $this->attachmentController->url($this->request(
            'GET',
            "/api/v1/attachments/{$id}/url",
            ['id' => $id],
            null,
            [],
            [],
            $this->ownerId,
        )), 'url soft-deleted');
        $this->assertApiStatus(404, fn (): Response => $this->deleteAttachment($id, $this->ownerId), 'delete soft-deleted');

        $this->noteController->purge($this->request(
            'DELETE',
            "/api/v1/notes/{$this->noteId}/purge",
            ['id' => $this->noteId],
            null,
            [],
            [],
            $this->ownerId,
        ));

        $this->assertNull($this->attachments->findById($id), 'purge удаляет строку вложения');
        $this->assertStorageMissing($key, 'purge');
    }

    public function testCanvasDeleteRemovesAttachmentRowsAndObjects(): void
    {
        [$id, $key] = $this->createAttachment();

        $response = $this->canvasController->delete($this->request(
            'DELETE',
            "/api/v1/canvases/{$this->canvasId}",
            ['id' => $this->canvasId],
            null,
            [],
            [],
            $this->ownerId,
        ));

        $this->assertSame(204, $response->status());
        $this->assertNull($this->attachments->findById($id));
        $this->assertStorageMissing($key);
    }

    /**
     * @return array{0: string, 1: string} id и s3_key
     */
    private function createAttachment(?string $uploaderId = null): array
    {
        $attachment = $this->json($this->attachmentController->uploadNote($this->requestWithFile(
            'POST',
            "/api/v1/notes/{$this->noteId}/attachments",
            ['id' => $this->noteId],
            $this->uploadedFile($this->png(), 'file.png'),
            $uploaderId ?? $this->ownerId,
        )));

        $row = $this->attachmentRow((string) $attachment['id']);

        return [(string) $attachment['id'], (string) $row['s3_key']];
    }

    private function deleteAttachment(string $id, string $userId): Response
    {
        return $this->attachmentController->delete($this->request(
            'DELETE',
            "/api/v1/attachments/{$id}",
            ['id' => $id],
            null,
            [],
            [],
            $userId,
        ));
    }

    private function png(): string
    {
        return (string) base64_decode(self::PNG, true);
    }
}
