-- 0004: выравнивание схемы с docs/data-model.md (расхождение №1 кода с доками).
-- Только ALTER + ремонт/перенос данных; применённые 0001–0003 не переписываем.
-- Все проверки dev-данных (width, title, цвета тегов, инвайты, дубли линков)
-- выполнены до написания: нарушения чинятся здесь же, до добавления CHECK/UNIQUE.

-- ============ users ============
ALTER TABLE users ADD COLUMN pending_email citext NULL;
CREATE UNIQUE INDEX users_pending_email_key ON users (pending_email) WHERE pending_email IS NOT NULL;

-- ============ auth_tokens ============
ALTER TABLE auth_tokens DROP CONSTRAINT auth_tokens_purpose_check;
ALTER TABLE auth_tokens ADD CONSTRAINT auth_tokens_purpose_check
    CHECK (purpose IN ('email_verify', 'email_change', 'password_reset'));

-- ============ canvases ============
-- Ремонт данных: 'specific' → 'private'; у private обнуляется slug
-- (оба значения допустимы старым CHECK).
UPDATE canvases SET access_level = 'private', public_slug = NULL WHERE access_level = 'specific';
UPDATE canvases SET public_slug = NULL WHERE access_level = 'private' AND public_slug IS NOT NULL;

-- Старый CHECK ('private','specific','public_link') снимается до записи 'public':
-- у публичного канваса dev-данных slug был NULL, строка чинится ниже.
ALTER TABLE canvases DROP CONSTRAINT canvases_access_level_check;
UPDATE canvases
   SET access_level = 'public',
       public_slug = trim(both '-' from COALESCE(NULLIF(regexp_replace(lower(title), '[^a-z0-9]+', '-', 'g'), ''), 'canvas'))
                     || '-' || left(md5(id::text), 8)
 WHERE access_level = 'public_link';

-- title длиной > 200 в dev нет (проверено) — CHECK безопасен.
ALTER TABLE canvases ADD CONSTRAINT canvases_access_level_check
    CHECK (access_level IN ('private', 'public'));
ALTER TABLE canvases ADD CONSTRAINT canvases_public_slug_check
    CHECK ((access_level = 'public') = (public_slug IS NOT NULL));
ALTER TABLE canvases ADD CONSTRAINT canvases_title_len_check
    CHECK (char_length(title) <= 200);

-- ============ notes ============
-- Ремонт: 2 строки с width из диапазона (-5, 2) → clamp к границам (120..800).
UPDATE notes SET width = least(greatest(width, 120), 800) WHERE width < 120 OR width > 800;

ALTER TABLE notes ADD COLUMN visibility text NOT NULL DEFAULT 'public';
ALTER TABLE notes ADD CONSTRAINT notes_visibility_check
    CHECK (visibility IN ('public', 'private'));
-- title длиной > 200 в dev нет (проверено).
ALTER TABLE notes ADD CONSTRAINT notes_title_len_check
    CHECK (char_length(title) <= 200);
ALTER TABLE notes ADD CONSTRAINT notes_width_check
    CHECK (width BETWEEN 120 AND 800);
CREATE INDEX notes_author_id_idx ON notes (author_id);

-- ============ tags ============
-- Ремонт: 2 битых цвета ('red', '') → дефолтный; имён > 50 в dev нет (проверено).
UPDATE tags SET color = '#7c3aed' WHERE color !~ '^#[0-9a-fA-F]{6}$';
ALTER TABLE tags ADD CONSTRAINT tags_name_len_check
    CHECK (char_length(name) <= 50);
ALTER TABLE tags ADD CONSTRAINT tags_color_check
    CHECK (color ~ '^#[0-9a-fA-F]{6}$');

-- ============ indirect_links ============
-- Дедуп: 1 группа дублей dev-данных (2 строки) — иначе упадёт UNIQUE.
DELETE FROM indirect_links a
    USING indirect_links b
 WHERE a.from_note_id = b.from_note_id
   AND a.raw_target = b.raw_target
   AND (a.created_at, a.id) > (b.created_at, b.id);

ALTER TABLE indirect_links ADD COLUMN link_label text NULL;
-- Без FK: id стёртой (purge) цели переживает саму строку notes.
ALTER TABLE indirect_links ADD COLUMN deleted_target_id uuid NULL;
CREATE UNIQUE INDEX indirect_links_from_note_id_raw_target_key
    ON indirect_links (from_note_id, raw_target);

