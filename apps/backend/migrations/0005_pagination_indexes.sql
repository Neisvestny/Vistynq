-- R19: индексы для пагинации списков (docs/api.md).
-- Канвасы: ORDER BY created_at DESC, id DESC.
-- Заметки: архив/поиск внутри канваса с разными предикатами и сортировками.

CREATE INDEX IF NOT EXISTS canvases_created_at_id_idx
    ON canvases (created_at DESC, id DESC);

-- Живые заметки канваса: поиск и архив «неактуально» (updated_at DESC, id DESC).
CREATE INDEX IF NOT EXISTS notes_canvas_live_updated_id_idx
    ON notes (canvas_id, updated_at DESC, id DESC)
    WHERE deleted_at IS NULL;

-- Soft-deleted заметки канваса: архив «удалённые» (deleted_at DESC, id DESC).
CREATE INDEX IF NOT EXISTS notes_canvas_deleted_deleted_id_idx
    ON notes (canvas_id, deleted_at DESC, id DESC)
    WHERE deleted_at IS NOT NULL;
