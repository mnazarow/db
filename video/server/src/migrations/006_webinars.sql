-- 006: вебинары (регистрация и учёт присутствия), качество воспроизведения, ИИ-поиск по видеотеке.
-- Версия 1.5.

-- --- Вебинары: регистрация на эфир -------------------------------------------------------------
ALTER TABLE live_streams ADD COLUMN registration boolean NOT NULL DEFAULT false;
ALTER TABLE live_streams ADD COLUMN registration_limit int;
ALTER TABLE live_streams ADD COLUMN registration_note text NOT NULL DEFAULT '';

CREATE TABLE live_registrations (
  id         bigserial PRIMARY KEY,
  stream_id  uuid NOT NULL REFERENCES live_streams(id) ON DELETE CASCADE,
  user_id    uuid REFERENCES users(id) ON DELETE CASCADE,
  name       text NOT NULL DEFAULT '',
  email      citext,
  note       text NOT NULL DEFAULT '',
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX live_registrations_user_idx ON live_registrations(stream_id, user_id) WHERE user_id IS NOT NULL;
CREATE UNIQUE INDEX live_registrations_email_idx ON live_registrations(stream_id, email) WHERE email IS NOT NULL;
CREATE INDEX live_registrations_stream_idx ON live_registrations(stream_id, created_at);

-- Присутствие на эфире: накапливается по «сердцебиению» плеера
CREATE TABLE live_attendance (
  stream_id uuid NOT NULL REFERENCES live_streams(id) ON DELETE CASCADE,
  user_id   uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  seconds   int NOT NULL DEFAULT 0,
  first_at  timestamptz NOT NULL DEFAULT now(),
  last_at   timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (stream_id, user_id)
);
CREATE INDEX live_attendance_stream_idx ON live_attendance(stream_id, seconds DESC);

-- --- Качество воспроизведения (QoE) ------------------------------------------------------------
CREATE TABLE playback_sessions (
  id             bigserial PRIMARY KEY,
  session_key    text NOT NULL UNIQUE,
  video_id       uuid REFERENCES videos(id) ON DELETE CASCADE,
  stream_id      uuid REFERENCES live_streams(id) ON DELETE CASCADE,
  user_id        uuid REFERENCES users(id) ON DELETE SET NULL,
  startup_ms     int,                              -- время до первого кадра
  watch_sec      int NOT NULL DEFAULT 0,
  rebuffer_count int NOT NULL DEFAULT 0,
  rebuffer_ms    int NOT NULL DEFAULT 0,
  quality_height int,
  bitrate_kbps   int,
  errors         int NOT NULL DEFAULT 0,
  error_text     text,
  started        boolean NOT NULL DEFAULT false,   -- дошло ли до первого кадра
  device         text,                             -- desktop | mobile | tablet
  browser        text,
  source         text,                             -- watch | embed | live | shorts | studio
  created_at     timestamptz NOT NULL DEFAULT now(),
  updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX playback_sessions_created_idx ON playback_sessions(created_at DESC);
CREATE INDEX playback_sessions_video_idx ON playback_sessions(video_id, created_at DESC);
CREATE INDEX playback_sessions_stream_idx ON playback_sessions(stream_id, created_at DESC) WHERE stream_id IS NOT NULL;

-- --- Журнал вопросов к видеотеке (ИИ-поиск) ----------------------------------------------------
CREATE TABLE ai_search_log (
  id         bigserial PRIMARY KEY,
  user_id    uuid REFERENCES users(id) ON DELETE SET NULL,
  question   text NOT NULL,
  answer     text NOT NULL DEFAULT '',
  sources    jsonb NOT NULL DEFAULT '[]',
  ms         int,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ai_search_log_created_idx ON ai_search_log(created_at DESC);
