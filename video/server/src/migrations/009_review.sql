-- 1.8: согласование видео, календарь публикаций, уведомления в мессенджеры, порядок в хранилище

-- Согласование: видео отправляется рецензентам, они принимают или возвращают на доработку
-- (как review-страницы Frame.io, Vimeo Review и Wipster)
CREATE TABLE video_reviews (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  video_id     uuid NOT NULL REFERENCES videos(id) ON DELETE CASCADE,
  status       text NOT NULL DEFAULT 'in_review'
               CHECK (status IN ('in_review','approved','changes_requested','cancelled')),
  note         text NOT NULL DEFAULT '',          -- что проверить
  due_at       timestamptz,
  requested_by uuid REFERENCES users(id) ON DELETE SET NULL,
  decided_at   timestamptz,
  created_at   timestamptz NOT NULL DEFAULT now(),
  updated_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX video_reviews_video_idx ON video_reviews(video_id, created_at DESC);
CREATE INDEX video_reviews_status_idx ON video_reviews(status) WHERE status = 'in_review';

CREATE TABLE review_reviewers (
  review_id   uuid NOT NULL REFERENCES video_reviews(id) ON DELETE CASCADE,
  user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  decision    text CHECK (decision IN ('approved','changes_requested')),
  comment     text NOT NULL DEFAULT '',
  decided_at  timestamptz,
  PRIMARY KEY (review_id, user_id)
);

-- Замечания рецензентов с привязкой к секунде записи
CREATE TABLE review_comments (
  id          bigserial PRIMARY KEY,
  review_id   uuid NOT NULL REFERENCES video_reviews(id) ON DELETE CASCADE,
  user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  at_sec      double precision,
  body        text NOT NULL,
  resolved    boolean NOT NULL DEFAULT false,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX review_comments_review_idx ON review_comments(review_id, at_sec NULLS LAST, id);

ALTER TABLE videos ADD COLUMN review_status text
  CHECK (review_status IN ('in_review','approved','changes_requested'));

-- Формат доставки вебхука: обычный JSON или карточка мессенджера
ALTER TABLE webhooks ADD COLUMN format text NOT NULL DEFAULT 'json'
  CHECK (format IN ('json','slack','mattermost','teams'));
