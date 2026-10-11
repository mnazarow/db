-- CorpVideo: начальная схема базы данных (PostgreSQL 14+)
CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;

-- ---------------------------------------------------------------------------
-- Настройки (ключ → JSON)
-- ---------------------------------------------------------------------------
CREATE TABLE settings (
  key         text PRIMARY KEY,
  value       jsonb NOT NULL,
  updated_at  timestamptz NOT NULL DEFAULT now(),
  updated_by  uuid
);

-- ---------------------------------------------------------------------------
-- Пользователи
-- ---------------------------------------------------------------------------
CREATE TABLE users (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  email             citext NOT NULL UNIQUE,
  handle            citext NOT NULL UNIQUE,           -- @имя канала
  display_name      text NOT NULL,
  password_hash     text,                             -- NULL для LDAP-учёток
  role              text NOT NULL DEFAULT 'user' CHECK (role IN ('user','moderator','admin')),
  status            text NOT NULL DEFAULT 'pending_email'
                    CHECK (status IN ('pending_email','pending_approval','active','blocked','rejected')),
  auth_provider     text NOT NULL DEFAULT 'local' CHECK (auth_provider IN ('local','ldap')),
  ldap_dn           text,
  email_verified_at timestamptz,
  approved_at       timestamptz,
  approved_by       uuid REFERENCES users(id) ON DELETE SET NULL,
  rejection_note    text,
  can_upload        boolean NOT NULL DEFAULT true,
  can_stream        boolean NOT NULL DEFAULT true,
  avatar_path       text,
  banner_path       text,
  bio               text NOT NULL DEFAULT '',
  links             jsonb NOT NULL DEFAULT '[]',      -- [{title,url}]
  prefs             jsonb NOT NULL DEFAULT '{}',      -- настройки интерфейса/уведомлений
  totp_secret       text,
  totp_enabled      boolean NOT NULL DEFAULT false,
  failed_logins     int NOT NULL DEFAULT 0,
  locked_until      timestamptz,
  last_login_at     timestamptz,
  last_seen_at      timestamptz,
  subscriber_count  int NOT NULL DEFAULT 0,
  video_count       int NOT NULL DEFAULT 0,
  total_views       bigint NOT NULL DEFAULT 0,
  created_at        timestamptz NOT NULL DEFAULT now(),
  updated_at        timestamptz NOT NULL DEFAULT now(),
  deleted_at        timestamptz
);
CREATE INDEX users_status_idx ON users(status);
CREATE INDEX users_role_idx ON users(role);
CREATE INDEX users_name_trgm_idx ON users USING gin (display_name gin_trgm_ops);
CREATE INDEX users_handle_trgm_idx ON users USING gin ((handle::text) gin_trgm_ops);

-- Разрешённые домены электронной почты для регистрации
CREATE TABLE allowed_domains (
  id            serial PRIMARY KEY,
  domain        citext NOT NULL UNIQUE,
  auto_approve  boolean NOT NULL DEFAULT false,    -- одобрять автоматически после подтверждения e-mail
  note          text NOT NULL DEFAULT '',
  created_by    uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at    timestamptz NOT NULL DEFAULT now()
);

-- Сессии
CREATE TABLE sessions (
  id            text PRIMARY KEY,
  user_id       uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created_at    timestamptz NOT NULL DEFAULT now(),
  expires_at    timestamptz NOT NULL,
  last_seen_at  timestamptz NOT NULL DEFAULT now(),
  ip            text,
  user_agent    text,
  totp_pending  boolean NOT NULL DEFAULT false
);
CREATE INDEX sessions_user_idx ON sessions(user_id);
CREATE INDEX sessions_expires_idx ON sessions(expires_at);

