-- 1.7: итоги встречи, автоклипы, тренажёры с ветвлением, офлайн-просмотр

-- Итоги встречи: структурированный конспект записи (как Otter, Fireflies, Copilot в Teams)
CREATE TABLE meeting_notes (
  video_id    uuid PRIMARY KEY REFERENCES videos(id) ON DELETE CASCADE,
  status      text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','processing','ready','failed')),
  summary     text NOT NULL DEFAULT '',
  topics      jsonb NOT NULL DEFAULT '[]',   -- [{at, title, text}]
  decisions   jsonb NOT NULL DEFAULT '[]',   -- [{at, text}]
  tasks       jsonb NOT NULL DEFAULT '[]',   -- [{at, text, who, due}]
  questions   jsonb NOT NULL DEFAULT '[]',   -- [{at, text}] — открытые вопросы
  model       text,
  error       text,
  created_by  uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at  timestamptz NOT NULL DEFAULT now(),
  updated_at  timestamptz NOT NULL DEFAULT now()
);

-- Предложения клипов: ИИ выбирает самые ценные фрагменты длинной записи (как OpusClip, Vizard)
CREATE TABLE clip_suggestions (
  id          bigserial PRIMARY KEY,
  video_id    uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  start_sec   double precision NOT NULL,
  end_sec     double precision NOT NULL,
  title       text NOT NULL DEFAULT '',
  reason      text NOT NULL DEFAULT '',
  score       smallint NOT NULL DEFAULT 0,      -- насколько фрагмент самостоятелен, 0…100
  created_video_id uuid REFERENCES videos(id) ON DELETE SET NULL,
  dismissed   boolean NOT NULL DEFAULT false,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX clip_suggestions_video_idx ON clip_suggestions(video_id, start_sec);
ALTER TABLE videos ADD COLUMN clips_suggested_at timestamptz;
ALTER TABLE videos ADD COLUMN has_scenario boolean NOT NULL DEFAULT false;
ALTER TABLE videos ADD COLUMN has_notes boolean NOT NULL DEFAULT false;

-- Тренажёр с ветвлением: в точке видео зритель выбирает действие и переходит к нужному моменту
-- (как Branching Scenario у H5P, сценарии Mindstamp и Kaltura Rapt)
CREATE TABLE video_scenarios (
  video_id    uuid PRIMARY KEY REFERENCES videos(id) ON DELETE CASCADE,
  title       text NOT NULL DEFAULT 'Тренажёр',
  enabled     boolean NOT NULL DEFAULT true,
  show_result boolean NOT NULL DEFAULT true,   -- показывать итог после прохождения
  points      jsonb NOT NULL DEFAULT '[]',
  -- [{id, at, text, options:[{id, text, goto, feedback, correct, ending}]}]
  updated_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE scenario_choices (
  id          bigserial PRIMARY KEY,
  video_id    uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  user_id     uuid REFERENCES users(id) ON DELETE SET NULL,
  point_id    text NOT NULL,
  option_id   text NOT NULL,
  correct     boolean,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX scenario_choices_video_idx ON scenario_choices(video_id, created_at DESC);
