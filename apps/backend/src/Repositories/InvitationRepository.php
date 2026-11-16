<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

use PDO;
use Vistynq\Support\Database;

final class InvitationRepository implements InvitationRepositoryInterface
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findPendingForCanvasEmail(string $canvasId, string $email): ?array
    {
        return $this->findPendingOne('canvas_id', $canvasId, $email);
    }

    public function findPendingForNoteEmail(string $noteId, string $email): ?array
    {
        return $this->findPendingOne('note_id', $noteId, $email);
    }

    public function upsertPendingForCanvas(string $canvasId, string $email, string $role, ?string $invitedBy): array
    {
        return $this->upsertPending('canvas_id', $canvasId, $email, $role, $invitedBy);
    }

    public function upsertPendingForNote(string $noteId, string $email, string $role, ?string $invitedBy): array
    {
        return $this->upsertPending('note_id', $noteId, $email, $role, $invitedBy);
    }

    public function findPendingById(string $invitationId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM invitations WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute(['id' => $invitationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function findPendingForEmail(string $email): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM invitations
              WHERE email = :email AND status = 'pending'
              ORDER BY created_at
                FOR UPDATE"
        );
        $stmt->execute(['email' => $email]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function accept(string $invitationId): void
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE invitations
                SET status = 'accepted', resolved_at = now()
              WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute(['id' => $invitationId]);
    }

    public function revoke(string $invitationId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE invitations
                SET status = 'revoked', resolved_at = now()
              WHERE id = :id AND status = 'pending'
              RETURNING id"
        );
        $stmt->execute(['id' => $invitationId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findPendingOne(string $column, string $id, string $email): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM invitations
              WHERE {$column} = :resource_id AND email = :email AND status = 'pending'
              LIMIT 1"
        );
        $stmt->execute(['resource_id' => $id, 'email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Partial unique index: ON CONFLICT (resource, email) WHERE pending.
     *
     * @return array<string,mixed> the stored invitation row
     */
    private function upsertPending(string $column, string $id, string $email, string $role, ?string $invitedBy): array
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO invitations ({$column}, email, role, invited_by)
             VALUES (:resource_id, :email, :role, :invited_by)
             ON CONFLICT ({$column}, email) WHERE status = 'pending'
             DO UPDATE SET role = EXCLUDED.role, invited_by = EXCLUDED.invited_by
             RETURNING *"
        );
        $stmt->execute([
            'resource_id' => $id,
            'email' => $email,
            'role' => $role,
            'invited_by' => $invitedBy,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }
}
