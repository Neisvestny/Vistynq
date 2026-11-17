<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use Vistynq\Http\Response;

/**
 * Загрузка вложений заметки через реальный MinIO (R17, docs/api.md §Вложения):
 * mime по содержимому, ключ без оригинального имени, presigned URL, права.
 */
final class AttachmentUploadTest extends IntegrationTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    private const MP3 = "\xFF\xFB\x90\x64\x00\x00\x00\x00";

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

        $this->canvasId = $this->createCanvas($this->ownerId, 'Вложения');
        $this->grantCanvasAccess($this->canvasId, $this->editorId, 'editor');
        $this->grantCanvasAccess($this->canvasId, $this->viewerId, 'viewer');
        $this->noteId = $this->createNote($this->canvasId, $this->ownerId, 'Заметка');
    }

    public function testUploadStoresObjectAndReturnsAttachment(): void
    {
        $png = $this->png();

        $response = $this->upload($this->noteId, $this->ownerId, $png, 'photo.png');

        $this->assertSame(201, $response->status());
        $attachment = $this->json($response);

        $this->assertSame(
            ['id', 'noteId', 'kind', 'filename', 'mime', 'sizeBytes', 'width', 'height', 'url'],
            array_keys($attachment),
        );
        $this->assertSame($this->noteId, $attachment['noteId']);
        $this->assertSame('image', $attachment['kind']);
        $this->assertSame('photo.png', $attachment['filename']);
        $this->assertSame('image/png', $attachment['mime']);
        $this->assertSame(strlen($png), $attachment['sizeBytes']);
        $this->assertSame(1, $attachment['width']);
        $this->assertSame(1, $attachment['height']);

        $row = $this->attachmentRow((string) $attachment['id']);
        $key = (string) $row['s3_key'];

        // Ключ: canvases/{canvasId}/notes/{noteId}/{uuid}.{ext}, без имени файла.
        $this->assertMatchesRegularExpression(
            '#^canvases/' . preg_quote($this->canvasId, '#') . '/notes/' . preg_quote($this->noteId, '#') . '/[0-9a-f-]{36}\.png$#',
            $key,
        );
        $this->assertStringNotContainsString('photo', $key);
        $this->assertSame('image/png', $row['mime']);
        $this->assertTrue($this->storage->exists($key));

        $this->assertStringContainsString('X-Amz-Signature=', (string) $attachment['url']);
        $this->assertStringContainsString($this->config->s3Bucket(), (string) $attachment['url']);
        $this->assertSame($png, $this->download((string) $attachment['url']));
    }

    public function testUploadDetectsAudioAndAllowsEditor(): void
    {
        $response = $this->upload($this->noteId, $this->editorId, self::MP3, 'voice.mp3');

        $this->assertSame(201, $response->status());
        $attachment = $this->json($response);

        $this->assertSame('audio', $attachment['kind']);
        $this->assertSame('audio/mpeg', $attachment['mime']);
        $this->assertSame('voice.mp3', $attachment['filename']);
        $this->assertNull($attachment['width']);
        $this->assertNull($attachment['height']);

        $row = $this->attachmentRow((string) $attachment['id']);
        $this->assertStringEndsWith('.mp3', (string) $row['s3_key']);
        $this->assertSame(self::MP3, $this->download((string) $attachment['url']));
    }

    public function testUploadEnforcesAccess(): void
    {
        $png = $this->png();

        $this->assertApiStatus(
            403,
            fn (): Response => $this->upload($this->noteId, $this->viewerId, $png, 'v.png'),
            'viewer',
        );
        $this->assertApiStatus(
            404,
            fn (): Response => $this->upload($this->noteId, $this->strangerId, $png, 's.png'),
            'stranger',
        );
    }

    public function testUploadRequiresVerifiedEmail(): void
    {
        $unverified = $this->createUser(verified: false);
        $this->grantCanvasAccess($this->canvasId, $unverified, 'editor');

        $error = $this->assertApiStatus(
            403,
            fn (): Response => $this->upload($this->noteId, $unverified, $this->png(), 'u.png'),
        );

        $this->assertSame('EMAIL_NOT_VERIFIED', $error->errorCode());
    }

    public function testUploadToSoftDeletedNoteIsNotFound(): void
    {
        $this->db->pdo()->prepare('UPDATE notes SET deleted_at = now() WHERE id = :id')
            ->execute(['id' => $this->noteId]);

        $this->assertApiStatus(
            404,
            fn (): Response => $this->upload($this->noteId, $this->ownerId, $this->png(), 'd.png'),
        );
    }

    public function testUploadRejectsUnsupportedAndOversizedFiles(): void
    {
        $this->assertApiStatus(
            415,
            fn (): Response => $this->upload($this->noteId, $this->ownerId, '<?php echo 1;', 'shell.png'),
            'текст под видом png',
        );

        $big = $this->png() . str_repeat("\0", 5 * 1024 * 1024);
        $this->assertApiStatus(
            413,
            fn (): Response => $this->upload($this->noteId, $this->ownerId, $big, 'big.png'),
            'image > 5 МиБ',
        );

        $this->assertApiStatus(
            422,
            fn (): Response => $this->attachmentController->uploadNote($this->requestWithFile(
                'POST',
                "/api/v1/notes/{$this->noteId}/attachments",
                ['id' => $this->noteId],
                null,
                $this->ownerId,
            )),
            'без файла',
        );
    }

    public function testAttachmentUrlEndpointReturnsFreshPresignedUrl(): void
    {
        $attachment = $this->json($this->upload($this->noteId, $this->ownerId, $this->png(), 'a.png'));
        $id = (string) $attachment['id'];

        foreach ([$this->ownerId, $this->editorId, $this->viewerId] as $viewer) {
            $response = $this->attachmentController->url($this->request(
                'GET',
                "/api/v1/attachments/{$id}/url",
                ['id' => $id],
                null,
                [],
                [],
                $viewer,
            ));

            $this->assertSame(200, $response->status());
            $body = $this->json($response);
            $this->assertSame(['url', 'expiresIn'], array_keys($body));
            $this->assertSame($this->config->s3PresignTtl(), $body['expiresIn']);
            $this->assertStringContainsString('X-Amz-Signature=', (string) $body['url']);
            $this->assertSame($this->png(), $this->download((string) $body['url']));
        }

        $this->assertApiStatus(404, fn (): Response => $this->attachmentController->url($this->request(
            'GET',
            "/api/v1/attachments/{$id}/url",
            ['id' => $id],
            null,
            [],
            [],
            $this->strangerId,
        )), 'stranger');
        $this->assertApiStatus(404, fn (): Response => $this->attachmentController->url($this->request(
            'GET',
            '/api/v1/attachments/' . $this->uuid() . '/url',
            ['id' => $this->uuid()],
            null,
            [],
            [],
            $this->ownerId,
        )), 'unknown attachment');
    }

    private function upload(string $noteId, string $userId, string $content, string $name): Response
    {
        return $this->attachmentController->uploadNote($this->requestWithFile(
            'POST',
            "/api/v1/notes/{$noteId}/attachments",
            ['id' => $noteId],
            $this->uploadedFile($content, $name),
            $userId,
        ));
    }

    private function png(): string
    {
        return (string) base64_decode(self::PNG, true);
    }
}
