<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

use PDO;
use Vistynq\Support\Database;

final class NoteRepository implements NoteRepositoryInterface
{
    private const TAG_IDS_AGG = "COALESCE(string_agg(nt.tag_id::text, ',' ORDER BY nt.tag_id), '') AS tag_ids";

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

    /**
     * @param array<string,mixed> $fields
     */
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

    /**
     * Idempotent: an already deleted note keeps its original deleted_at.
     */
    public function softDelete(string $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE notes SET deleted_at = COALESCE(deleted_at, now()), updated_at = now() WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    public function restore(string $id): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE notes SET deleted_at = NULL, updated_at = now() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Hard delete (purge): строка уходит из notes, tombstone в deleted_notes
     * создаёт вызывающий код до этого вызова (в той же транзакции).
     */
    public function hardDelete(string $id): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM notes WHERE id = :id');
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
     * Nodes of GET /canvases/{id}/graph.
     *
     * The canvas-level grant is already established by AccessService; the
     * per-note predicate below mirrors the max(canvas, note) rule of
     * docs/architecture.md and is kept as defence in depth so that a note
     * can never leak through this endpoint. Anonymous callers get a variant
     * without bound user parameters (native prepares need a type context,
     * ":userId IS NOT NULL" has none — see migration/bug in git history).
     *
     * @return list<array<string,mixed>>
     */
    public function findGraphNotes(string $canvasId, ?string $userId): array
    {
        $select = 'SELECT n.id, n.title, n.importance, n.is_relevant, n.x, n.y, n.width, n.deleted_at, n.author_id'
            . ' FROM notes n WHERE n.canvas_id = :canvasId AND n.deleted_at IS NULL';

        if ($userId === null) {
            $sql = $select . " AND (SELECT c.access_level FROM canvases c WHERE c.id = n.canvas_id) = 'public_link'";
            $params = ['canvasId' => $canvasId];
        } else {
            $sql = $select
                . ' AND ('
                . 'n.author_id = :userId'
                . ' OR (SELECT c.owner_id FROM canvases c WHERE c.id = n.canvas_id) = :userId'
                . ' OR EXISTS (SELECT 1 FROM canvas_access ca WHERE ca.canvas_id = n.canvas_id AND ca.user_id = :userId)'
                . ' OR EXISTS (SELECT 1 FROM note_access na WHERE na.note_id = n.id AND na.user_id = :userId)'
                . " OR (SELECT c.access_level FROM canvases c WHERE c.id = n.canvas_id) = 'public_link'"
                . ')';
            $params = ['canvasId' => $canvasId, 'userId' => $userId];
        }

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Full Note rows (with tagIds) of a canvas, for search and archive.
     *
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    private function findNotesBy(string $condition, array $params, string $orderBy): array
    {
        $sql = 'SELECT n.*, ' . self::TAG_IDS_AGG
            . ' FROM notes n'
            . ' LEFT JOIN note_tags nt ON nt.note_id = n.id'
            . ' WHERE ' . $condition
            . ' GROUP BY n.id'
            . ' ORDER BY ' . $orderBy;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Archive «неактуально» (docs/data-model.md: is_relevant = false, живые).
     *
     * @return list<array<string,mixed>>
     */
    public function findNotRelevant(string $canvasId): array
    {
        return $this->findNotesBy(
            'n.canvas_id = :canvasId AND n.deleted_at IS NULL AND n.is_relevant = false',
            ['canvasId' => $canvasId],
            'n.updated_at DESC',
        );
    }

    /**
     * Archive «удалённые» — soft-deleted заметки (tombstone'ы purge приходят
     * отдельно из DeletedNoteRepository: выборки непересекающиеся).
     *
     * @return list<array<string,mixed>>
     */
    public function findDeleted(string $canvasId): array
    {
        return $this->findNotesBy(
            'n.canvas_id = :canvasId AND n.deleted_at IS NOT NULL',
            ['canvasId' => $canvasId],
            'n.deleted_at DESC',
        );
    }

    /**
     * Поиск по заголовкам и тегам в рамках канваса (docs/api.md).
     * Регистр не важен, Unicode — обычный LIKE по text; %/_ в запросе
     * экранируются, чтобы поиск был литеральным.
     *
     * @return list<array<string,mixed>>
     */
    public function search(string $canvasId, string $q): array
    {
        $like = '%' . self::escapeLike($q) . '%';

        $sql = 'SELECT n.*, ' . self::TAG_IDS_AGG
            . ' FROM notes n'
            . ' LEFT JOIN note_tags nt ON nt.note_id = n.id'
            . ' WHERE n.canvas_id = :canvasId AND n.deleted_at IS NULL'
            . " AND (lower(n.title) LIKE lower(:q) ESCAPE '\\'"
            . ' OR EXISTS (SELECT 1 FROM note_tags x JOIN tags t ON t.id = x.tag_id'
            . " WHERE x.note_id = n.id AND lower(t.name) LIKE lower(:q) ESCAPE '\\'))"
            . ' GROUP BY n.id'
            . ' ORDER BY n.updated_at DESC'
            . ' LIMIT 50';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['canvasId' => $canvasId, 'q' => $like]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Резолв [[uuid]]-цели: только живые заметки этого канваса.
     *
     * @param list<string> $ids
     *
     * @return list<string> подмножество $ids
     */
    public function findVisibleIdsInCanvas(string $canvasId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM notes WHERE canvas_id = ? AND deleted_at IS NULL AND id IN ($in)"
        );
        $stmt->execute(array_merge([$canvasId], array_values($ids)));

        return array_values(array_map(
            static fn (array $row): string => (string) $row['id'],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /**
     * Резолв [[Заголовок]]-цели одним запросом: ключ — lower(title) в
     * регистронезависимом виде, значение — id заметки (при дубле — первая
     * по created_at, чтобы результат был детерминированным).
     *
     * @param list<string> $titles
     *
     * @return array<string, string>
     */
    public function findVisibleByLowerTitles(string $canvasId, array $titles): array
    {
        if ($titles === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($titles), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, lower(title) AS lt FROM notes'
            . " WHERE canvas_id = ? AND deleted_at IS NULL AND lower(title) IN ($in)"
            . ' ORDER BY created_at'
        );
        $stmt->execute(array_merge([$canvasId], array_map('mb_strtolower', array_values($titles))));

        $byLower = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = mb_strtolower((string) $row['lt']);
            if (!isset($byLower[$key])) {
                $byLower[$key] = (string) $row['id'];
            }
        }

        return $byLower;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function findTags(string $noteId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.id, t.name, t.color FROM note_tags nt JOIN tags t ON t.id = nt.tag_id'
            . ' WHERE nt.note_id = :noteId ORDER BY t.name, t.id'
        );
        $stmt->execute(['noteId' => $noteId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<mixed> $tagIds
     */
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

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
