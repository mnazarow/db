-- 1.6: премьеры видео, совместный просмотр, живые субтитры эфира, экраны-витрины

-- Премьера: назначенный показ загруженного видео с обратным отсчётом и чатом (как на YouTube/VK Видео).
-- Время премьеры хранится в уже существующем scheduled_at, флаг premiere отличает её от обычной
-- отложенной публикации: анонс премьеры виден заранее, а воспроизведение открывается в назначенный час.
ALTER TABLE videos
  ADD COLUMN premiere        boolean NOT NULL DEFAULT false,
  ADD COLUMN premiere_chat   boolean NOT NULL DEFAULT true,
  ADD COLUMN premiere_started_at timestamptz,
  ADD COLUMN premiere_notified_at timestamptz;
CREATE INDEX videos_premiere_idx ON videos(scheduled_at) WHERE premiere = true;

-- Сообщения комнат: premiere:<videoId> и party:<partyId>
CREATE TABLE room_messages (
  id         bigserial PRIMARY KEY,
  room       text NOT NULL,
  user_id    uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  body       text NOT NULL,
  is_deleted boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX room_messages_room_idx ON room_messages(room, id DESC);

-- Совместный просмотр: комната с синхронной позицией (Teleparty, Plex Watch Together)
CREATE TABLE watch_parties (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  code         text NOT NULL UNIQUE,
  video_id     uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  host_id      uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  title        text NOT NULL DEFAULT '',
  position     double precision NOT NULL DEFAULT 0,   -- секунда, на которой комната
  playing      boolean NOT NULL DEFAULT false,
  rate         double precision NOT NULL DEFAULT 1,
  everyone_controls boolean NOT NULL DEFAULT false,   -- управлять может любой участник
  state_at     timestamptz NOT NULL DEFAULT now(),    -- когда зафиксирована позиция
  ended_at     timestamptz,
  created_at   timestamptz NOT NULL DEFAULT now(),
  updated_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX watch_parties_video_idx ON watch_parties(video_id);

CREATE TABLE watch_party_members (
  party_id   uuid NOT NULL REFERENCES watch_parties(id) ON DELETE CASCADE,
  user_id    uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  joined_at  timestamptz NOT NULL DEFAULT now(),
  last_seen  timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (party_id, user_id)
);

-- Живые субтитры эфира: реплики, распознанные по ходу трансляции
ALTER TABLE live_streams ADD COLUMN captions boolean NOT NULL DEFAULT false;
CREATE TABLE live_captions (
  id          bigserial PRIMARY KEY,
  stream_id   uuid NOT NULL REFERENCES live_streams(id) ON DELETE CASCADE,
  seq         int NOT NULL,
  offset_sec  double precision NOT NULL DEFAULT 0,  -- секунда от начала эфира
  text        text NOT NULL,
  created_at  timestamptz NOT NULL DEFAULT now(),
  UNIQUE (stream_id, seq)
);
CREATE INDEX live_captions_stream_idx ON live_captions(stream_id, seq);

-- Экраны: витрина для телевизора в холле (цифровые вывески)
CREATE TABLE screens (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name         text NOT NULL,
  token        text NOT NULL UNIQUE,
  source       text NOT NULL DEFAULT 'playlist' CHECK (source IN ('playlist','category','channel','latest')),
  playlist_id  uuid REFERENCES playlists(id) ON DELETE SET NULL,
  category_id  int REFERENCES categories(id) ON DELETE SET NULL,
  channel_id   uuid REFERENCES users(id) ON DELETE SET NULL,
  shuffle      boolean NOT NULL DEFAULT false,
  subtitles    boolean NOT NULL DEFAULT true,
  show_title   boolean NOT NULL DEFAULT true,
  muted        boolean NOT NULL DEFAULT true,
  is_active    boolean NOT NULL DEFAULT true,
  last_seen_at timestamptz,
  last_ip      text,
  created_by   uuid REFERENCES users(id) ON DELETE SET NULL,
  created_at   timestamptz NOT NULL DEFAULT now(),
  updated_at   timestamptz NOT NULL DEFAULT now()
);
