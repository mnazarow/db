-- CorpVideo 1.2: SSO OIDC, срок публикации, вложения, опросы и вопросы эфиров, напоминания, сертификаты,
-- Telegram, интеграция с RAG, контроль присутствия.

-- Пользователи: вход через OIDC, Telegram
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_auth_provider_check;
ALTER TABLE users ADD CONSTRAINT users_auth_provider_check CHECK (auth_provider IN ('local','ldap','oidc'));
ALTER TABLE users ADD COLUMN oidc_sub text UNIQUE;
ALTER TABLE users ADD COLUMN telegram_chat_id bigint;
ALTER TABLE users ADD COLUMN telegram_username text;
ALTER TABLE users ADD COLUMN telegram_link_code text;
ALTER TABLE users ADD COLUMN telegram_linked_at timestamptz;

-- Видео: срок публикации, выгрузка в RAG
ALTER TABLE videos ADD COLUMN expires_at timestamptz;          -- после этой даты видео становится приватным
ALTER TABLE videos ADD COLUMN expired_at timestamptz;          -- когда фактически снято с публикации
ALTER TABLE videos ADD COLUMN rag_synced_at timestamptz;       -- когда документ последний раз выгружен в RAG
ALTER TABLE videos ADD COLUMN rag_hash text;                   -- хэш выгруженного содержимого
ALTER TABLE videos ADD COLUMN rag_error text;
CREATE INDEX videos_expires_idx ON videos(expires_at) WHERE expires_at IS NOT NULL AND expired_at IS NULL;

-- Вложения к видео (презентации, документы)
CREATE TABLE video_attachments (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  video_id    uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  title       text NOT NULL,
  filename    text NOT NULL,
  path        text NOT NULL,
  size        bigint NOT NULL DEFAULT 0,
  mime        text,
  downloads   int NOT NULL DEFAULT 0,
  created_by  uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX video_attachments_video_idx ON video_attachments(video_id, created_at);

-- Трансляции: напоминания о запланированных эфирах, опросы, вопросы спикеру
CREATE TABLE live_reminders (
  stream_id  uuid NOT NULL REFERENCES live_streams(id) ON DELETE CASCADE,
  user_id    uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (stream_id, user_id)
);
ALTER TABLE live_streams ADD COLUMN reminded_at timestamptz;
ALTER TABLE live_streams ADD COLUMN qa_enabled boolean NOT NULL DEFAULT true;
ALTER TABLE live_streams ADD COLUMN polls_enabled boolean NOT NULL DEFAULT true;

CREATE TABLE live_polls (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  stream_id   uuid NOT NULL REFERENCES live_streams(id) ON DELETE CASCADE,
  question    text NOT NULL,
  options     jsonb NOT NULL DEFAULT '[]',   -- [{id, text}]
  multiple    boolean NOT NULL DEFAULT false,
  status      text NOT NULL DEFAULT 'open' CHECK (status IN ('draft','open','closed')),
  show_results boolean NOT NULL DEFAULT true,
  created_by  uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at  timestamptz NOT NULL DEFAULT now(),
  opened_at   timestamptz,
  closed_at   timestamptz
);
CREATE INDEX live_polls_stream_idx ON live_polls(stream_id, created_at DESC);
CREATE TABLE live_poll_votes (
  poll_id    uuid NOT NULL REFERENCES live_polls(id) ON DELETE CASCADE,
  user_id    uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  option_ids jsonb NOT NULL DEFAULT '[]',
  created_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (poll_id, user_id)
);

CREATE TABLE live_questions (
  id          bigserial PRIMARY KEY,
  stream_id   uuid NOT NULL REFERENCES live_streams(id) ON DELETE CASCADE,
  user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  body        text NOT NULL,
  anonymous   boolean NOT NULL DEFAULT false,
  upvotes     int NOT NULL DEFAULT 0,
  status      text NOT NULL DEFAULT 'new' CHECK (status IN ('new','answered','hidden')),
  answer      text,
  answered_by uuid REFERENCES users(id) ON DELETE SET NULL,
  answered_at timestamptz,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX live_questions_stream_idx ON live_questions(stream_id, status, upvotes DESC, created_at);
CREATE TABLE live_question_votes (
  question_id bigint NOT NULL REFERENCES live_questions(id) ON DELETE CASCADE,
  user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  PRIMARY KEY (question_id, user_id)
);

-- Сертификаты о прохождении (назначение выполнено)
CREATE TABLE certificates (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  number        text NOT NULL UNIQUE,
  user_id       uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  assignment_id uuid REFERENCES assignments(id) ON DELETE SET NULL,
  video_id      uuid REFERENCES videos(id) ON DELETE SET NULL,
  playlist_id   uuid REFERENCES playlists(id) ON DELETE SET NULL,
  title         text NOT NULL,
  details       jsonb NOT NULL DEFAULT '{}',   -- {percent, quizPercent, durationSec, videos, issuerName, issuerTitle}
  issued_at     timestamptz NOT NULL DEFAULT now(),
  revoked_at    timestamptz
);
CREATE INDEX certificates_user_idx ON certificates(user_id, issued_at DESC);
CREATE UNIQUE INDEX certificates_assignment_user_idx ON certificates(assignment_id, user_id) WHERE assignment_id IS NOT NULL;

-- Назначения: контроль присутствия (периодическое подтверждение «я смотрю»)
ALTER TABLE assignments ADD COLUMN attention_check_min smallint NOT NULL DEFAULT 0;   -- 0 — выключено
ALTER TABLE assignments ADD COLUMN certificate boolean NOT NULL DEFAULT false;         -- выдавать сертификат при выполнении

-- Вход через OIDC: одноразовые состояния (state → nonce, redirect)
CREATE TABLE oidc_states (
  state      text PRIMARY KEY,
  nonce      text NOT NULL,
  verifier   text NOT NULL,
  next_url   text,
  created_at timestamptz NOT NULL DEFAULT now()
);

-- Нумерация сертификатов
CREATE SEQUENCE certificates_number_seq START 1;
