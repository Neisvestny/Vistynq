<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Vistynq\Models\User;
use Vistynq\Services\AccessService;
use Vistynq\Services\LinkService;
use Vistynq\Support\ApiException;

require_once __DIR__ . '/Fakes/FakeCanvasRepository.php';
require_once __DIR__ . '/Fakes/FakeDirectLinkRepository.php';
require_once __DIR__ . '/Fakes/FakeNoteRepository.php';
require_once __DIR__ . '/Fakes/FakeUserRepository.php';

/**
 * Unit-тесты прямых ссылок (roadmap R15): матрица 409 (дубль / разные
 * канвасы / from == to), 404-политика, ≥editor, верифицированный email,
 * delete → 204-семантика, reverse → 200 и 409 на существующую обратную.
 *
 * Контракт (docs/api.md §Ссылки, docs/openapi.yaml).
 */
final class LinkServiceTest extends TestCase
{
    private const CANVAS = 'canvas-1';
    private const OTHER_CANVAS = 'canvas-2';
    private const OWNER = 'user-owner';
    private const EDITOR = 'user-editor';
    private const VIEWER = 'user-viewer';
    private const STRANGER = 'user-stranger';
    private const UNVERIFIED = 'user-unverified';

    private const FROM = '550e8400-e29b-41d4-a716-446655440001';
    private const TO = '550e8400-e29b-41d4-a716-446655440002';
    private const FOREIGN = '550e8400-e29b-41d4-a716-446655440003';

    private FakeCanvasRepository $canvases;
    private FakeNoteRepository $notes;
    private FakeDirectLinkRepository $directLinks;
    private LinkService $service;

    protected function setUp(): void
    {
        $this->canvases = new FakeCanvasRepository();
        $this->notes = new FakeNoteRepository();
        $this->directLinks = new FakeDirectLinkRepository();
        $users = new FakeUserRepository();
        $access = new AccessService($this->canvases, $this->notes, $users);
        $this->service = new LinkService($this->directLinks, $this->notes, $access);

        $users->add($this->user(self::OWNER, verified: true));
        $users->add($this->user(self::EDITOR, verified: true));
        $users->add($this->user(self::VIEWER, verified: true));
        $users->add($this->user(self::STRANGER, verified: true));
        $users->add($this->user(self::UNVERIFIED, verified: false));

        $this->canvases->add([
            'id' => self::CANVAS,
            'owner_id' => self::OWNER,
            'access_level' => 'private',
        ]);
        $this->canvases->grant(self::CANVAS, self::EDITOR, 'editor');
        $this->canvases->grant(self::CANVAS, self::VIEWER, 'viewer');

        foreach ([self::FROM, self::TO] as $id) {
            $this->notes->add([
                'id' => $id,
                'canvas_id' => self::CANVAS,
                'author_id' => self::OWNER,
                'title' => 'Заметка ' . $id,
                'importance' => 3,
                'is_relevant' => true,
                'x' => 0.0,
                'y' => 0.0,
                'width' => 200,
                'deleted_at' => null,
            ]);
        }
        $this->notes->add([
            'id' => self::FOREIGN,
            'canvas_id' => self::OTHER_CANVAS,
            'author_id' => 'user-foreign',
            'title' => 'Чужая',
            'importance' => 1,
            'is_relevant' => true,
            'x' => 0.0,
            'y' => 0.0,
            'width' => 200,
            'deleted_at' => null,
        ]);
        $this->canvases->add([
            'id' => self::OTHER_CANVAS,
            'owner_id' => 'user-foreign',
            'access_level' => 'private',
        ]);
    }

    // --- POST /links/direct ---

