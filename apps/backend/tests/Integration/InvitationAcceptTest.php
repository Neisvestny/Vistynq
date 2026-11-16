<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use Vistynq\Controllers\AccessController;
use Vistynq\Controllers\AuthController;
use Vistynq\Controllers\ProfileController;

/**
 * Резолв pending-инвайтов (docs/api.md §Доступ, R16): доступ выдаётся
 * автоматически после подтверждения email — при регистрации и при смене
 * адреса. Отдельного «принять»-эндпоинта нет.
 */
final class InvitationAcceptTest extends IntegrationTestCase
{
    private string $ownerId;
    private string $canvasId;
    private string $noteId;
    private AccessController $access;
    private AuthController $auth;
    private ProfileController $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->access = $this->service(AccessController::class);
        $this->auth = $this->service(AuthController::class);
        $this->profile = $this->service(ProfileController::class);

        $this->ownerId = $this->createUser();
        $this->canvasId = $this->createCanvas($this->ownerId, 'Инвайты');
        $this->noteId = $this->createNote($this->canvasId, $this->ownerId, 'Заметка с инвайтом');
    }

    public function testPendingCanvasInviteIsGrantedAfterRegistrationVerification(): void
    {
        $email = $this->tempEmail('accept-canvas');

        $invitationId = $this->inviteCanvas($email, 'editor');
        $this->assertFalse($this->invitationAccepted($invitationId));

        $userId = $this->registerAndVerify($email);

        $this->assertSame('editor', $this->canvasAccessRole($userId));
        $this->assertTrue($this->invitationAccepted($invitationId), 'pending → accepted');
    }

    public function testPendingNoteInviteIsGrantedAfterRegistrationVerification(): void
    {
        $email = $this->tempEmail('accept-note');

        $invitationId = $this->inviteNote($email, 'viewer');

        $userId = $this->registerAndVerify($email);

        $this->assertSame('viewer', $this->noteAccessRole($userId));
        $this->assertTrue($this->invitationAccepted($invitationId));
    }

    public function testPendingInviteIsGrantedAfterEmailChangeVerification(): void
    {
        $userId = $this->createUser();
        $newEmail = $this->tempEmail('accept-change');

        $invitationId = $this->inviteCanvas($newEmail, 'viewer');

        $patch = $this->profile->update($this->request(
            'PATCH',
            '/api/v1/profile',
            [],
            ['email' => $newEmail],
            [],
            [],
            $userId,
        ));
        $this->assertSame(200, $patch->status());

        $token = $this->fetchVerifyTokenFromMailpit($newEmail);
        $verify = $this->auth->verifyEmail($this->request(
            'POST',
            '/api/v1/auth/verify-email',
            [],
            ['token' => $token],
        ));
        $this->assertSame(200, $verify->status());

        $this->assertSame($newEmail, $this->users->findById($userId)->email);
        $this->assertSame('viewer', $this->canvasAccessRole($userId));
        $this->assertTrue($this->invitationAccepted($invitationId));
    }

    public function testExistingUnverifiedUserGetsInstantGrant(): void
    {
        // Решение R16: аккаунт существует → grant сразу, даже без подтверждения.
        $unverified = $this->createUser(verified: false);
        $email = $this->users->findById($unverified)->email;

        $response = $this->access->inviteCanvas($this->request(
            'POST',
            "/api/v1/canvases/{$this->canvasId}/access/invite",
            ['id' => $this->canvasId],
            ['email' => $email, 'role' => 'viewer'],
            [],
            [],
            $this->ownerId,
        ));

        $this->assertSame(201, $response->status());
        $this->assertSame(['status' => 'granted'], $this->json($response));
        $this->assertSame('viewer', $this->canvasAccessRole($unverified));
    }

    public function testVerifyWithoutPendingInvitesIsNoop(): void
    {
        $email = $this->tempEmail('accept-none');
        $userId = $this->registerAndVerify($email);

        $this->assertNull($this->canvasAccessRole($userId));
        $this->assertSame(0, $this->pendingCount($email));
    }

    private function inviteCanvas(string $email, string $role): string
    {
        $response = $this->access->inviteCanvas($this->request(
            'POST',
            "/api/v1/canvases/{$this->canvasId}/access/invite",
            ['id' => $this->canvasId],
            ['email' => $email, 'role' => $role],
            [],
            [],
            $this->ownerId,
        ));
        $this->assertSame(201, $response->status());
        $this->assertSame(['status' => 'pending'], $this->json($response));

        return $this->pendingInvitationId('canvas_id', $this->canvasId, $email);
    }

    private function inviteNote(string $email, string $role): string
    {
        $response = $this->access->inviteNote($this->request(
            'POST',
            "/api/v1/notes/{$this->noteId}/access/invite",
            ['id' => $this->noteId],
            ['email' => $email, 'role' => $role],
            [],
            [],
            $this->ownerId,
        ));
        $this->assertSame(201, $response->status());
        $this->assertSame(['status' => 'pending'], $this->json($response));

        return $this->pendingInvitationId('note_id', $this->noteId, $email);
    }

    private function registerAndVerify(string $email): string
    {
        $register = $this->auth->register($this->request(
            'POST',
            '/api/v1/auth/register',
            [],
            ['email' => $email, 'password' => 'secret-pass-123', 'displayName' => 'Invite Test'],
        ));
        $this->assertSame(201, $register->status());

        $userId = $this->userIdByEmail($email);
        $this->assertNotNull($userId);
        $this->trackUserId($userId);

        $token = $this->fetchVerifyTokenFromMailpit($email);
        $verify = $this->auth->verifyEmail($this->request(
            'POST',
            '/api/v1/auth/verify-email',
            [],
            ['token' => $token],
        ));
        $this->assertSame(200, $verify->status());

        return $userId;
    }

    private function tempEmail(string $prefix): string
    {
        return sprintf('%s-%s@example.local', $prefix, bin2hex(random_bytes(6)));
    }

    private function pendingInvitationId(string $column, string $resourceId, string $email): string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM invitations
              WHERE {$column} = :resource_id AND email = :email AND status = 'pending'"
        );
        $stmt->execute(['resource_id' => $resourceId, 'email' => $email]);
        $id = $stmt->fetchColumn();

        $this->assertIsString($id, 'pending-инвайт не создан для ' . $email);

        return $id;
    }

    private function invitationAccepted(string $invitationId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM invitations WHERE id = :id');
        $stmt->execute(['id' => $invitationId]);

        return $stmt->fetchColumn() === 'accepted';
    }

    private function pendingCount(string $email): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT count(*) FROM invitations WHERE email = :email AND status = 'pending'"
        );
        $stmt->execute(['email' => $email]);

        return (int) $stmt->fetchColumn();
    }

    private function canvasAccessRole(string $userId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT role FROM canvas_access WHERE canvas_id = :canvas_id AND user_id = :user_id'
        );
        $stmt->execute(['canvas_id' => $this->canvasId, 'user_id' => $userId]);
        $role = $stmt->fetchColumn();

        return is_string($role) ? $role : null;
    }

    private function noteAccessRole(string $userId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT role FROM note_access WHERE note_id = :note_id AND user_id = :user_id'
        );
        $stmt->execute(['note_id' => $this->noteId, 'user_id' => $userId]);
        $role = $stmt->fetchColumn();

        return is_string($role) ? $role : null;
    }
}
