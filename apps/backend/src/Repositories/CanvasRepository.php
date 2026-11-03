<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

use PDO;
use Vistynq\Support\Database;

final class CanvasRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findById(string $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM canvases WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function findForUser(string $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.*,
                    CASE
                        WHEN c.owner_id = :userId THEN 'owner'
                        WHEN ca.role IS NOT NULL THEN ca.role
                        ELSE NULL
                    END as my_role
             FROM canvases c
             LEFT JOIN canvas_access ca ON ca.canvas_id = c.id AND ca.user_id = :userId
             WHERE c.owner_id = :userId OR ca.user_id IS NOT NULL
             ORDER BY c.created_at DESC"
        );
        $stmt->execute(['userId' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string,mixed> the inserted row
     */
    public function create(string $ownerId, string $title): array
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO canvases (owner_id, title) VALUES (:owner_id, :title) RETURNING *'
        );
        $stmt->execute(['owner_id' => $ownerId, 'title' => $title]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /**
     * Whitelisted columns only — mass assignment of owner_id / access_level /
     * public_slug must stay impossible.
     *
     * @param array<string,mixed> $fields
     */
    public function update(string $id, array $fields): void
    {
        $sets = [];
        $params = ['id' => $id];
        foreach (['title'] as $key) {
            if (array_key_exists($key, $fields)) {
                $sets[] = $key . ' = :' . $key;
                $params[$key] = $fields[$key];
            }
        }
        if ($sets === []) {
            return;
        }
        $sets[] = 'updated_at = now()';
        $sql = 'UPDATE canvases SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Hard delete with cascade (docs/ROADMAP.md R11, docs/data-model.md).
     *
     * Everything reachable through an FK goes with the DELETE (notes, tags,
     * note_tags, canvas_access, note_access, direct/indirect links). Rows
     * referencing a canvas polymorphically — invitations (resource_id has no
     * FK by design) and deleted_notes tombstones (canvas_id is a plain uuid
     * so a tombstone can outlive its note) — are removed explicitly, in the
     * same transaction, so no orphaned record can survive the canvas.
     */
    public function delete(string $id): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'DELETE FROM invitations
                 WHERE (resource_type = \'canvas\' AND resource_id = :id)
                    OR (resource_type = \'note\' AND resource_id IN (SELECT id FROM notes WHERE canvas_id = :id))'
            );
            $stmt->execute(['id' => $id]);

            $stmt = $pdo->prepare('DELETE FROM deleted_notes WHERE canvas_id = :id');
            $stmt->execute(['id' => $id]);

            $stmt = $pdo->prepare('DELETE FROM canvases WHERE id = :id');
            $stmt->execute(['id' => $id]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function findCanvasAccess(string $canvasId, string $userId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT role FROM canvas_access WHERE canvas_id = :canvas_id AND user_id = :user_id');
        $stmt->execute(['canvas_id' => $canvasId, 'user_id' => $userId]);
        $role = $stmt->fetchColumn();

        return is_string($role) ? $role : null;
    }
}