    public function testCreateReturnsDirectLink(): void
    {
        $link = $this->service->create(self::OWNER, [
            'fromNoteId' => self::FROM,
            'toNoteId' => self::TO,
        ]);

        $this->assertSame(self::FROM, $link['fromNoteId']);
        $this->assertSame(self::TO, $link['toNoteId']);
        $this->assertNotEmpty($link['id']);

        $stored = $this->directLinks->findById((string) $link['id']);
        $this->assertNotNull($stored);
        $this->assertSame(self::FROM, $stored['from_note_id']);
    }

    public function testCreateByCanvasEditorIsAllowed(): void
    {
        $link = $this->service->create(self::EDITOR, [
            'fromNoteId' => self::FROM,
            'toNoteId' => self::TO,
        ]);

        $this->assertSame(self::TO, $link['toNoteId']);
    }

    public function testDuplicateIs409(): void
    {
        $this->service->create(self::OWNER, ['fromNoteId' => self::FROM, 'toNoteId' => self::TO]);

        $e = $this->expectExceptionApi(409, fn (): mixed => $this->service->create(self::OWNER, [
            'fromNoteId' => self::FROM,
            'toNoteId' => self::TO,
        ]));
        $this->assertSame('CONFLICT', $e->errorCode());
    }

    public function testOppositeDirectionIsNotDuplicate(): void
    {
        $this->service->create(self::OWNER, ['fromNoteId' => self::FROM, 'toNoteId' => self::TO]);
        $link = $this->service->create(self::OWNER, ['fromNoteId' => self::TO, 'toNoteId' => self::FROM]);

        $this->assertSame(self::TO, $link['fromNoteId']);
    }

    public function testDifferentCanvasIs409(): void
    {
        $e = $this->expectExceptionApi(409, fn (): mixed => $this->service->create(self::OWNER, [
            'fromNoteId' => self::FROM,
            'toNoteId' => self::FOREIGN,
        ]));
        $this->assertSame('CONFLICT', $e->errorCode());
    }

    public function testSelfLinkIs409(): void
    {
        $e = $this->expectExceptionApi(409, fn (): mixed => $this->service->create(self::OWNER, [
            'fromNoteId' => self::FROM,
            'toNoteId' => self::FROM,
        ]));
        $this->assertSame('CONFLICT', $e->errorCode());
    }

    public function testMissingFromNoteIs404(): void
    {
        $this->expectExceptionApi(404, fn (): mixed => $this->service->create(self::OWNER, [
            'fromNoteId' => '00000000-0000-4000-8000-000000000000',
            'toNoteId' => self::TO,
        ]));
    }

    public function testMissingToNoteIs404(): void
    {
        $this->expectExceptionApi(404, fn (): mixed => $this->service->create(self::OWNER, [
            'fromNoteId' => self::FROM,
            'toNoteId' => '00000000-0000-4000-8000-000000000000',
        ]));
    }

    public function testInaccessibleFromNoteIs404Not403(): void
    {
        // Чужой приватный канвас не раскрываем (R11-политика).
        $this->expectExceptionApi(404, fn (): mixed => $this->service->create(self::STRANGER, [
            'fromNoteId' => self::FROM,
            'toNoteId' => self::TO,
        ]));
    }

    public function testViewerIs403(): void
    {
        $e = $this->expectExceptionApi(403, fn (): mixed => $this->service->create(self::VIEWER, [
            'fromNoteId' => self::FROM,
            'toNoteId' => self::TO,
        ]));
        $this->assertNotSame('EMAIL_NOT_VERIFIED', $e->errorCode());
    }

    public function testUnverifiedEmailIs403(): void
    {
        $e = $this->expectExceptionApi(403, fn (): mixed => $this->service->create(self::UNVERIFIED, [
            'fromNoteId' => self::FROM,
            'toNoteId' => self::TO,
        ]));
        $this->assertSame('EMAIL_NOT_VERIFIED', $e->errorCode());
    }

    public function testInvalidUuidIs422(): void
    {
        $this->expectExceptionApi(422, fn (): mixed => $this->service->create(self::OWNER, [
            'fromNoteId' => 'not-a-uuid',
            'toNoteId' => self::TO,
        ]));
    }

