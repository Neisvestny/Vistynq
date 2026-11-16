<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use Vistynq\Controllers\AccessController;
use Vistynq\Http\Response;

/**
 * Доступ к канвасу (docs/ROADMAP.md R16, docs/api.md §Доступ):
 * public/private + slug, список участников, мгновенный grant, pending-инвайт,
 * смена роли и отзыв.
 */
final class CanvasAccessTest extends IntegrationTestCase
{
    private string $ownerId;
    private string $editorId;
    private string $viewerId;
    private string $strangerId;
    private string $canvasId;
    private AccessController $access;

    protected function setUp(): void
    {
        parent::setUp();

        $this->access = $this->service(AccessController::class);
        $this->ownerId = $this->createUser();
        $this->editorId = $this->createUser();
        $this->viewerId = $this->createUser();
        $this->strangerId = $this->createUser();
        $this->canvasId = $this->createCanvas($this->ownerId, 'Доступ к канвасу');
    }

    public function testPublicGeneratesSlugAndRotatesItOnRepeat(): void
    {
        $first = $this->setLevel('public', $this->ownerId);
        $this->assertSame('public', $first['level']);
        $this->assertIsString($first['publicSlug']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $first['publicSlug']);

        $second = $this->setLevel('public', $this->ownerId);
        $this->assertSame('public', $second['level']);
        $this->assertNotSame($first['publicSlug'], $second['publicSlug'], 'каждый PUT public ротирует slug');

        $private = $this->setLevel('private', $this->ownerId);
        $this->assertSame('private', $private['level']);
        $this->assertNull($private['publicSlug']);
    }

    public function testAccessLevelRequiresOwnerAndVerifiedEmail(): void
    {
        $this->grantCanvasAccess($this->canvasId, $this->editorId, 'editor');

        $this->assertApiStatus(403, fn (): array => $this->setLevel('public', $this->editorId));
        $this->assertApiStatus(404, fn (): array => $this->setLevel('public', $this->strangerId));

        $unverifiedOwner = $this->createUser(verified: false);
        $canvas = $this->createCanvas($unverifiedOwner);
        $e = $this->assertApiStatus(403, fn (): array => $this->setLevel('public', $unverifiedOwner, $canvas));
        $this->assertSame('EMAIL_NOT_VERIFIED', $e->errorCode());
    }

    public function testInviteExistingUserGrantsImmediately(): void
    {
        $editorEmail = $this->emailOf($this->editorId);

        $response = $this->invite($editorEmail, 'viewer');
        $this->assertSame(201, $response->status());
        $this->assertSame(['status' => 'granted'], $this->json($response));

        $row = $this->canvasAccessRow($this->editorId);
        $this->assertNotNull($row);
        $this->assertSame('viewer', $row['role']);

        $entries = $this->listAccess();
        $entry = $this->entryFor($entries, $editorEmail);
        $this->assertSame($this->editorId, $entry['userId']);
        $this->assertSame('viewer', $entry['role']);
        $this->assertFalse($entry['pending']);
        $this->assertNull($entry['invitationId']);

        // Письмо-уведомление ушло (best-effort, но в dev/CI Mailpit доступен).
        $this->assertInviteMailContains($editorEmail, 'Вам открыли доступ', 'Доступ к канвасу');

        // Повторная выдача уже существующего доступа — 409.
        $this->assertApiStatus(409, fn (): Response => $this->invite($editorEmail, 'editor'));
    }

    public function testInviteUnknownEmailCreatesPendingAndCanBeReinvited(): void
    {
        $email = 'pending-' . bin2hex(random_bytes(6)) . '@example.local';

        $response = $this->invite($email, 'viewer');
        $this->assertSame(201, $response->status());
        $this->assertSame(['status' => 'pending'], $this->json($response));

        $entry = $this->entryFor($this->listAccess(), $email);
        $this->assertNull($entry['userId']);
        $this->assertSame('viewer', $entry['role']);
        $this->assertTrue($entry['pending']);
        $this->assertIsString($entry['invitationId']);

        $this->assertInviteMailContains($email, 'Вас пригласили', 'Доступ к канвасу');

        // Повторный pending-инвайт upsert'ит роль, а не плодит вторую строку.
        $this->assertSame(['status' => 'pending'], $this->json($this->invite($email, 'editor')));
        $entries = array_values(array_filter(
            $this->listAccess(),
            static fn (array $e): bool => $e['email'] === $email,
        ));
        $this->assertCount(1, $entries);
        $this->assertSame('editor', $entries[0]['role']);

        $this->assertSame(204, $this->revokeInvitation($entries[0]['invitationId'])->status());
        $this->assertFalse($this->hasEntry($this->listAccess(), $email));

        $this->assertApiStatus(404, fn (): Response => $this->revokeInvitation($entries[0]['invitationId']));
    }

