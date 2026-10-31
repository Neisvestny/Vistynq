CREATE EXTENSION IF NOT EXISTS citext;

CREATE TABLE users (
    id                   uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    email                citext UNIQUE NOT NULL,
    password_hash        text NOT NULL,
    display_name         text NOT NULL,
    avatar_attachment_id uuid NULL,
    email_verified_at    timestamptz NULL,
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE refresh_tokens (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id      uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    token_hash   char(64) UNIQUE NOT NULL,
    family_id    uuid NOT NULL,
    expires_at   timestamptz NOT NULL,
    created_at   timestamptz NOT NULL DEFAULT now(),
    revoked_at   timestamptz NULL,
    replaced_by  uuid NULL REFERENCES refresh_tokens (id) ON DELETE SET NULL
);

CREATE INDEX refresh_tokens_user_id_idx ON refresh_tokens (user_id);
CREATE INDEX refresh_tokens_family_id_idx ON refresh_tokens (family_id);

CREATE TABLE auth_tokens (
    id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id    uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    purpose    text NOT NULL CHECK (purpose IN ('email_verify', 'password_reset')),
    token_hash char(64) UNIQUE NOT NULL,
    expires_at timestamptz NOT NULL,
    used_at    timestamptz NULL
);

CREATE INDEX auth_tokens_user_id_idx ON auth_tokens (user_id);
