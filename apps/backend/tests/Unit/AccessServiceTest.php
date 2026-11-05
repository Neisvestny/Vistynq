<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Vistynq\Models\User;
use Vistynq\Services\AccessService;
use Vistynq\Support\ApiException;

require_once __DIR__ . '/Fakes/FakeCanvasRepository.php';
require_once __DIR__ . '/Fakes/FakeNoteRepository.php';
require_once __DIR__ . '/Fakes/FakeUserRepository.php';

/**
 * Unit-тесты единственной точки принятия решений о доступе (R15).
 *
 * Политика (docs/architecture.md §5, ROADMAP R11):
 *  - недоступное для вызывающего → 404 (не раскрываем существование);
 *  - видно, но роли не хватает на действие → 403;
 *  - права заметки = max(права канваса, свои права), автор всегда может.
 */
final class AccessServiceTest extends TestCase
{
    private FakeCanvasRepository $canvases;
    private FakeNoteRepository $notes;
    private FakeUserRepository $users;
    private AccessService $service;

    private const OWNER = 'user-owner';
    private const INVITED = 'user-invited';
    private const STRANGER = 'user-stranger';
    private const CANVAS = 'canvas-1';
    private const NOTE = 'note-1';

    protected function setUp(): void
    {
        $this->canvases = new FakeCanvasRepository();
        $this->notes = new FakeNoteRepository();
        $this->users = new FakeUserRepository();
        $this->service = new AccessService($this->canvases, $this->notes, $this->users);

        $this->canvases->add([
            'id' => self::CANVAS,
            'owner_id' => self::OWNER,
            'access_level' => 'private',
        ]);
        $this->notes->add([
            'id' => self::NOTE,
            'canvas_id' => self::CANVAS,
            'author_id' => self::INVITED,
        ]);
    }

    // --- getCanvasContext / 404-политика ---

    public function testMissingCanvasIs404(): void
    {
        $e = $this->expectExceptionApi(404, fn (): mixed => $this->service->getCanvasContext('no-such', self::OWNER));
        $this->assertSame('NOT_FOUND', $e->errorCode());
    }

    public function testPrivateCanvasWithoutRightsIs404Not403(): void
    {
        // Существование канваса не раскрываем постороннему (R11).
        $this->expectExceptionApi(404, fn (): mixed => $this->service->getCanvasContext(self::CANVAS, self::STRANGER));
    }

    public function testAnonymousOnPrivateCanvasIs404(): void
    {
        $this->expectExceptionApi(404, fn (): mixed => $this->service->getCanvasContext(self::CANVAS, null));
    }

    public function testOwnerGetsOwnerRole(): void
    {
        $ctx = $this->service->getCanvasContext(self::CANVAS, self::OWNER);

        $this->assertSame('owner', $ctx['role']);
        $this->assertSame(self::CANVAS, $ctx['canvas']['id']);
    }

    public function testInvitedEditorGetsEditorRole(): void
    {
        $this->canvases->grant(self::CANVAS, self::INVITED, 'editor');

        $this->assertSame('editor', $this->service->getCanvasContext(self::CANVAS, self::INVITED)['role']);
    }

    public function testPublicLinkGivesViewerRoleToStranger(): void
    {
        $this->canvases->add([
            'id' => 'canvas-public',
            'owner_id' => self::OWNER,
            'access_level' => 'public_link',
        ]);

        $ctx = $this->service->getCanvasContext('canvas-public', self::STRANGER);

        $this->assertSame('viewer', $ctx['role']);
    }

    public function testInviteBeatsPublicLink(): void
    {
        $this->canvases->add([
            'id' => 'canvas-public',
            'owner_id' => self::OWNER,
            'access_level' => 'public_link',
        ]);
        $this->canvases->grant('canvas-public', self::INVITED, 'editor');

        $this->assertSame('editor', $this->service->getCanvasContext('canvas-public', self::INVITED)['role']);
    }

    public function testOwnerBeatsInvite(): void
    {
        // Владелец — owner, даже если у него есть инвайт-строка.
        $this->canvases->grant(self::CANVAS, self::OWNER, 'viewer');

        $this->assertSame('owner', $this->service->getCanvasContext(self::CANVAS, self::OWNER)['role']);
    }

    // --- requireCanvasEdit / requireCanvasManage → 403 ---