    public function testUpdateRoleAndRevokeParticipant(): void
    {
        $this->assertSame('granted', $this->json($this->invite($this->emailOf($this->viewerId), 'viewer'))['status']);

        $patch = $this->access->updateCanvasRole($this->request(
            'PATCH',
            "/api/v1/canvases/{$this->canvasId}/access/{$this->viewerId}",
            ['id' => $this->canvasId, 'userId' => $this->viewerId],
            ['role' => 'editor'],
            [],
            [],
            $this->ownerId,
        ));
        $this->assertSame(204, $patch->status());
        $this->assertSame('editor', $this->canvasAccessRow($this->viewerId)['role']);

        $delete = $this->access->revokeCanvas($this->request(
            'DELETE',
            "/api/v1/canvases/{$this->canvasId}/access/{$this->viewerId}",
            ['id' => $this->canvasId, 'userId' => $this->viewerId],
            null,
            [],
            [],
            $this->ownerId,
        ));
        $this->assertSame(204, $delete->status());
        $this->assertNull($this->canvasAccessRow($this->viewerId));

        $this->assertApiStatus(404, fn (): Response => $this->access->revokeCanvas($this->request(
            'DELETE',
            "/api/v1/canvases/{$this->canvasId}/access/{$this->viewerId}",
            ['id' => $this->canvasId, 'userId' => $this->viewerId],
            null,
            [],
            [],
            $this->ownerId,
        )));
    }

    public function testInviteValidationAndRights(): void
    {
        $this->assertApiStatus(422, fn (): Response => $this->invite($this->emailOf($this->ownerId), 'viewer'));
        $this->assertApiStatus(422, fn (): Response => $this->invite('not-an-email', 'viewer'));
        $this->assertApiStatus(422, fn (): Response => $this->invite($this->emailOf($this->strangerId), 'owner'));

        $this->grantCanvasAccess($this->canvasId, $this->editorId, 'editor');
        $this->assertApiStatus(403, fn (): Response => $this->invite($this->emailOf($this->strangerId), 'viewer', $this->editorId));
        $this->assertApiStatus(404, fn (): Response => $this->invite($this->emailOf($this->strangerId), 'viewer', $this->strangerId));
    }

    /**
     * @return array<string, mixed>
     */
    private function setLevel(string $level, string $userId, ?string $canvasId = null): array
    {
        $canvasId ??= $this->canvasId;
        $response = $this->access->setCanvasLevel($this->request(
            'PUT',
            "/api/v1/canvases/{$canvasId}/access-level",
            ['id' => $canvasId],
            ['level' => $level],
            [],
            [],
            $userId,
        ));

        $this->assertSame(200, $response->status());

        return $this->json($response);
    }

    private function invite(string $email, string $role, ?string $userId = null): Response
    {
        return $this->access->inviteCanvas($this->request(
            'POST',
            "/api/v1/canvases/{$this->canvasId}/access/invite",
            ['id' => $this->canvasId],
            ['email' => $email, 'role' => $role],
            [],
            [],
            $userId ?? $this->ownerId,
        ));
    }

    private function revokeInvitation(string $invitationId): Response
    {
        return $this->access->revokeCanvasInvitation($this->request(
            'DELETE',
            "/api/v1/canvases/{$this->canvasId}/invitations/{$invitationId}",
            ['id' => $this->canvasId, 'invitationId' => $invitationId],
            null,
            [],
            [],
            $this->ownerId,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listAccess(): array
    {
        $response = $this->access->listCanvas($this->request(
            'GET',
            "/api/v1/canvases/{$this->canvasId}/access",
            ['id' => $this->canvasId],
            null,
            [],
            [],
            $this->ownerId,
        ));
        $this->assertSame(200, $response->status());

        return $this->json($response);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function canvasAccessRow(string $userId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM canvas_access WHERE canvas_id = :canvas_id AND user_id = :user_id'
        );
        $stmt->execute(['canvas_id' => $this->canvasId, 'user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function emailOf(string $userId): string
    {
        return $this->users->findById($userId)->email;
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return array<string, mixed>
     */
    private function entryFor(array $entries, string $email): array
    {
        foreach ($entries as $entry) {
            if ($entry['email'] === $email) {
                return $entry;
            }
        }

        $this->fail('в списке доступа нет ' . $email);
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function hasEntry(array $entries, string $email): bool
    {
        foreach ($entries as $entry) {
            if ($entry['email'] === $email) {
                return true;
            }
        }

        return false;
    }

    private function assertInviteMailContains(string $email, string ...$needles): void
    {
        foreach ($this->mailpitMessages($email) as $message) {
            $body = $this->mailpitMessageText((string) $message['ID']);
            $missing = array_filter($needles, static fn (string $needle): bool => !str_contains($body, $needle));
            if ($missing === []) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail('письмо для ' . $email . ' не содержит: ' . implode(', ', $needles));
    }
}
