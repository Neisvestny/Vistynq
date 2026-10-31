<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

use PDO;
use Vistynq\Support\Database;

final class NoteRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findById(string $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM notes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function create(string $canvasId, string $authorId, string $title, float $x, float $y): string
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO notes (canvas_id, author_id, title, x, y) VALUES (:canvas_id, :author_id, :title, :x, :y) RETURNING id'
        );
        $stmt->execute([
            'canvas_id' => $canvasId,
            'author_id' => $authorId,
            'title' => $title,
            'x' => $x,
            'y' => $y,
        ]);
        $id = $stmt->fetchColumn();

        return is_string($id) ? $id : '';
    }

    public function update(string $id, array $fields): void
    {
        $allowed = ['title', 'importance', 'is_relevant', 'x', 'y', 'width'];
        $sets = [];
        $params = ['id' => $id];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $fields)) {
                $sets[] = $key . ' = :' . $key;
                $params[$key] = $fields[$key];
            }
        }
        if ($sets === []) {
            return;
        }
        $sets[] = 'updated_at = now()';
        $sql = 'UPDATE notes SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
    }

    public function updateBody(string $id, string $bodyMd): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE notes SET body_md = :body_md, updated_at = now() WHERE id = :id');
        $stmt->execute(['id' => $id, 'body_md' => $bodyMd]);
    }

    public function softDelete(string $id): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE notes SET deleted_at = now(), updated_at = now() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function restore(string $id): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE notes SET deleted_at = NULL, updated_at = now() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function findNoteAccess(string $noteId, string $userId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT role FROM note_access WHERE note_id = :note_id AND user_id = :user_id');
        $stmt->execute(['note_id' => $noteId, 'user_id' => $userId]);
        $role = $stmt->fetchColumn();

        return is_string($role) ? $role : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function findTags(string $noteId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.id, t.name, t.color FROM note_tags nt JOIN tags t ON t.id = nt.tag_id WHERE nt.note_id = :noteId'
        );
        $stmt->execute(['noteId' => $noteId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function syncTags(string $noteId, array $tagIds): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM note_tags WHERE note_id = :noteId')->execute(['noteId' => $noteId]);
        if ($tagIds === []) {
            return;
        }
        $stmt = $pdo->prepare('INSERT INTO note_tags (note_id, tag_id) VALUES (:noteId, :tagId) ON CONFLICT DO NOTHING');
        foreach ($tagIds as $tagId) {
            if (is_string($tagId) && $tagId !== '') {
                $stmt->execute(['noteId' => $noteId, 'tagId' => $tagId]);
            }
        }
    }
}
