CREATE TABLE canvases (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    owner_id     uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    title        text NOT NULL,
    access_level text NOT NULL DEFAULT 'private' CHECK (access_level IN ('private', 'specific', 'public_link')),
    public_slug  text UNIQUE NULL,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX canvases_owner_id_idx ON canvases (owner_id);

CREATE TABLE notes (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    canvas_id   uuid NOT NULL REFERENCES canvases (id) ON DELETE CASCADE,
    author_id   uuid NOT NULL REFERENCES users (id),
    title       text NOT NULL,
    body_md     text NOT NULL DEFAULT '',
    importance  smallint NOT NULL DEFAULT 3 CHECK (importance BETWEEN 1 AND 5),
    is_relevant boolean NOT NULL DEFAULT true,
    x           double precision NOT NULL DEFAULT 0,
    y           double precision NOT NULL DEFAULT 0,
    width       integer NOT NULL DEFAULT 220,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    deleted_at  timestamptz NULL
);

CREATE INDEX notes_canvas_id_idx ON notes (canvas_id);
CREATE INDEX notes_canvas_id_active_idx ON notes (canvas_id) WHERE deleted_at IS NULL;

CREATE TABLE tags (
    id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    canvas_id  uuid NOT NULL REFERENCES canvases (id) ON DELETE CASCADE,
    name       citext NOT NULL,
    color      text NOT NULL DEFAULT '#7c3aed',
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (canvas_id, name)
);

CREATE TABLE note_tags (
    note_id uuid NOT NULL REFERENCES notes (id) ON DELETE CASCADE,
    tag_id  uuid NOT NULL REFERENCES tags (id) ON DELETE CASCADE,
    PRIMARY KEY (note_id, tag_id)
);

CREATE INDEX note_tags_tag_id_idx ON note_tags (tag_id);

CREATE TABLE direct_links (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    from_note_id uuid NOT NULL REFERENCES notes (id) ON DELETE CASCADE,
    to_note_id   uuid NOT NULL REFERENCES notes (id) ON DELETE CASCADE,
    created_by   uuid NOT NULL REFERENCES users (id),
    created_at   timestamptz NOT NULL DEFAULT now(),
    CHECK (from_note_id <> to_note_id),
    UNIQUE (from_note_id, to_note_id)
);

CREATE INDEX direct_links_from_note_id_idx ON direct_links (from_note_id);
CREATE INDEX direct_links_to_note_id_idx ON direct_links (to_note_id);

CREATE TABLE indirect_links (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    from_note_id uuid NOT NULL REFERENCES notes (id) ON DELETE CASCADE,
    to_note_id   uuid NULL REFERENCES notes (id) ON DELETE CASCADE,
    raw_target   text NOT NULL,
    resolved     boolean NOT NULL DEFAULT false,
    created_at   timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX indirect_links_from_note_id_idx ON indirect_links (from_note_id);
CREATE INDEX indirect_links_to_note_id_idx ON indirect_links (to_note_id);

-- Tombstone переживает заметку: без FK на notes, canvas_id — обычный uuid.
CREATE TABLE deleted_notes (
    note_id        uuid PRIMARY KEY,
    canvas_id      uuid NULL,
    title_snapshot text NOT NULL DEFAULT '',
    deleted_by     uuid NULL REFERENCES users (id) ON DELETE SET NULL,
    deleted_at     timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE canvas_access (
    canvas_id  uuid NOT NULL REFERENCES canvases (id) ON DELETE CASCADE,
    user_id    uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    role       text NOT NULL CHECK (role IN ('viewer', 'editor')),
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (canvas_id, user_id)
);

CREATE INDEX canvas_access_user_id_idx ON canvas_access (user_id);

CREATE TABLE note_access (
    note_id    uuid NOT NULL REFERENCES notes (id) ON DELETE CASCADE,
    user_id    uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    role       text NOT NULL CHECK (role IN ('viewer', 'editor')),
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (note_id, user_id)
);

CREATE INDEX note_access_user_id_idx ON note_access (user_id);

CREATE TABLE invitations (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    resource_type text NOT NULL CHECK (resource_type IN ('canvas', 'note')),
    resource_id   uuid NOT NULL,
    email         citext NOT NULL,
    user_id       uuid NULL REFERENCES users (id) ON DELETE SET NULL,
    role          text NOT NULL CHECK (role IN ('viewer', 'editor')),
    status        text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'accepted', 'declined', 'revoked')),
    token_hash    char(64) UNIQUE NULL,
    invited_by    uuid NULL REFERENCES users (id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    responded_at  timestamptz NULL
);

CREATE INDEX invitations_email_pending_idx ON invitations (email) WHERE status = 'pending';

CREATE TABLE attachments (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    uploader_id uuid NOT NULL REFERENCES users (id),
    note_id     uuid NULL REFERENCES notes (id) ON DELETE SET NULL,
    kind        text NOT NULL CHECK (kind IN ('image', 'audio', 'text_import', 'avatar')),
    s3_key      text UNIQUE NOT NULL,
    filename    text NOT NULL,
    mime        text NOT NULL,
    size_bytes  bigint NOT NULL CHECK (size_bytes > 0),
    width       integer NULL,
    height      integer NULL,
    created_at  timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX attachments_note_id_idx ON attachments (note_id);

-- Цикл users → attachments → notes → users: FK дописывается после создания всех таблиц.
ALTER TABLE users
    ADD CONSTRAINT users_avatar_attachment_id_fkey
    FOREIGN KEY (avatar_attachment_id) REFERENCES attachments (id) ON DELETE SET NULL;
