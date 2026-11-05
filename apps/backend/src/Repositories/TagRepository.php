<?php

declare(strict_types=1);

namespace Vistynq\Repositories;

use PDO;
use Vistynq\Support\Database;

final class TagRepository implements TagRepositoryInterface
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findById(string $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM tags WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<string> $ids
     * @return list<array<string,mixed>> rows of the given ids (id, canvas_id, name)
     */
    public function findManyByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare("SELECT id, canvas_id, name FROM tags WHERE id IN ($in)");
        $stmt->execute(array_values($ids));

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * All tags of a canvas (graph payload + tag pickers).
     *
     * @return list<array<string,mixed>>
     */
    public function findCanvasTags(string $canvasId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, color FROM tags WHERE canvas_id = :canvasId ORDER BY name'
        );
        $stmt->execute(['canvasId' => $canvasId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Новый тег канваса. Нарушение UNIQUE (canvas_id, name) не перехватывается
     * здесь — его ловит TagService и превращает в 409.
     *
     * @return array<string, mixed> row (id, canvas_id, name, color, created_at)
     */
    public function create(string $canvasId, string $name, string $color): array
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tags (canvas_id, name, color) VALUES (:canvasId, :name, :color) RETURNING *'
        );
        $stmt->execute(['canvasId' => $canvasId, 'name' => $name, 'color' => $color]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /**
     * Частичное обновление тега (только переданные поля).
     *
     * @param array<string, string> $fields name и/или color
     *
     * @return array<string, mixed>|null null, если тег исчез между чтением и записью
     */
    public function update(string $id, array $fields): ?array
    {
        if ($fields === []) {
            return $this->findById($id);
        }

        $set = [];
        $params = ['id' => $id];
        foreach ($fields as $column => $value) {
            // whitelist: имя колонки подставляется в SQL
            if (!in_array($column, ['name', 'color'], true)) {
                continue;
            }
            $set[] = "{$column} = :{$column}";
            $params[$column] = $value;
        }
        if ($set === []) {
            return $this->findById($id);
        }

        $stmt = $this->db->pdo()->prepare(
            'UPDATE tags SET ' . implode(', ', $set) . ' WHERE id = :id RETURNING *'
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Hard delete тега: note_tags уходят каскадом (FK ON DELETE CASCADE) —
     * тег «снимается со всех заметок канваса» (docs/api.md).
     */
    public function delete(string $id): void
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM tags WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * note_id → tag names, resolved in one query scoped to the canvas.
     * Deliberately not driven by an IN-list of note ids: the note set is
     * already filtered by NoteRepository::findGraphNotes and re-binding it
     * would only reintroduce the parameter-count bug this replaces.
     *
     * @return array<string, list<string>> noteId => [tagName, ...]
     */
    public function findNoteTagNames(string $canvasId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT nt.note_id, t.name FROM note_tags nt'
            . ' JOIN tags t ON t.id = nt.tag_id'
            . ' JOIN notes n ON n.id = nt.note_id'
            . ' WHERE t.canvas_id = :canvasId AND n.canvas_id = :canvasId'
            . ' ORDER BY t.name'
        );
        $stmt->execute(['canvasId' => $canvasId]);

        $byNote = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byNote[(string) $row['note_id']][] = (string) $row['name'];
        }

        return $byNote;
    }
}
