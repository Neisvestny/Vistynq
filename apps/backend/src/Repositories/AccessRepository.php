<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

use PDO;
use Vistynq\Support\Database;

final class AccessRepository implements AccessRepositoryInterface
{
    public function __construct(private readonly Database $db)
    {
    }

    public function listCanvasEntries(string $canvasId): array
    {
        return $this->listEntries('canvas', $canvasId);
    }

    public function listNoteEntries(string $noteId): array
    {
        return $this->listEntries('note', $noteId);
    }

    public function grantCanvas(string $canvasId, string $userId, string $role, ?string $grantedBy): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO canvas_access (canvas_id, user_id, role, granted_by)
             VALUES (:canvas_id, :user_id, :role, :granted_by)
             ON CONFLICT (canvas_id, user_id)
             DO UPDATE SET role = EXCLUDED.role, granted_by = EXCLUDED.granted_by'
        );
        $stmt->execute([
            'canvas_id' => $canvasId,
            'user_id' => $userId,
            'role' => $role,
            'granted_by' => $grantedBy,
        ]);
    }

    public function grantNote(string $noteId, string $userId, string $role, ?string $grantedBy): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO note_access (note_id, user_id, role, granted_by)
             VALUES (:note_id, :user_id, :role, :granted_by)
             ON CONFLICT (note_id, user_id)
             DO UPDATE SET role = EXCLUDED.role, granted_by = EXCLUDED.granted_by'
        );
        $stmt->execute([
            'note_id' => $noteId,
            'user_id' => $userId,
            'role' => $role,
            'granted_by' => $grantedBy,
        ]);
    }

    public function updateCanvasRole(string $canvasId, string $userId, string $role): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE canvas_access SET role = :role
              WHERE canvas_id = :canvas_id AND user_id = :user_id
              RETURNING user_id'
        );
        $stmt->execute(['role' => $role, 'canvas_id' => $canvasId, 'user_id' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    public function updateNoteRole(string $noteId, string $userId, string $role): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE note_access SET role = :role
              WHERE note_id = :note_id AND user_id = :user_id
              RETURNING user_id'
        );
        $stmt->execute(['role' => $role, 'note_id' => $noteId, 'user_id' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    public function revokeCanvas(string $canvasId, string $userId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM canvas_access WHERE canvas_id = :canvas_id AND user_id = :user_id'
        );
        $stmt->execute(['canvas_id' => $canvasId, 'user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public function revokeNote(string $noteId, string $userId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM note_access WHERE note_id = :note_id AND user_id = :user_id'
        );
        $stmt->execute(['note_id' => $noteId, 'user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Один UNION-запрос на гранты и pending-инвайты ресурса.
     *
     * @return list<array{
     *     user_id: string|null,
     *     email: string,
     *     role: string,
     *     invitation_id: string|null,
     *     pending: bool
     * }>
     */
    private function listEntries(string $resource, string $id): array
    {
        $accessTable = $resource === 'canvas' ? 'canvas_access' : 'note_access';
        $accessColumn = $resource === 'canvas' ? 'canvas_id' : 'note_id';
        $invitationColumn = $resource === 'canvas' ? 'canvas_id' : 'note_id';

        $sql = "SELECT a.user_id, u.email::text AS email, a.role,
                       NULL::uuid AS invitation_id, false AS pending
                  FROM {$accessTable} a
                  JOIN users u ON u.id = a.user_id
                 WHERE a.{$accessColumn} = :resource_id
                UNION ALL
                SELECT NULL::uuid AS user_id, i.email::text AS email, i.role,
                       i.id AS invitation_id, true AS pending
                  FROM invitations i
                 WHERE i.{$invitationColumn} = :resource_id AND i.status = 'pending'
                 ORDER BY email";

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['resource_id' => $id]);

        /** @var list<array{user_id: string|null, email: string, role: string, invitation_id: string|null, pending: bool}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }
}
