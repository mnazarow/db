-- CorpVideo 1.3: вебхуки, xAPI, редактор видео (обрезка, клипы, удаление пауз), перевод субтитров,
-- текст на экране (OCR), подсказки и конечные заставки, повтор чата в записи эфира, RSS/подкаст-ленты,
-- еженедельный дайджест, папка автоимпорта.

-- Видео: подсказки, конечная заставка, клипы, повтор чата, аудиодорожка, OCR
ALTER TABLE videos ADD COLUMN cards jsonb NOT NULL DEFAULT '[]'::jsonb;   -- [{id,start,title,type:'video'|'playlist'|'url',target,text}]
ALTER TABLE videos ADD COLUMN end_screen jsonb;                            -- {seconds, items:[{type:'video'|'playlist'|'subscribe'|'url',target,title}]}
ALTER TABLE videos ADD COLUMN clip_of uuid REFERENCES videos(id) ON DELETE SET NULL;  -- исходное видео клипа
ALTER TABLE videos ADD COLUMN clip_range jsonb;                            -- {start,end,vertical}
ALTER TABLE videos ADD COLUMN recording_started_at timestamptz;            -- начало записи эфира (для повтора чата)
ALTER TABLE videos ADD COLUMN audio_path text;                             -- аудиодорожка m4a (режим «только звук», подкасты)
ALTER TABLE videos ADD COLUMN screen_text text;                            -- распознанный текст с экрана (сводно, для поиска)
ALTER TABLE videos ADD COLUMN ocr_status text CHECK (ocr_status IN ('queued','processing','done','failed'));
ALTER TABLE videos ADD COLUMN ocr_at timestamptz;
ALTER TABLE videos ADD COLUMN edit_history jsonb NOT NULL DEFAULT '[]'::jsonb; -- [{at,op,params,by}]
CREATE INDEX videos_clip_of_idx ON videos(clip_of) WHERE clip_of IS NOT NULL;

-- Текст на экране по времени (результат OCR)
CREATE TABLE video_screen_text (
  id          bigserial PRIMARY KEY,
  video_id    uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  t           numeric(10,2) NOT NULL,           -- секунда кадра
  text        text NOT NULL,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX video_screen_text_video_idx ON video_screen_text(video_id, t);

-- Полнотекстовый поиск: учитываем текст с экрана
CREATE OR REPLACE FUNCTION videos_search_vector_update() RETURNS trigger AS $$
BEGIN
  NEW.search_vector :=
    setweight(to_tsvector('russian', unaccent(coalesce(NEW.title,''))), 'A') ||
    setweight(to_tsvector('russian', unaccent(array_to_string(NEW.tags, ' '))), 'B') ||
    setweight(to_tsvector('russian', unaccent(coalesce(NEW.description,''))), 'C') ||
    setweight(to_tsvector('russian', unaccent(left(coalesce(NEW.transcript,''), 200000))), 'D') ||
    setweight(to_tsvector('russian', unaccent(left(coalesce(NEW.screen_text,''), 100000))), 'D');
  NEW.updated_at := now();
  RETURN NEW;
END $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS videos_search_vector_trg ON videos;
CREATE TRIGGER videos_search_vector_trg
  BEFORE INSERT OR UPDATE OF title, description, tags, transcript, screen_text ON videos
  FOR EACH ROW EXECUTE FUNCTION videos_search_vector_update();

-- Субтитры: источник перевода
ALTER TABLE subtitles ADD COLUMN translated_from uuid REFERENCES subtitles(id) ON DELETE SET NULL;

-- Пользователи: личный токен RSS-лент
ALTER TABLE users ADD COLUMN feed_token text UNIQUE;

-- Вебхуки
CREATE TABLE webhooks (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name        text NOT NULL,
  url         text NOT NULL,
  secret      text NOT NULL DEFAULT '',
  events      text[] NOT NULL DEFAULT '{}',      -- пустой список = все события
  enabled     boolean NOT NULL DEFAULT true,
  created_by  uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at  timestamptz NOT NULL DEFAULT now(),
  last_status int,
  last_at     timestamptz,
  fail_count  int NOT NULL DEFAULT 0
);
CREATE TABLE webhook_deliveries (
  id            bigserial PRIMARY KEY,
  webhook_id    uuid NOT NULL REFERENCES webhooks(id) ON DELETE CASCADE,
  event         text NOT NULL,
  payload       jsonb NOT NULL,
  status        text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','ok','failed')),
  http_status   int,
  attempts      int NOT NULL DEFAULT 0,
  response      text,
  created_at    timestamptz NOT NULL DEFAULT now(),
  delivered_at  timestamptz
);
CREATE INDEX webhook_deliveries_hook_idx ON webhook_deliveries(webhook_id, id DESC);

-- xAPI: журнал отправленных выражений (для повторов и отчётности)
CREATE TABLE xapi_statements (
  id          bigserial PRIMARY KEY,
  user_id     uuid REFERENCES users(id) ON DELETE SET NULL,
  video_id    uuid REFERENCES videos(id) ON DELETE SET NULL,
  verb        text NOT NULL,
  statement   jsonb NOT NULL,
  status      text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','ok','failed')),
  error       text,
  created_at  timestamptz NOT NULL DEFAULT now(),
  sent_at     timestamptz
);
CREATE INDEX xapi_statements_status_idx ON xapi_statements(status) WHERE status = 'queued';

-- Папка автоимпорта: что уже импортировано
CREATE TABLE import_watch_log (
  id          bigserial PRIMARY KEY,
  filename    text NOT NULL,
  size        bigint NOT NULL,
  mtime       timestamptz,
  video_id    uuid REFERENCES videos(id) ON DELETE SET NULL,
  status      text NOT NULL DEFAULT 'imported' CHECK (status IN ('imported','skipped','failed')),
  error       text,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX import_watch_log_file_idx ON import_watch_log(filename, size);

-- Дайджест: когда последний раз отправлен
ALTER TABLE users ADD COLUMN digest_sent_at timestamptz;