    // --- DELETE /links/direct/{id} ---

    public function testDeleteRemovesLink(): void
    {
        $link = $this->createLink();

        $this->service->delete(self::OWNER, (string) $link['id']);

        $this->assertNull($this->directLinks->findById((string) $link['id']));
    }

    public function testDeleteMissingLinkIs404(): void
    {
        $this->expectExceptionApi(404, fn (): mixed => $this->service->delete(
            self::OWNER,
            '00000000-0000-4000-8000-000000000000',
        ));
    }

    public function testDeleteByViewerIs403(): void
    {
        $link = $this->createLink();

        $this->expectExceptionApi(403, fn (): mixed => $this->service->delete(self::VIEWER, (string) $link['id']));
    }

    public function testDeleteByStrangerIs404(): void
    {
        $link = $this->createLink();

        $this->expectExceptionApi(404, fn (): mixed => $this->service->delete(self::STRANGER, (string) $link['id']));
    }

    // --- POST /links/direct/{id}/reverse ---

    public function testReverseSwapsDirection(): void
    {
        $link = $this->createLink();

        $reversed = $this->service->reverse(self::OWNER, (string) $link['id']);

        $this->assertSame($link['id'], $reversed['id']);
        $this->assertSame(self::TO, $reversed['fromNoteId']);
        $this->assertSame(self::FROM, $reversed['toNoteId']);
    }

    public function testReverseTwiceRestoresOriginalDirection(): void
    {
        $link = $this->createLink();

        $this->service->reverse(self::OWNER, (string) $link['id']);
        $again = $this->service->reverse(self::EDITOR, (string) $link['id']);

        $this->assertSame(self::FROM, $again['fromNoteId']);
        $this->assertSame(self::TO, $again['toNoteId']);
    }

    public function testReverseOntoExistingOppositeLinkIs409(): void
    {
        $link = $this->createLink();
        // Обратная ссылка уже существует — цикл из двух рёбер.
        $this->service->create(self::OWNER, ['fromNoteId' => self::TO, 'toNoteId' => self::FROM]);

        $e = $this->expectExceptionApi(409, fn (): mixed => $this->service->reverse(
            self::OWNER,
            (string) $link['id'],
        ));
        $this->assertSame('CONFLICT', $e->errorCode());
    }

    public function testReverseMissingLinkIs404(): void
    {
        $this->expectExceptionApi(404, fn (): mixed => $this->service->reverse(
            self::OWNER,
            '00000000-0000-4000-8000-000000000000',
        ));
    }

    public function testReverseByViewerIs403(): void
    {
        $link = $this->createLink();

        $this->expectExceptionApi(403, fn (): mixed => $this->service->reverse(
            self::VIEWER,
            (string) $link['id'],
        ));
    }

    // --- helpers ---

    /**
     * @return array<string, mixed> DirectLink row
     */
    private function createLink(): array
    {
        $link = $this->service->create(self::OWNER, [
            'fromNoteId' => self::FROM,
            'toNoteId' => self::TO,
        ]);

        return $this->directLinks->findById((string) $link['id']) ?? [];
    }

    private function expectExceptionApi(int $status, callable $fn): ApiException
    {
        try {
            $fn();
        } catch (ApiException $e) {
            $this->assertSame($status, $e->httpStatus(), 'неожиданный статус: ' . $e->getMessage());

            return $e;
        }

        $this->fail(sprintf('ожидалась ApiException с HTTP %d, но исключение не выброшено', $status));
    }

    private function user(string $id, bool $verified): User
    {
        return new User(
            id: $id,
            email: $id . '@example.local',
            displayName: 'Test ' . $id,
            passwordHash: null,
            avatarAttachmentId: null,
            emailVerifiedAt: $verified ? new DateTimeImmutable('2026-01-01T00:00:00+00:00') : null,
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }
}
