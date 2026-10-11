-- 1.9: размытие в кадре, монтаж по расшифровке, ретрансляция и перемотка эфира, витрины, сроки хранения

-- Витрины: брендированные страницы-подборки (как Wistia Channels и Brightcove Gallery)
CREATE TABLE showcases (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  slug        text NOT NULL UNIQUE,
  title       text NOT NULL,
  subtitle    text NOT NULL DEFAULT '',
  description text NOT NULL DEFAULT '',
  cover_path  text,
  accent      text,
  visibility  text NOT NULL DEFAULT 'internal' CHECK (visibility IN ('public','internal','unlisted')),
  in_menu     boolean NOT NULL DEFAULT false,
  position    integer NOT NULL DEFAULT 0,
  enabled     boolean NOT NULL DEFAULT true,
  view_count  bigint NOT NULL DEFAULT 0,
  created_by  uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at  timestamptz NOT NULL DEFAULT now(),
  updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX showcases_menu_idx ON showcases(position) WHERE enabled AND in_menu;

CREATE TABLE showcase_sections (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  showcase_id uuid NOT NULL REFERENCES showcases(id) ON DELETE CASCADE,
  title       text NOT NULL DEFAULT '',
  note        text NOT NULL DEFAULT '',
  kind        text NOT NULL DEFAULT 'videos' CHECK (kind IN ('videos','playlist','category','tag','latest')),
  playlist_id uuid REFERENCES playlists(id) ON DELETE SET NULL,
  category_id integer REFERENCES categories(id) ON DELETE SET NULL,
  tag         text,
  max_items   integer NOT NULL DEFAULT 12,
  layout      text NOT NULL DEFAULT 'grid' CHECK (layout IN ('grid','row','hero')),
  position    integer NOT NULL DEFAULT 0
);
CREATE INDEX showcase_sections_showcase_idx ON showcase_sections(showcase_id, position);

CREATE TABLE showcase_items (
  section_id uuid NOT NULL REFERENCES showcase_sections(id) ON DELETE CASCADE,
  video_id   uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  position   integer NOT NULL DEFAULT 0,
  PRIMARY KEY (section_id, video_id)
);

-- Ретрансляция эфира на внешние площадки (мультистриминг как в Restream)
CREATE TABLE live_restreams (
  id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  stream_id  uuid NOT NULL REFERENCES live_streams(id) ON DELETE CASCADE,
  name       text NOT NULL,
  url        text NOT NULL,
  stream_key text NOT NULL DEFAULT '',
  enabled    boolean NOT NULL DEFAULT true,
  status     text NOT NULL DEFAULT 'idle' CHECK (status IN ('idle','starting','live','error','stopped')),
  last_error text,
  started_at timestamptz,
  stopped_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX live_restreams_stream_idx ON live_restreams(stream_id);

ALTER TABLE live_streams ADD COLUMN dvr boolean NOT NULL DEFAULT true;

-- Правила хранения (retention) и журнал их применения
CREATE TABLE retention_rules (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name        text NOT NULL,
  scope       text NOT NULL DEFAULT 'all' CHECK (scope IN ('all','category','visibility','tag','live')),
  category_id integer REFERENCES categories(id) ON DELETE CASCADE,
  visibility  text,
  tag         text,
  after_days  integer NOT NULL CHECK (after_days > 0),
  action      text NOT NULL DEFAULT 'archive' CHECK (action IN ('archive','delete','notify')),
  warn_days   integer NOT NULL DEFAULT 7,
  enabled     boolean NOT NULL DEFAULT true,
  applied_at  timestamptz,
  applied_count integer NOT NULL DEFAULT 0,
  created_by  uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at  timestamptz NOT NULL DEFAULT now(),
  updated_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE retention_log (
  id       bigserial PRIMARY KEY,
  video_id uuid,
  rule_id  uuid,
  title    text NOT NULL DEFAULT '',
  owner_id uuid,
  action   text NOT NULL,
  at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX retention_log_at_idx ON retention_log(at DESC);

ALTER TABLE videos
  ADD COLUMN archived_at        timestamptz,
  ADD COLUMN archived_reason    text,
  ADD COLUMN legal_hold         boolean NOT NULL DEFAULT false,
  ADD COLUMN retention_warned_at timestamptz,
  ADD COLUMN fresh_until        date,               -- «актуально до»
  ADD COLUMN fresh_asked_at     timestamptz,
  ADD COLUMN fresh_confirmed_at timestamptz,
  ADD COLUMN blur_regions       jsonb NOT NULL DEFAULT '[]'::jsonb;

CREATE INDEX videos_archived_idx ON videos(archived_at) WHERE archived_at IS NOT NULL;
CREATE INDEX videos_fresh_idx ON videos(fresh_until) WHERE fresh_until IS NOT NULL AND deleted_at IS NULL;