    public function testViewerCannotEditCanvas(): void
    {
        $this->canvases->grant(self::CANVAS, self::INVITED, 'viewer');
        $this->expectExceptionApi(403, fn (): mixed => $this->service->requireCanvasEdit(self::CANVAS, self::INVITED));
    }

    public function testEditorCanEditCanvas(): void
    {
        $this->canvases->grant(self::CANVAS, self::INVITED, 'editor');

        $this->assertSame('editor', $this->service->requireCanvasEdit(self::CANVAS, self::INVITED)['role']);
    }

    public function testEditorCannotManageCanvas(): void
    {
        // Manage = owner; редактор получает 403, а не 404 (он видит канвас).
        $this->canvases->grant(self::CANVAS, self::INVITED, 'editor');
        $this->expectExceptionApi(403, fn (): mixed => $this->service->requireCanvasManage(self::CANVAS, self::INVITED));
    }

    public function testOwnerCanManageCanvas(): void
    {
        $this->assertSame('owner', $this->service->requireCanvasManage(self::CANVAS, self::OWNER)['role']);
    }

    public function testStrangerEditAttemptIs404Not403(): void
    {
        // Не видит канвас → 404, чтобы не подтвердить его существование.
        $this->expectExceptionApi(404, fn (): mixed => $this->service->requireCanvasEdit(self::CANVAS, self::STRANGER));
    }

    // --- Контекст заметки: max(canvas, note), автор ---

    public function testMissingNoteIs404(): void
    {
        $this->expectExceptionApi(404, fn (): mixed => $this->service->getNoteContext('no-such', self::OWNER));
    }

    public function testNoteOnMissingCanvasIs404(): void
    {
        // Заметка пережила канвас — недостижимо при каскаде, но 404, а не 500.
        $this->notes->add([
            'id' => 'orphan-note',
            'canvas_id' => 'no-such-canvas',
            'author_id' => self::OWNER,
        ]);

        $this->expectExceptionApi(404, fn (): mixed => $this->service->getNoteContext('orphan-note', self::OWNER));
    }

    public function testAuthorAlwaysSeesOwnNote(): void
    {
        // Автор — посторонний для канваса, но заметка видна и редактируется.
        $ctx = $this->service->getNoteContext(self::NOTE, self::INVITED);

        $this->assertTrue($ctx['effectiveView']);
        $this->assertTrue($ctx['effectiveEdit']);
        $this->assertTrue($ctx['isAuthor']);
    }

    public function testStrangerCannotSeePrivateNote(): void
    {
        $this->expectExceptionApi(404, fn (): mixed => $this->service->getNoteContext(self::NOTE, self::STRANGER));
    }

    public function testCanvasViewerSeesNoteButCannotEditIt(): void
    {
        // Права заметки = max(canvas, note): viewer канваса видит заметку,
        // но edit не даёт ни канвас, ни авторство, ни note-инвайт.
        $this->canvases->grant(self::CANVAS, self::STRANGER, 'viewer');
        $ctx = $this->service->getNoteContext(self::NOTE, self::STRANGER);

        $this->assertTrue($ctx['effectiveView']);
        $this->assertFalse($ctx['effectiveEdit']);
        $this->expectExceptionApi(403, fn (): mixed => $this->service->requireNoteEdit(self::NOTE, self::STRANGER));
    }

    public function testNoteEditorInviteGrantsEditOnPrivateCanvas(): void
    {
        // Права заметки = max(canvas, note): note-инвайт editor даёт edit,
        // даже если канвас приватный и роли в нём нет.
        $this->notes->grant(self::NOTE, self::STRANGER, 'editor');
        $ctx = $this->service->getNoteContext(self::NOTE, self::STRANGER);

        $this->assertTrue($ctx['effectiveView']);
        $this->assertTrue($ctx['effectiveEdit']);
        $this->assertSame('none', $ctx['role'], 'роли в приватном канвасе у постороннего нет');
        $this->assertSame('editor', $ctx['noteRole']);
        $this->assertNotNull($this->service->requireNoteEdit(self::NOTE, self::STRANGER));
    }

    public function testNoteViewerInviteGivesViewButNotEdit(): void
    {
        $this->notes->grant(self::NOTE, self::STRANGER, 'viewer');
        $ctx = $this->service->getNoteContext(self::NOTE, self::STRANGER);

        $this->assertTrue($ctx['effectiveView']);
        $this->assertFalse($ctx['effectiveEdit']);
        $this->expectExceptionApi(403, fn (): mixed => $this->service->requireNoteEdit(self::NOTE, self::STRANGER));
    }

