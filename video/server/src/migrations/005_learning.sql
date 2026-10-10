-- 005: учебные программы (курсы), реакции по таймкоду, дополнительные звуковые дорожки,
-- границы вступления и финальной заставки. Версия 1.4.

-- --- Курсы (учебные программы) --------------------------------------------------------------
CREATE TABLE courses (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  slug              text UNIQUE,
  title             text NOT NULL,
  description       text NOT NULL DEFAULT '',
  cover_path        text,
  owner_id          uuid REFERENCES users(id) ON DELETE SET NULL,
  visibility        text NOT NULL DEFAULT 'internal' CHECK (visibility IN ('public','internal','private')),
  status            text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published','archived')),
  sequential        boolean NOT NULL DEFAULT true,     -- шаги открываются по порядку
  required_percent  smallint NOT NULL DEFAULT 90,      -- сколько нужно посмотреть у каждого видео
  issue_certificate boolean NOT NULL DEFAULT true,
  item_count        int NOT NULL DEFAULT 0,
  video_count       int NOT NULL DEFAULT 0,
  duration_sec      int NOT NULL DEFAULT 0,
  created_at        timestamptz NOT NULL DEFAULT now(),
  updated_at        timestamptz NOT NULL DEFAULT now(),
  published_at      timestamptz
);
CREATE INDEX courses_owner_idx ON courses(owner_id, created_at DESC);
CREATE INDEX courses_status_idx ON courses(status, visibility);

-- Шаги курса: видео, материал (файл/ссылка) или текстовый блок; группируются по разделам
CREATE TABLE course_items (
  id          bigserial PRIMARY KEY,
  course_id   uuid NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
  position    int NOT NULL DEFAULT 0,
  section     text NOT NULL DEFAULT '',
  kind        text NOT NULL CHECK (kind IN ('video','material','text')),
  video_id    uuid REFERENCES videos(id) ON DELETE CASCADE,
  title       text NOT NULL DEFAULT '',
  body        text NOT NULL DEFAULT '',
  url         text,
  required    boolean NOT NULL DEFAULT true,
  require_quiz boolean NOT NULL DEFAULT false,         -- для видео с тестом: нужен зачёт
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX course_items_course_idx ON course_items(course_id, position);
CREATE INDEX course_items_video_idx ON course_items(video_id) WHERE video_id IS NOT NULL;

-- Прохождение шагов
CREATE TABLE course_progress (
  course_id    uuid NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
  user_id      uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  item_id      bigint NOT NULL REFERENCES course_items(id) ON DELETE CASCADE,
  percent      smallint NOT NULL DEFAULT 0,
  completed_at timestamptz,
  updated_at   timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (course_id, user_id, item_id)
);
CREATE INDEX course_progress_user_idx ON course_progress(user_id, course_id);

-- Запись на курс (самостоятельная или по назначению)
CREATE TABLE course_enrollments (
  course_id      uuid NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
  user_id        uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  started_at     timestamptz NOT NULL DEFAULT now(),
  completed_at   timestamptz,
  certificate_id uuid REFERENCES certificates(id) ON DELETE SET NULL,
  PRIMARY KEY (course_id, user_id)
);
CREATE INDEX course_enrollments_user_idx ON course_enrollments(user_id, started_at DESC);

-- Назначение курса наравне с видео и плейлистом
ALTER TABLE assignments DROP CONSTRAINT assignments_kind_check;
ALTER TABLE assignments ADD CONSTRAINT assignments_kind_check CHECK (kind IN ('video','playlist','course'));
ALTER TABLE assignments ADD COLUMN course_id uuid REFERENCES courses(id) ON DELETE CASCADE;
CREATE INDEX assignments_course_idx ON assignments(course_id) WHERE course_id IS NOT NULL;

-- Сертификат может выдаваться за курс
ALTER TABLE certificates ADD COLUMN course_id uuid REFERENCES courses(id) ON DELETE SET NULL;

-- --- Реакции по таймкоду ----------------------------------------------------------------------
CREATE TABLE video_reactions (
  id         bigserial PRIMARY KEY,
  video_id   uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  user_id    uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  t          numeric(10,2) NOT NULL,
  kind       text NOT NULL CHECK (kind IN ('like','love','wow','question')),
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX video_reactions_video_idx ON video_reactions(video_id, t);
CREATE INDEX video_reactions_user_idx ON video_reactions(user_id, created_at DESC);

-- --- Дополнительные звуковые дорожки (дубляж, тифлокомментарий) --------------------------------
CREATE TABLE video_audio_tracks (
  id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  video_id   uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  language   text NOT NULL DEFAULT 'ru',
  label      text NOT NULL,
  kind       text NOT NULL DEFAULT 'dub' CHECK (kind IN ('dub','description')),
  path       text,
  size_bytes bigint NOT NULL DEFAULT 0,
  status     text NOT NULL DEFAULT 'processing' CHECK (status IN ('processing','ready','failed')),
  error      text,
  created_by uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX video_audio_tracks_video_idx ON video_audio_tracks(video_id);

-- --- Пропуск вступления и финальной заставки ---------------------------------------------------
ALTER TABLE videos ADD COLUMN intro_end numeric(10,2);
ALTER TABLE videos ADD COLUMN outro_start numeric(10,2);
