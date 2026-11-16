<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use Vistynq\Controllers\AccessController;
use Vistynq\Http\Response;

/**
 * Доступ к заметке (docs/api.md §Доступ): видимость public/private,
 * участники/инвайты, мгновенный grant и pending; управляют только
 * владелец канваса и автор заметки.
 */
final class NoteAccessTest extends IntegrationTestCase
{
    private string $ownerId;
    private string $authorId;
    private string $editorId;
    private string $strangerId;
    private string $canvasId;
    private string $noteId;
    private AccessController $access;

    protected function setUp(): void
    {
        parent::setUp();

        $this->access = $this->service(AccessController::class);
        $this->ownerId = $this->createUser();
        $this->authorId = $this->createUser();
        $this->editorId = $this->createUser();
        $this->strangerId = $this->createUser();

        $this->canvasId = $this->createCanvas($this->ownerId, 'Заметки и доступ');
        $this->grantCanvasAccess($this->canvasId, $this->editorId, 'editor');
        $this->noteId = $this->createNote($this->canvasId, $this->authorId, 'Заметка');
    }

    public function testAuthorAndCanvasOwnerCanChangeVisibility(): void
    {
        $this->assertSame(['visibility' => 'private'], $this->setVisibility('private', $this->authorId));
        $this->assertSame('private', $this->noteVisibility());

        $this->assertSame(['visibility' => 'public'], $this->setVisibility('public', $this->ownerId));
        $this->assertSame('public', $this->noteVisibility());
    }

    public function testVisibilityRequiresOwnerOrAuthor(): void
    {
        // Editor канваса видит публичную заметку, но доступом не управляет.
        $this->assertApiStatus(403, fn (): array => $this->setVisibility('private', $this->editorId));
        $this->assertApiStatus(404, fn (): array => $this->setVisibility('private', $this->strangerId));
        $this->assertApiStatus(422, fn (): array => $this->setVisibility('hidden', $this->authorId));
    }

    public function testNoteEditorStillCannotManageVisibility(): void
    {
        $this->grantNoteAccess($this->noteId, $this->strangerId, 'editor');

        $this->assertApiStatus(403, fn (): array => $this->setVisibility('private', $this->strangerId));
    }

    public function testPrivateNoteHidesAccessFromCanvasEditor(): void
    {
        $this->setVisibility('private', $this->authorId);

        // Приватная заметка editor'у канваса не видна вовсе → 404, а не 403.
        $this->assertApiStatus(404, fn (): array => $this->listAccess($this->editorId));
        // Автор список видит.
        $this->assertSame([], $this->listAccess($this->authorId));
    }

    public function testInviteExistingUserGrantsImmediately(): void
    {
        $email = $this->emailOf($this->strangerId);

        $response = $this->invite($email, 'viewer', $this->authorId);
        $this->assertSame(201, $response->status());
        $this->assertSame(['status' => 'granted'], $this->json($response));

        $row = $this->noteAccessRow($this->strangerId);
        $this->assertNotNull($row);
        $this->assertSame('viewer', $row['role']);

        $entry = $this->entryFor($this->listAccess($this->authorId), $email);
        $this->assertSame($this->strangerId, $entry['userId']);
        $this->assertFalse($entry['pending']);

        $this->assertApiStatus(409, fn (): Response => $this->invite($email, 'editor', $this->authorId));
    }

    public function testInviteUnknownEmailCreatesPendingAndCanBeRevoked(): void
    {
        $email = 'note-pending-' . bin2hex(random_bytes(6)) . '@example.local';

        $this->assertSame(['status' => 'pending'], $this->json($this->invite($email, 'editor', $this->authorId)));
        $entry = $this->entryFor($this->listAccess($this->authorId), $email);
        $this->assertNull($entry['userId']);
        $this->assertTrue($entry['pending']);
        $this->assertSame('editor', $entry['role']);

        $this->assertSame(204, $this->revokeInvitation($entry['invitationId'], $this->authorId)->status());
        $this->assertApiStatus(404, fn (): Response => $this->revokeInvitation($entry['invitationId'], $this->authorId));
    }