    public function testCanvasEditorEditsOthersNote(): void
    {
        // Автор — INVITED, но OWNER как editor канваса тоже может править.
        $ctx = $this->service->requireNoteEdit(self::NOTE, self::OWNER);

        $this->assertTrue($ctx['effectiveEdit']);
    }

    // --- requireNoteManage: restore/purge — только ≥editor канваса или автор ---

    public function testNoteEditorCannotManageNote(): void
    {
        // Матрица прав (docs/api.md): note-инвайт editor ✖ для restore/purge.
        $this->notes->grant(self::NOTE, self::STRANGER, 'editor');
        $ctx = $this->service->getNoteContext(self::NOTE, self::STRANGER);
        $this->assertTrue($ctx['effectiveEdit']);

        $this->expectExceptionApi(403, fn (): mixed => $this->service->requireNoteManage(self::NOTE, self::STRANGER));
    }

    public function testAuthorCanManageOwnNote(): void
    {
        $ctx = $this->service->requireNoteManage(self::NOTE, self::INVITED);

        $this->assertTrue($ctx['isAuthor']);
    }

    public function testCanvasEditorCanManageNote(): void
    {
        $this->assertSame(
            self::CANVAS,
            $this->service->requireNoteManage(self::NOTE, self::OWNER)['note']['canvas_id'],
        );
    }

    // --- describeNoteRole: свёртка в одну роль для NoteDetail.myRole ---

    public function testDescribeNoteRolePrefersCanvasRole(): void
    {
        $this->assertSame('owner', $this->service->describeNoteRole($this->roleCtx('owner', false, true, false, null)));
        $this->assertSame('editor', $this->service->describeNoteRole($this->roleCtx('editor', true, true, false, null)));
    }

    public function testDescribeNoteRoleFallsBackToAuthor(): void
    {
        $this->assertSame('author', $this->service->describeNoteRole($this->roleCtx('viewer', true, true, true, null)));
        $this->assertSame('author', $this->service->describeNoteRole($this->roleCtx('none', true, true, true, null)));
    }

    public function testDescribeNoteRoleFallsBackToNoteInvite(): void
    {
        $this->assertSame('editor', $this->service->describeNoteRole($this->roleCtx('none', true, true, false, 'editor')));
        $this->assertSame('viewer', $this->service->describeNoteRole($this->roleCtx('none', false, true, false, 'viewer')));
    }

    public function testDescribeNoteRoleFallsBackToViewerOrNone(): void
    {
        $this->assertSame('viewer', $this->service->describeNoteRole($this->roleCtx('viewer', false, true, false, null)));
        $this->assertSame('none', $this->service->describeNoteRole($this->roleCtx('none', false, false, false, null)));
    }

    // --- assertVerified ---

    public function testAssertVerifiedPassesForVerifiedUser(): void
    {
        $this->users->add($this->user('verified-user', verified: true));

        $this->service->assertVerified('verified-user'); // без исключения
        $this->addToAssertionCount(1);
    }

    public function testAssertVerifiedRejectsUnverified(): void
    {
        $this->users->add($this->user('unverified-user', verified: false));

        $e = $this->expectExceptionApi(403, fn (): mixed => $this->service->assertVerified('unverified-user'));
        $this->assertSame('EMAIL_NOT_VERIFIED', $e->errorCode());
    }

    public function testAssertVerifiedRejectsMissingUser(): void
    {
        $e = $this->expectExceptionApi(403, fn (): mixed => $this->service->assertVerified('ghost-user'));
        $this->assertSame('EMAIL_NOT_VERIFIED', $e->errorCode());
    }

    // --- хелперы ---

    /**
     * @param callable(): mixed $fn
     */
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

    /**
     * @return array{role: string, effectiveEdit: bool, effectiveView: bool, isAuthor: bool, noteRole: string|null}
     */
    private function roleCtx(
        string $role,
        bool $effectiveEdit,
        bool $effectiveView,
        bool $isAuthor,
        ?string $noteRole,
    ): array {
        return [
            'role' => $role,
            'effectiveEdit' => $effectiveEdit,
            'effectiveView' => $effectiveView,
            'isAuthor' => $isAuthor,
            'noteRole' => $noteRole,
        ];
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