-- ============ deleted_notes ============
-- Ремонт: 1 tombstone-сирота (канвас не существует) — удаляется до FK.
DELETE FROM deleted_notes dn
 WHERE NOT EXISTS (SELECT 1 FROM canvases c WHERE c.id = dn.canvas_id);
ALTER TABLE deleted_notes ALTER COLUMN canvas_id SET NOT NULL;
ALTER TABLE deleted_notes ADD CONSTRAINT deleted_notes_canvas_id_fkey
    FOREIGN KEY (canvas_id) REFERENCES canvases (id) ON DELETE CASCADE;

-- ============ canvas_access / note_access ============
ALTER TABLE canvas_access ADD COLUMN granted_by uuid NULL
    REFERENCES users (id) ON DELETE SET NULL;
ALTER TABLE note_access ADD COLUMN granted_by uuid NULL
    REFERENCES users (id) ON DELETE SET NULL;

-- ============ invitations ============
-- 1) Сирота: resource_id без существующего канваса — удалить до FK.
DELETE FROM invitations i
 WHERE (i.resource_type = 'canvas'
        AND NOT EXISTS (SELECT 1 FROM canvases c WHERE c.id = i.resource_id))
    OR (i.resource_type = 'note'
        AND NOT EXISTS (SELECT 1 FROM notes n WHERE n.id = i.resource_id));

-- 2) Перенос полиморфии в canvas_id / note_id (ровно одно — CHECK ниже).
ALTER TABLE invitations ADD COLUMN canvas_id uuid NULL
    REFERENCES canvases (id) ON DELETE CASCADE;
ALTER TABLE invitations ADD COLUMN note_id uuid NULL
    REFERENCES notes (id) ON DELETE CASCADE;
UPDATE invitations SET canvas_id = resource_id WHERE resource_type = 'canvas';
UPDATE invitations SET note_id = resource_id WHERE resource_type = 'note';

-- 3) Дедуп pending-инвайтов: 1 дубль (canvas+email pending) нарушил бы новый UNIQUE.
DELETE FROM invitations a
    USING invitations b
 WHERE a.status = 'pending'
   AND b.status = 'pending'
   AND a.email = b.email
   AND a.id <> b.id
   AND ((a.canvas_id IS NOT NULL AND a.canvas_id = b.canvas_id)
        OR (a.note_id IS NOT NULL AND a.note_id = b.note_id))
   AND (a.created_at, a.id) > (b.created_at, b.id);

-- 4) Колонки полиморфии и поля вне data-model (кодом не используются) — убрать.
--    invitations_resource_type_check уходит вместе с колонкой resource_type.
ALTER TABLE invitations DROP COLUMN resource_type;
ALTER TABLE invitations DROP COLUMN resource_id;
ALTER TABLE invitations DROP COLUMN token_hash;
ALTER TABLE invitations DROP COLUMN user_id;
ALTER TABLE invitations RENAME COLUMN responded_at TO resolved_at;

-- 5) Статусы: 'declined' строк в dev нет → CHECK из data-model.
UPDATE invitations SET status = 'revoked' WHERE status NOT IN ('pending', 'accepted', 'revoked');
ALTER TABLE invitations DROP CONSTRAINT invitations_status_check;
ALTER TABLE invitations ADD CONSTRAINT invitations_status_check
    CHECK (status IN ('pending', 'accepted', 'revoked'));
ALTER TABLE invitations ADD CONSTRAINT invitations_target_check
    CHECK ((canvas_id IS NOT NULL) <> (note_id IS NOT NULL));
CREATE UNIQUE INDEX invitations_canvas_email_pending_key
    ON invitations (canvas_id, email) WHERE status = 'pending';
CREATE UNIQUE INDEX invitations_note_email_pending_key
    ON invitations (note_id, email) WHERE status = 'pending';

-- ============ attachments ============
-- 0 строк в dev (проверено): 'text_import' убираем, note_id → CASCADE.
ALTER TABLE attachments DROP CONSTRAINT attachments_kind_check;
ALTER TABLE attachments ADD CONSTRAINT attachments_kind_check
    CHECK (kind IN ('image', 'audio', 'avatar'));
ALTER TABLE attachments DROP CONSTRAINT attachments_note_id_fkey;
ALTER TABLE attachments ADD CONSTRAINT attachments_note_id_fkey
    FOREIGN KEY (note_id) REFERENCES notes (id) ON DELETE CASCADE;
