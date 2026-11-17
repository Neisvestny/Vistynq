<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use Vistynq\Http\Response;

/**
 * Аватар (R17, docs/api.md §Auth и профиль): image ≤5 МиБ, строка
 * attachments с note_id = NULL, users.avatar_attachment_id; замена/удаление
 * чистят старый объект после коммита; публичный User отдаёт avatarUrl.
 */
final class AvatarTest extends IntegrationTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireStorage();

        $this->userId = $this->createUser();
    }

    public function testUploadCreatesAttachmentAndReturnsAvatarUrl(): void
    {
        $png = $this->png();

        $response = $this->upload($png, 'avatar.png');

        $this->assertSame(201, $response->status());
        $body = $this->json($response);
        $this->assertSame(['avatarUrl'], array_keys($body));

        $avatarId = $this->avatarAttachmentId();
        $row = $this->attachmentRow($avatarId);

        $this->assertNull($row['note_id']);
        $this->assertSame('avatar', $row['kind']);
        $this->assertSame('avatar.png', $row['filename']);
        $this->assertSame('image/png', $row['mime']);
        $this->assertMatchesRegularExpression(
            '#^avatars/' . preg_quote($this->userId, '#') . '/[0-9a-f-]{36}\.png$#',
            (string) $row['s3_key'],
        );
        $this->assertStringContainsString('X-Amz-Signature=', (string) $body['avatarUrl']);
        $this->assertSame($png, $this->download((string) $body['avatarUrl']));
    }

    public function testPublicUserJsonExposesAvatarUrlOnly(): void
    {
        $url = (string) $this->json($this->upload($this->png()))['avatarUrl'];

        $me = $this->json($this->authController->me($this->request(
            'GET',
            '/api/v1/auth/me',
            [],
            null,
            [],
            [],
            $this->userId,
        )));

        $this->assertIsString($me['avatarUrl']);
        // Presigned URL пересоздаётся на каждый запрос: сверяем путь объекта,
        // а не весь URL с подписью и timestamp.
        $this->assertSame(parse_url($url, PHP_URL_PATH), parse_url((string) $me['avatarUrl'], PHP_URL_PATH));
        $this->assertArrayNotHasKey('avatarAttachmentId', $me);
    }

    public function testReplacingAvatarDeletesOldObjectAndRow(): void
    {
        $this->upload($this->png(), 'old.png');
        $oldId = $this->avatarAttachmentId();
        $oldKey = (string) $this->attachmentRow($oldId)['s3_key'];

        $this->assertSame(201, $this->upload($this->png(), 'new.png')->status());
        $newId = $this->avatarAttachmentId();
        $newKey = (string) $this->attachmentRow($newId)['s3_key'];

        $this->assertNotSame($oldId, $newId);
        $this->assertNull($this->attachments->findById($oldId), 'старая строка удалена');
        $this->assertStorageMissing($oldKey, 'старый аватар');
        $this->assertTrue($this->storage->exists($newKey));
    }

    public function testDeleteIsIdempotentAndRemovesObject(): void
    {
        $this->upload($this->png());
        $avatarId = $this->avatarAttachmentId();
        $key = (string) $this->attachmentRow($avatarId)['s3_key'];

        $this->assertSame(204, $this->deleteAvatar()->status());
        $this->assertNull($this->avatarAttachmentId());
        $this->assertNull($this->attachments->findById($avatarId));
        $this->assertStorageMissing($key);

        // Повторный DELETE — 204, без ошибок.
        $this->assertSame(204, $this->deleteAvatar()->status());
    }

    public function testDeleteWithoutAvatarIsNoContent(): void
    {
        $this->assertSame(204, $this->deleteAvatar()->status());
    }

    public function testUploadRejectsNonImageAndOversizedFiles(): void
    {
        $this->assertApiStatus(
            415,
            fn (): Response => $this->upload("\xFF\xFB\x90\x64\x00\x00\x00\x00", 'voice.mp3'),
            'audio',
        );
        $this->assertApiStatus(
            415,
            fn (): Response => $this->upload('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'x.svg'),
            'svg',
        );
        $this->assertApiStatus(
            413,
            fn (): Response => $this->upload($this->png() . str_repeat("\0", 5 * 1024 * 1024), 'big.png'),
            '> 5 МиБ',
        );
        $this->assertApiStatus(
            422,
            fn (): Response => $this->profileController->uploadAvatar($this->requestWithFile(
                'POST',
                '/api/v1/profile/avatar',
                [],
                null,
                $this->userId,
            )),
            'без файла',
        );
    }

    public function testAvatarUrlIsNotReadableThroughAttachmentEndpointByAnotherUser(): void
    {
        $this->upload($this->png());
        $avatarId = $this->avatarAttachmentId();
        $other = $this->createUser();

        $this->assertSame(200, $this->attachmentController->url($this->request(
            'GET',
            "/api/v1/attachments/{$avatarId}/url",
            ['id' => $avatarId],
            null,
            [],
            [],
            $this->userId,
        ))->status());

        $this->assertApiStatus(404, fn (): Response => $this->attachmentController->url($this->request(
            'GET',
            "/api/v1/attachments/{$avatarId}/url",
            ['id' => $avatarId],
            null,
            [],
            [],
            $other,
        )));
    }

    private function upload(string $content, string $name = 'avatar.png'): Response
    {
        return $this->profileController->uploadAvatar($this->requestWithFile(
            'POST',
            '/api/v1/profile/avatar',
            [],
            $this->uploadedFile($content, $name),
            $this->userId,
        ));
    }

    private function deleteAvatar(): Response
    {
        return $this->profileController->deleteAvatar($this->request(
            'DELETE',
            '/api/v1/profile/avatar',
            [],
            null,
            [],
            [],
            $this->userId,
        ));
    }

    private function avatarAttachmentId(): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT avatar_attachment_id FROM users WHERE id = :id');
        $stmt->execute(['id' => $this->userId]);
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function png(): string
    {
        return (string) base64_decode(self::PNG, true);
    }
}
