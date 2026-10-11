-- CorpVideo 1.1: группы сотрудников, обязательные просмотры, тесты, защищённые ссылки, заметки, версии видео, ИИ.

-- Группы сотрудников (вручную или из групп Active Directory по DN)
CREATE TABLE groups (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name         citext NOT NULL UNIQUE,
  description  text NOT NULL DEFAULT '',
  ldap_dn      text,                       -- DN группы AD: членство синхронизируется при входе через LDAP
  member_count int NOT NULL DEFAULT 0,
  created_by   uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at   timestamptz NOT NULL DEFAULT now(),
  updated_at   timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE group_members (
  group_id   uuid NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
  user_id    uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  added_by   uuid REFERENCES users(id) ON DELETE SET NULL,
  via_ldap   boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (group_id, user_id)
);
CREATE INDEX group_members_user_idx ON group_members(user_id);

-- Доступ к приватным видео целым группам
CREATE TABLE video_group_access (
  video_id   uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  group_id   uuid NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
  granted_by uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (video_id, group_id)
);

-- Назначения: обязательные к просмотру видео и плейлисты
CREATE TABLE assignments (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  kind             text NOT NULL CHECK (kind IN ('video','playlist')),
  video_id         uuid REFERENCES videos(id) ON DELETE CASCADE,
  playlist_id      uuid REFERENCES playlists(id) ON DELETE CASCADE,
  title            text NOT NULL,
  note             text NOT NULL DEFAULT '',
  created_by       uuid REFERENCES users(id) ON DELETE SET NULL,
  due_at           timestamptz,
  required_percent smallint NOT NULL DEFAULT 90,
  require_quiz     boolean NOT NULL DEFAULT false,
  remind_days      smallint NOT NULL DEFAULT 3,
  reminded_at      timestamptz,
  status           text NOT NULL DEFAULT 'active' CHECK (status IN ('active','closed')),
  created_at       timestamptz NOT NULL DEFAULT now(),
  updated_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX assignments_video_idx ON assignments(video_id) WHERE video_id IS NOT NULL;
CREATE INDEX assignments_playlist_idx ON assignments(playlist_id) WHERE playlist_id IS NOT NULL;
CREATE INDEX assignments_creator_idx ON assignments(created_by, created_at DESC);

CREATE TABLE assignment_targets (
  id            bigserial PRIMARY KEY,
  assignment_id uuid NOT NULL REFERENCES assignments(id) ON DELETE CASCADE,
  target_type   text NOT NULL CHECK (target_type IN ('user','group','all')),
  user_id       uuid REFERENCES users(id) ON DELETE CASCADE,
  group_id      uuid REFERENCES groups(id) ON DELETE CASCADE
);
CREATE INDEX assignment_targets_assignment_idx ON assignment_targets(assignment_id);
CREATE INDEX assignment_targets_user_idx ON assignment_targets(user_id) WHERE user_id IS NOT NULL;
CREATE INDEX assignment_targets_group_idx ON assignment_targets(group_id) WHERE group_id IS NOT NULL;

-- Прогресс по назначениям (одна строка на сотрудника и видео)
CREATE TABLE assignment_progress (
  assignment_id uuid NOT NULL REFERENCES assignments(id) ON DELETE CASCADE,
  user_id       uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  video_id      uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  percent       smallint NOT NULL DEFAULT 0,
  completed_at  timestamptz,
  quiz_passed   boolean,
  last_at       timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (assignment_id, user_id, video_id)
);
CREATE INDEX assignment_progress_user_idx ON assignment_progress(user_id);

-- Тесты (проверка знаний) внутри видео
CREATE TABLE video_quizzes (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  video_id      uuid NOT NULL UNIQUE REFERENCES videos(id) ON DELETE CASCADE,
  title         text NOT NULL DEFAULT 'Проверка знаний',
  pass_percent  smallint NOT NULL DEFAULT 70,
  show_answers  boolean NOT NULL DEFAULT true,
  allow_retry   boolean NOT NULL DEFAULT true,
  questions     jsonb NOT NULL DEFAULT '[]',   -- [{id, at, text, type:'single'|'multiple', options:[{id,text,correct}], explanation}]
  updated_at    timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE quiz_attempts (
  id            bigserial PRIMARY KEY,
  quiz_id       uuid NOT NULL REFERENCES video_quizzes(id) ON DELETE CASCADE,
  video_id      uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  user_id       uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  answers       jsonb NOT NULL DEFAULT '{}',   -- {questionId: [optionId,…]}
  correct       smallint NOT NULL DEFAULT 0,
  total         smallint NOT NULL DEFAULT 0,
  percent       smallint NOT NULL DEFAULT 0,
  passed        boolean NOT NULL DEFAULT false,
  finished_at   timestamptz,
  created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX quiz_attempts_user_idx ON quiz_attempts(user_id, quiz_id, created_at DESC);
CREATE INDEX quiz_attempts_video_idx ON quiz_attempts(video_id, created_at DESC);

-- Защищённые ссылки доступа для внешних зрителей
CREATE TABLE share_links (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  video_id       uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  token          text NOT NULL UNIQUE,
  note           text NOT NULL DEFAULT '',
  password_hash  text,
  expires_at     timestamptz,
  max_views      int,
  view_count     int NOT NULL DEFAULT 0,
  allow_download boolean NOT NULL DEFAULT false,
  created_by     uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at     timestamptz NOT NULL DEFAULT now(),
  last_used_at   timestamptz,
  revoked_at     timestamptz
);
CREATE INDEX share_links_video_idx ON share_links(video_id);

-- Личные заметки зрителя с таймкодами
CREATE TABLE user_notes (
  id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id    uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  video_id   uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  position   numeric(10,2) NOT NULL DEFAULT 0,
  body       text NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX user_notes_idx ON user_notes(user_id, video_id, position);

-- Видео: водяной знак зрителя, версии файла, тест, ИИ-подсказки, импорт по ссылке
ALTER TABLE videos ADD COLUMN viewer_watermark boolean NOT NULL DEFAULT false;
ALTER TABLE videos ADD COLUMN version int NOT NULL DEFAULT 1;
ALTER TABLE videos ADD COLUMN replaced_at timestamptz;
ALTER TABLE videos ADD COLUMN has_quiz boolean NOT NULL DEFAULT false;
ALTER TABLE videos ADD COLUMN ai_suggestions jsonb;
ALTER TABLE videos ADD COLUMN source_url text;

-- Загрузки: замена файла существующего видео
ALTER TABLE uploads ADD COLUMN replace boolean NOT NULL DEFAULT false;
