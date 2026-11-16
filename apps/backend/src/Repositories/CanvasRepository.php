<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

use PDO;
use Vistynq\Support\Database;

final class CanvasRepository implements CanvasRepositoryInterface
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
        // my_role = NULL (нет canvas_access) маппится Canvas::fromRow в 'none':
        // выдаём и канвасы с доступом только к отдельным заметкам (R12).
        //
        // note_count повторяет предикат NoteRepository::findGraphNotes (обе
        // конъюнкции: есть доступ к канвасу/заметке AND заметка видима по
        // visibility), чтобы число совпадало с числом нод в /graph для этого
        // же пользователя и не раскрывало чужие private-заметки.
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.*,
                    CASE
                        WHEN c.owner_id = :userId THEN 'owner'
                        WHEN ca.role IS NOT NULL THEN ca.role
                        ELSE NULL
                    END as my_role,
                    (SELECT count(*) FROM notes n
                      WHERE n.canvas_id = c.id AND n.deleted_at IS NULL
                        AND (n.author_id = :userId
                             OR c.owner_id = :userId
                             OR EXISTS (SELECT 1 FROM canvas_access ca2
                                 WHERE ca2.canvas_id = c.id AND ca2.user_id = :userId)
                             OR EXISTS (SELECT 1 FROM note_access na2
                                 WHERE na2.note_id = n.id AND na2.user_id = :userId)
                             OR c.access_level = 'public')
                        AND (n.visibility = 'public'
                             OR n.author_id = :userId
                             OR c.owner_id = :userId
                             OR EXISTS (SELECT 1 FROM note_access na3
                                 WHERE na3.note_id = n.id AND na3.user_id = :userId))) AS note_count
             FROM canvases c
             LEFT JOIN canvas_access ca ON ca.canvas_id = c.id AND ca.user_id = :userId
             WHERE c.owner_id = :userId OR ca.user_id IS NOT NULL
                OR EXISTS (SELECT 1 FROM note_access na JOIN notes n2 ON n2.id = na.note_id
                    WHERE na.user_id = :userId AND n2.canvas_id = c.id AND n2.deleted_at IS NULL)
                OR EXISTS (SELECT 1 FROM notes n3
                    WHERE n3.canvas_id = c.id AND n3.author_id = :userId AND n3.deleted_at IS NULL)
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
     * note_tags, canvas_access, note_access, direct/indirect links,
     * invitations и tombstone'ы deleted_notes — их FK на канвас, ON DELETE
     * CASCADE, добавлены миграцией 0004).
     */
    public function delete(string $id): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
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
