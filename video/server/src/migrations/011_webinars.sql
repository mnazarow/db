-- 1.10: полноценный раздел «Вебинары» и графический видеоредактор (проекты монтажа)

-- ---------- Вебинары ----------
ALTER TABLE live_streams
  ADD COLUMN webinar         boolean NOT NULL DEFAULT false,   -- показывать в разделе «Вебинары»
  ADD COLUMN slug            text UNIQUE,                      -- адрес лендинга /webinar/<slug>
  ADD COLUMN cover_path      text,
  ADD COLUMN agenda          jsonb NOT NULL DEFAULT '[]'::jsonb,   -- программа: [{time,title,note}]
  ADD COLUMN speakers        jsonb NOT NULL DEFAULT '[]'::jsonb,   -- спикеры: [{name,role,bio,photo,userId}]
  ADD COLUMN reg_fields      jsonb NOT NULL DEFAULT '[]'::jsonb,   -- анкета: [{id,label,type,required,options}]
  ADD COLUMN reg_moderation  boolean NOT NULL DEFAULT false,   -- заявки подтверждает организатор
  ADD COLUMN reg_waitlist    boolean NOT NULL DEFAULT true,    -- лист ожидания при нехватке мест
  ADD COLUMN reg_external    boolean NOT NULL DEFAULT false,   -- пускать внешних участников по почте
  ADD COLUMN reg_closes_at   timestamptz,                      -- когда закрывается регистрация
  ADD COLUMN cert_enabled    boolean NOT NULL DEFAULT false,   -- сертификат участника
  ADD COLUMN cert_min_percent integer NOT NULL DEFAULT 60,     -- сколько нужно пробыть, % от длительности
  ADD COLUMN cohosts         uuid[] NOT NULL DEFAULT '{}',     -- соведущие
  ADD COLUMN source_video_id uuid REFERENCES videos(id) ON DELETE SET NULL,  -- автовебинар из записи
  ADD COLUMN reminders_sent  jsonb NOT NULL DEFAULT '{}'::jsonb,
  ADD COLUMN followup_sent_at timestamptz,
  ADD COLUMN cta             jsonb;                            -- призыв к действию: {text,url,label}

CREATE INDEX live_streams_webinar_idx ON live_streams(scheduled_at DESC) WHERE webinar;

ALTER TABLE live_registrations
  ADD COLUMN status     text NOT NULL DEFAULT 'approved'
             CHECK (status IN ('pending','approved','waitlist','declined','cancelled')),
  ADD COLUMN token      text UNIQUE,                        -- персональная ссылка входа (внешние участники)
  ADD COLUMN answers    jsonb NOT NULL DEFAULT '{}'::jsonb, -- ответы анкеты
  ADD COLUMN company    text,
  ADD COLUMN seconds    integer NOT NULL DEFAULT 0,         -- присутствие внешнего участника
  ADD COLUMN first_at   timestamptz,
  ADD COLUMN last_at    timestamptz,
  ADD COLUMN reminded   jsonb NOT NULL DEFAULT '{}'::jsonb,
  ADD COLUMN decided_at timestamptz,
  ADD COLUMN cert_id    uuid REFERENCES certificates(id) ON DELETE SET NULL;

CREATE INDEX live_registrations_status_idx ON live_registrations(stream_id, status);

-- Поминутная кривая присутствия: сколько человек было в эфире на каждой минуте
CREATE TABLE webinar_presence (
  stream_id uuid NOT NULL REFERENCES live_streams(id) ON DELETE CASCADE,
  minute    integer NOT NULL,
  people    integer NOT NULL DEFAULT 0,
  PRIMARY KEY (stream_id, minute)
);

-- Материалы вебинара: раздаются до эфира на лендинге и/или после него в письме
CREATE TABLE webinar_materials (
  id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  stream_id  uuid NOT NULL REFERENCES live_streams(id) ON DELETE CASCADE,
  name       text NOT NULL,
  path       text NOT NULL,
  bytes      bigint NOT NULL DEFAULT 0,
  before     boolean NOT NULL DEFAULT true,   -- доступен до эфира
  position   integer NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX webinar_materials_stream_idx ON webinar_materials(stream_id, position);

-- ---------- Графический видеоредактор ----------
-- Проект монтажа хранится целиком в JSON: дорожки, куски, титры, картинки, музыка.
CREATE TABLE video_projects (
  video_id   uuid PRIMARY KEY REFERENCES videos(id) ON DELETE CASCADE,
  data       jsonb NOT NULL DEFAULT '{}'::jsonb,
  updated_by uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  rendered_at timestamptz
);

-- Загруженные в проект картинки и музыка
CREATE TABLE project_assets (
  id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  video_id   uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  kind       text NOT NULL CHECK (kind IN ('image','audio')),
  name       text NOT NULL,
  path       text NOT NULL,
  bytes      bigint NOT NULL DEFAULT 0,
  duration   numeric(10,3),
  width      integer,
  height     integer,
  created_by uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX project_assets_video_idx ON project_assets(video_id, created_at);