-- Одноразовые токены (подтверждение e-mail, сброс пароля, приглашения)
CREATE TABLE tokens (
  token       text PRIMARY KEY,
  type        text NOT NULL CHECK (type IN ('verify_email','reset_password','invite','change_email')),
  user_id     uuid REFERENCES users(id) ON DELETE CASCADE,
  email       citext,
  payload     jsonb NOT NULL DEFAULT '{}',
  expires_at  timestamptz NOT NULL,
  used_at     timestamptz,
  created_by  uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX tokens_user_idx ON tokens(user_id);
CREATE INDEX tokens_type_email_idx ON tokens(type, email);

-- Токены API (интеграции, скрипты)
CREATE TABLE api_tokens (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id       uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name          text NOT NULL,
  token_hash    text NOT NULL UNIQUE,
  token_prefix  text NOT NULL,
  scopes        text[] NOT NULL DEFAULT '{}',
  last_used_at  timestamptz,
  expires_at    timestamptz,
  created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX api_tokens_user_idx ON api_tokens(user_id);

-- ---------------------------------------------------------------------------
-- Категории
-- ---------------------------------------------------------------------------
CREATE TABLE categories (
  id          serial PRIMARY KEY,
  slug        citext NOT NULL UNIQUE,
  name        text NOT NULL,
  description text NOT NULL DEFAULT '',
  icon        text NOT NULL DEFAULT 'folder',
  sort_order  int NOT NULL DEFAULT 0,
  video_count int NOT NULL DEFAULT 0,
  is_active   boolean NOT NULL DEFAULT true,
  created_at  timestamptz NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- Видео
-- ---------------------------------------------------------------------------
CREATE TABLE videos (
  id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  short_id            text NOT NULL UNIQUE,            -- короткий id для ссылок (11 символов)
  owner_id            uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  title               text NOT NULL,
  description         text NOT NULL DEFAULT '',
  category_id         int REFERENCES categories(id) ON DELETE SET NULL,
  tags                text[] NOT NULL DEFAULT '{}',
  language            text NOT NULL DEFAULT 'ru',
  visibility          text NOT NULL DEFAULT 'internal'
                      CHECK (visibility IN ('public','internal','unlisted','private')),
  status              text NOT NULL DEFAULT 'uploading'
                      CHECK (status IN ('uploading','queued','processing','ready','failed')),
  moderation_status   text NOT NULL DEFAULT 'approved'
                      CHECK (moderation_status IN ('pending','approved','rejected')),
  moderation_note     text,
  moderated_by        uuid REFERENCES users(id) ON DELETE SET NULL,
  moderated_at        timestamptz,
  is_blocked          boolean NOT NULL DEFAULT false,  -- заблокировано администратором
  block_reason        text,
  duration            numeric(10,3) NOT NULL DEFAULT 0, -- секунды
  width               int,
  height              int,
  fps                 numeric(7,3),
  bitrate             int,
  codec               text,
  original_filename   text,
  original_size       bigint NOT NULL DEFAULT 0,
  original_path       text,
  original_kept       boolean NOT NULL DEFAULT true,
  hls_path            text,                            -- относительный путь к master.m3u8
  mp4_path            text,                            -- прогрессивный mp4 (фолбэк/скачивание)
  thumbnail_path      text,
  thumbnail_candidates jsonb NOT NULL DEFAULT '[]',
  storyboard_path     text,                            -- раскадровка (VTT)
  storyboard_meta     jsonb,
  chapters            jsonb NOT NULL DEFAULT '[]',     -- [{start,title}]
  storage_bytes       bigint NOT NULL DEFAULT 0,
  processing_progress smallint NOT NULL DEFAULT 0,
  processing_error    text,
  processing_stage    text,
  comments_mode       text NOT NULL DEFAULT 'open' CHECK (comments_mode IN ('open','held','disabled')),
  allow_download      boolean NOT NULL DEFAULT true,
  allow_embed         boolean NOT NULL DEFAULT true,
  allow_ratings       boolean NOT NULL DEFAULT true,
  is_short            boolean NOT NULL DEFAULT false,  -- вертикальное короткое видео
  is_live_recording   boolean NOT NULL DEFAULT false,
  live_stream_id      uuid,
  view_count          bigint NOT NULL DEFAULT 0,
  like_count          int NOT NULL DEFAULT 0,
  dislike_count       int NOT NULL DEFAULT 0,
  comment_count       int NOT NULL DEFAULT 0,
  watch_seconds       bigint NOT NULL DEFAULT 0,
  transcript          text,                            -- текст автосубтитров (для поиска)
  search_vector       tsvector,
  published_at        timestamptz,                     -- момент публикации (после обработки)
  scheduled_at        timestamptz,                     -- отложенная публикация
  created_at          timestamptz NOT NULL DEFAULT now(),
  updated_at          timestamptz NOT NULL DEFAULT now(),
  deleted_at          timestamptz
);
CREATE INDEX videos_owner_idx ON videos(owner_id);
CREATE INDEX videos_visibility_idx ON videos(visibility) WHERE deleted_at IS NULL;
CREATE INDEX videos_status_idx ON videos(status);
CREATE INDEX videos_moderation_idx ON videos(moderation_status) WHERE deleted_at IS NULL;
CREATE INDEX videos_published_idx ON videos(published_at DESC) WHERE deleted_at IS NULL;
CREATE INDEX videos_views_idx ON videos(view_count DESC) WHERE deleted_at IS NULL;
CREATE INDEX videos_category_idx ON videos(category_id);
CREATE INDEX videos_tags_idx ON videos USING gin(tags);
CREATE INDEX videos_search_idx ON videos USING gin(search_vector);
CREATE INDEX videos_title_trgm_idx ON videos USING gin(title gin_trgm_ops);
CREATE INDEX videos_scheduled_idx ON videos(scheduled_at) WHERE scheduled_at IS NOT NULL;

CREATE OR REPLACE FUNCTION videos_search_vector_update() RETURNS trigger AS $$
BEGIN
  NEW.search_vector :=
    setweight(to_tsvector('russian', unaccent(coalesce(NEW.title,''))), 'A') ||
    setweight(to_tsvector('russian', unaccent(array_to_string(NEW.tags, ' '))), 'B') ||
    setweight(to_tsvector('russian', unaccent(coalesce(NEW.description,''))), 'C') ||
    setweight(to_tsvector('russian', unaccent(left(coalesce(NEW.transcript,''), 200000))), 'D');
  NEW.updated_at := now();
  RETURN NEW;
END $$ LANGUAGE plpgsql;

CREATE TRIGGER videos_search_vector_trg
  BEFORE INSERT OR UPDATE OF title, description, tags, transcript ON videos
  FOR EACH ROW EXECUTE FUNCTION videos_search_vector_update();

-- Доступ к приватным видео для конкретных пользователей
CREATE TABLE video_access (
  video_id    uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  granted_by  uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at  timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (video_id, user_id)
);

-- Варианты качества (HLS-лестница)
CREATE TABLE video_renditions (
  id          serial PRIMARY KEY,
  video_id    uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  label       text NOT NULL,        -- 1080p, 720p …
  width       int NOT NULL,
  height      int NOT NULL,
  bandwidth   int NOT NULL,         -- бит/с
  path        text NOT NULL,        -- относительный путь к index.m3u8
  bytes       bigint NOT NULL DEFAULT 0,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX video_renditions_video_idx ON video_renditions(video_id);

-- Субтитры
CREATE TABLE subtitles (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  video_id    uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  language    text NOT NULL DEFAULT 'ru',
  label       text NOT NULL,
  kind        text NOT NULL DEFAULT 'manual' CHECK (kind IN ('manual','auto')),
  status      text NOT NULL DEFAULT 'ready' CHECK (status IN ('processing','ready','failed')),
  path        text,
  error       text,
  is_default  boolean NOT NULL DEFAULT false,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX subtitles_video_idx ON subtitles(video_id);

-- Загрузки (возобновляемые, по частям)
CREATE TABLE uploads (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id         uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  video_id        uuid REFERENCES videos(id) ON DELETE SET NULL,
  filename        text NOT NULL,
  size            bigint NOT NULL,
  mime            text,
  received_bytes  bigint NOT NULL DEFAULT 0,
  tmp_path        text NOT NULL,
  status          text NOT NULL DEFAULT 'active' CHECK (status IN ('active','completed','aborted','expired')),
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX uploads_user_idx ON uploads(user_id);

-- ---------------------------------------------------------------------------
-- Просмотры, история, аналитика
-- ---------------------------------------------------------------------------
CREATE TABLE video_views (
  id              bigserial PRIMARY KEY,
  video_id        uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  user_id         uuid REFERENCES users(id) ON DELETE CASCADE,
  session_key     text NOT NULL,           -- id сессии или cookie гостя
  source          text NOT NULL DEFAULT 'direct',
  device          text NOT NULL DEFAULT 'desktop',
  watched_seconds numeric(10,2) NOT NULL DEFAULT 0,
  position        numeric(10,2) NOT NULL DEFAULT 0,
  max_position    numeric(10,2) NOT NULL DEFAULT 0,
  counted         boolean NOT NULL DEFAULT false, -- засчитан ли просмотр
  completed       boolean NOT NULL DEFAULT false,
  day             date NOT NULL DEFAULT current_date,
  first_at        timestamptz NOT NULL DEFAULT now(),
  last_at         timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX video_views_video_idx ON video_views(video_id, last_at DESC);
CREATE INDEX video_views_user_idx ON video_views(user_id, last_at DESC) WHERE user_id IS NOT NULL;
CREATE UNIQUE INDEX video_views_session_day_idx ON video_views(video_id, session_key, day);

-- Удержание аудитории: 100 корзин по длительности видео
CREATE TABLE video_retention (
  video_id  uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  bucket    smallint NOT NULL CHECK (bucket BETWEEN 0 AND 99),
  count     int NOT NULL DEFAULT 0,
  PRIMARY KEY (video_id, bucket)
);

-- Суточная статистика по видео
CREATE TABLE video_stats_daily (
  video_id        uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  day             date NOT NULL,
  views           int NOT NULL DEFAULT 0,
  watch_seconds   bigint NOT NULL DEFAULT 0,
  likes           int NOT NULL DEFAULT 0,
  dislikes        int NOT NULL DEFAULT 0,
  comments        int NOT NULL DEFAULT 0,
  sources         jsonb NOT NULL DEFAULT '{}',
  devices         jsonb NOT NULL DEFAULT '{}',
  PRIMARY KEY (video_id, day)
);
CREATE INDEX video_stats_daily_day_idx ON video_stats_daily(day);

-- Суточная статистика по каналу (подписки)
CREATE TABLE channel_stats_daily (
  user_id       uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  day           date NOT NULL,
  subscribers_gained int NOT NULL DEFAULT 0,
  subscribers_lost   int NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, day)
);

-- ---------------------------------------------------------------------------
-- Реакции и комментарии
-- ---------------------------------------------------------------------------
CREATE TABLE video_likes (
  video_id    uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  value       smallint NOT NULL CHECK (value IN (1,-1)),
  created_at  timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (video_id, user_id)
);
CREATE INDEX video_likes_user_idx ON video_likes(user_id, created_at DESC) WHERE value = 1;

CREATE TABLE comments (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  video_id      uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  user_id       uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  parent_id     uuid REFERENCES comments(id) ON DELETE CASCADE,
  root_id       uuid REFERENCES comments(id) ON DELETE CASCADE,
  body          text NOT NULL,
  status        text NOT NULL DEFAULT 'visible' CHECK (status IN ('visible','held','hidden','deleted')),
  is_pinned     boolean NOT NULL DEFAULT false,
  is_hearted    boolean NOT NULL DEFAULT false,   -- «сердечко» от автора видео
  like_count    int NOT NULL DEFAULT 0,
  dislike_count int NOT NULL DEFAULT 0,
  reply_count   int NOT NULL DEFAULT 0,
  edited_at     timestamptz,
  created_at    timestamptz NOT NULL DEFAULT now(),
  updated_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX comments_video_idx ON comments(video_id, created_at DESC);
CREATE INDEX comments_root_idx ON comments(root_id, created_at);
CREATE INDEX comments_user_idx ON comments(user_id, created_at DESC);
CREATE INDEX comments_status_idx ON comments(status) WHERE status = 'held';

CREATE TABLE comment_likes (
  comment_id  uuid NOT NULL REFERENCES comments(id) ON DELETE CASCADE,
  user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  value       smallint NOT NULL CHECK (value IN (1,-1)),
  created_at  timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (comment_id, user_id)
);

-- ---------------------------------------------------------------------------
-- Плейлисты
-- ---------------------------------------------------------------------------
CREATE TABLE playlists (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  owner_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  title        text NOT NULL,
  description  text NOT NULL DEFAULT '',
  visibility   text NOT NULL DEFAULT 'private' CHECK (visibility IN ('public','internal','unlisted','private')),
  kind         text NOT NULL DEFAULT 'normal' CHECK (kind IN ('normal','watch_later')),
  item_count   int NOT NULL DEFAULT 0,
  created_at   timestamptz NOT NULL DEFAULT now(),
  updated_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX playlists_owner_idx ON playlists(owner_id);
CREATE UNIQUE INDEX playlists_watch_later_idx ON playlists(owner_id) WHERE kind = 'watch_later';

CREATE TABLE playlist_items (
  playlist_id  uuid NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
  video_id     uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  position     int NOT NULL DEFAULT 0,
  added_by     uuid REFERENCES users(id) ON DELETE SET NULL,
  added_at     timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (playlist_id, video_id)
);
CREATE INDEX playlist_items_pos_idx ON playlist_items(playlist_id, position);

-- ---------------------------------------------------------------------------
-- Подписки и уведомления
-- ---------------------------------------------------------------------------
CREATE TABLE subscriptions (
  subscriber_id  uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  channel_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  notify         text NOT NULL DEFAULT 'all' CHECK (notify IN ('all','none')),
  created_at     timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (subscriber_id, channel_id),
  CHECK (subscriber_id <> channel_id)
);
CREATE INDEX subscriptions_channel_idx ON subscriptions(channel_id);

CREATE TABLE notifications (
  id          bigserial PRIMARY KEY,
  user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  type        text NOT NULL,
  title       text NOT NULL,
  body        text NOT NULL DEFAULT '',
  link        text,
  image       text,
  actor_id    uuid REFERENCES users(id) ON DELETE SET NULL,
  data        jsonb NOT NULL DEFAULT '{}',
  read_at     timestamptz,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX notifications_user_idx ON notifications(user_id, created_at DESC);
CREATE INDEX notifications_unread_idx ON notifications(user_id) WHERE read_at IS NULL;

-- ---------------------------------------------------------------------------
-- Жалобы и модерация
-- ---------------------------------------------------------------------------
CREATE TABLE reports (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  reporter_id   uuid REFERENCES users(id) ON DELETE SET NULL,
  target_type   text NOT NULL CHECK (target_type IN ('video','comment','user','live')),
  target_id     uuid NOT NULL,
  reason        text NOT NULL,
  details       text NOT NULL DEFAULT '',
  status        text NOT NULL DEFAULT 'open' CHECK (status IN ('open','resolved','dismissed')),
  resolved_by   uuid REFERENCES users(id) ON DELETE SET NULL,
  resolved_at   timestamptz,
  resolution    text,
  created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX reports_status_idx ON reports(status, created_at DESC);
CREATE INDEX reports_target_idx ON reports(target_type, target_id);

CREATE TABLE audit_log (
  id          bigserial PRIMARY KEY,
  actor_id    uuid REFERENCES users(id) ON DELETE SET NULL,
  action      text NOT NULL,
  target_type text,
  target_id   text,
  details     jsonb NOT NULL DEFAULT '{}',
  ip          text,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX audit_log_created_idx ON audit_log(created_at DESC);
CREATE INDEX audit_log_actor_idx ON audit_log(actor_id);
CREATE INDEX audit_log_target_idx ON audit_log(target_type, target_id);

-- ---------------------------------------------------------------------------
-- Очередь фоновых заданий
-- ---------------------------------------------------------------------------
CREATE TABLE jobs (
  id            bigserial PRIMARY KEY,
  type          text NOT NULL,
  payload       jsonb NOT NULL DEFAULT '{}',
  status        text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','running','done','failed','cancelled')),
  priority      int NOT NULL DEFAULT 0,
  attempts      int NOT NULL DEFAULT 0,
  max_attempts  int NOT NULL DEFAULT 3,
  run_at        timestamptz NOT NULL DEFAULT now(),
  locked_at     timestamptz,
  locked_by     text,
  heartbeat_at  timestamptz,
  progress      smallint NOT NULL DEFAULT 0,
  stage         text,
  error         text,
  result        jsonb,
  video_id      uuid REFERENCES videos(id) ON DELETE CASCADE,
  created_at    timestamptz NOT NULL DEFAULT now(),
  started_at    timestamptz,
  finished_at   timestamptz
);
CREATE INDEX jobs_queue_idx ON jobs(status, priority DESC, run_at) WHERE status IN ('queued','running');
CREATE INDEX jobs_video_idx ON jobs(video_id);
CREATE INDEX jobs_type_idx ON jobs(type, created_at DESC);

-- ---------------------------------------------------------------------------
-- Прямые трансляции
-- ---------------------------------------------------------------------------
CREATE TABLE live_streams (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  short_id        text NOT NULL UNIQUE,
  owner_id        uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  title           text NOT NULL,
  description     text NOT NULL DEFAULT '',
  category_id     int REFERENCES categories(id) ON DELETE SET NULL,
  visibility      text NOT NULL DEFAULT 'internal' CHECK (visibility IN ('public','internal','unlisted','private')),
  stream_key      text NOT NULL UNIQUE,
  status          text NOT NULL DEFAULT 'idle' CHECK (status IN ('idle','live','ended')),
  chat_enabled    boolean NOT NULL DEFAULT true,
  record          boolean NOT NULL DEFAULT true,
  recording_video_id uuid REFERENCES videos(id) ON DELETE SET NULL,
  thumbnail_path  text,
  scheduled_at    timestamptz,
  started_at      timestamptz,
  ended_at        timestamptz,
  viewer_count    int NOT NULL DEFAULT 0,
  viewer_peak     int NOT NULL DEFAULT 0,
  total_views     int NOT NULL DEFAULT 0,
  source_protocol text,
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX live_streams_owner_idx ON live_streams(owner_id);
CREATE INDEX live_streams_status_idx ON live_streams(status);

CREATE TABLE live_chat_messages (
  id          bigserial PRIMARY KEY,
  stream_id   uuid NOT NULL REFERENCES live_streams(id) ON DELETE CASCADE,
  user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  body        text NOT NULL,
  is_deleted  boolean NOT NULL DEFAULT false,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX live_chat_stream_idx ON live_chat_messages(stream_id, id DESC);

CREATE TABLE live_bans (
  stream_id   uuid NOT NULL REFERENCES live_streams(id) ON DELETE CASCADE,
  user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created_at  timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (stream_id, user_id)
);

-- ---------------------------------------------------------------------------
-- История поиска
-- ---------------------------------------------------------------------------
CREATE TABLE search_history (
  id          bigserial PRIMARY KEY,
  user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  query       text NOT NULL,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX search_history_user_idx ON search_history(user_id, created_at DESC);

-- ---------------------------------------------------------------------------
-- Категории по умолчанию
-- ---------------------------------------------------------------------------
INSERT INTO categories (slug, name, icon, sort_order) VALUES
  ('training', 'Обучение', 'school', 10),
  ('products', 'Продукция и оборудование', 'inventory', 20),
  ('events', 'Мероприятия', 'event', 30),
  ('news', 'Новости компании', 'campaign', 40),
  ('instructions', 'Инструкции', 'menu_book', 50),
  ('meetings', 'Совещания и записи эфиров', 'videocam', 60),
  ('marketing', 'Маркетинг', 'star', 70),
  ('other', 'Прочее', 'folder', 100);