    public function testInviteAuthorOwnerAndSelfAre422(): void
    {
        // Владелец канваса не может пригласить автора (у него уже есть доступ).
        $this->assertApiStatus(422, fn (): Response => $this->invite($this->emailOf($this->authorId), 'viewer', $this->ownerId));
        // Автор не может пригласить владельца канваса.
        $this->assertApiStatus(422, fn (): Response => $this->invite($this->emailOf($this->ownerId), 'viewer', $this->authorId));
        // Себя приглашать нельзя.
        $this->assertApiStatus(422, fn (): Response => $this->invite($this->emailOf($this->authorId), 'viewer', $this->authorId));
    }

    public function testUpdateRoleAndRevokeNoteAccess(): void
    {
        $this->assertSame('granted', $this->json($this->invite($this->emailOf($this->strangerId), 'viewer', $this->authorId))['status']);

        $patch = $this->access->updateNoteRole($this->request(
            'PATCH',
            "/api/v1/notes/{$this->noteId}/access/{$this->strangerId}",
            ['id' => $this->noteId, 'userId' => $this->strangerId],
            ['role' => 'editor'],
            [],
            [],
            $this->authorId,
        ));
        $this->assertSame(204, $patch->status());
        $this->assertSame('editor', $this->noteAccessRow($this->strangerId)['role']);

        $delete = $this->access->revokeNote($this->request(
            'DELETE',
            "/api/v1/notes/{$this->noteId}/access/{$this->strangerId}",
            ['id' => $this->noteId, 'userId' => $this->strangerId],
            null,
            [],
            [],
            $this->authorId,
        ));
        $this->assertSame(204, $delete->status());
        $this->assertNull($this->noteAccessRow($this->strangerId));

        $this->assertApiStatus(404, fn (): Response => $this->access->revokeNote($this->request(
            'DELETE',
            "/api/v1/notes/{$this->noteId}/access/{$this->strangerId}",
            ['id' => $this->noteId, 'userId' => $this->strangerId],
            null,
            [],
            [],
            $this->authorId,
        )));
    }

    public function testUnverifiedAuthorCannotInvite(): void
    {
        $unverified = $this->createUser(verified: false);
        $noteId = $this->createNote($this->canvasId, $unverified, 'Черновик');

        $e = $this->assertApiStatus(403, fn (): Response => $this->access->inviteNote($this->request(
            'POST',
            "/api/v1/notes/{$noteId}/access/invite",
            ['id' => $noteId],
            ['email' => $this->emailOf($this->strangerId), 'role' => 'viewer'],
            [],
            [],
            $unverified,
        )));
        $this->assertSame('EMAIL_NOT_VERIFIED', $e->errorCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function setVisibility(string $visibility, string $userId): array
    {
        $response = $this->access->setVisibility($this->request(
            'PUT',
            "/api/v1/notes/{$this->noteId}/visibility",
            ['id' => $this->noteId],
            ['visibility' => $visibility],
            [],
            [],
            $userId,
        ));
        $this->assertSame(200, $response->status());

        return $this->json($response);
    }

    private function invite(string $email, string $role, string $userId): Response
    {
        return $this->access->inviteNote($this->request(
            'POST',
            "/api/v1/notes/{$this->noteId}/access/invite",
            ['id' => $this->noteId],
            ['email' => $email, 'role' => $role],
            [],
            [],
            $userId,
        ));
    }

    private function revokeInvitation(string $invitationId, string $userId): Response
    {
        return $this->access->revokeNoteInvitation($this->request(
            'DELETE',
            "/api/v1/notes/{$this->noteId}/invitations/{$invitationId}",
            ['id' => $this->noteId, 'invitationId' => $invitationId],
            null,
            [],
            [],
            $userId,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listAccess(string $userId): array
    {
        $response = $this->access->listNote($this->request(
            'GET',
            "/api/v1/notes/{$this->noteId}/access",
            ['id' => $this->noteId],
            null,
            [],
            [],
            $userId,
        ));
        $this->assertSame(200, $response->status());

        return $this->json($response);
    }

    private function noteVisibility(): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT visibility FROM notes WHERE id = :id');
        $stmt->execute(['id' => $this->noteId]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function noteAccessRow(string $userId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM note_access WHERE note_id = :note_id AND user_id = :user_id'
        );
        $stmt->execute(['note_id' => $this->noteId, 'user_id' => $userId]);
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
}
