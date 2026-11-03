-- Purge заметки должен оставлять пометку «ссылка удалена» (docs/data-model.md:
-- «Purge — hard delete из БД, строка в deleted_notes, линки → «ссылка удалена»»).
-- С прежним ON DELETE CASCADE строка indirect_links уничтожалась вместе с целью,
-- поэтому state:"removed" в GET /canvases/{id}/graph был недостижим.
-- Колонка уже NULL-допустима, меняется только действие FK.
ALTER TABLE indirect_links
    DROP CONSTRAINT indirect_links_to_note_id_fkey;

ALTER TABLE indirect_links
    ADD CONSTRAINT indirect_links_to_note_id_fkey
    FOREIGN KEY (to_note_id) REFERENCES notes (id) ON DELETE SET NULL;

-- Архив и очистка при hard-delete канваса выборят по canvas_id (сейчас — seq scan).
CREATE INDEX IF NOT EXISTS deleted_notes_canvas_id_idx ON deleted_notes (canvas_id);

-- Удаление канваса убирает его инвайты и инвайты его заметок (полиморфный
-- resource_id без FK, поэтому каскадом не убирается).
CREATE INDEX IF NOT EXISTS invitations_resource_idx ON invitations (resource_type, resource_id);
