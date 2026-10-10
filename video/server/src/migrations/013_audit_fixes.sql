-- 1.11.0 — исправления по итогам аудита.

-- Присутствие на вебинаре считалось по каждому сигналу плеера, а не по людям:
-- при сигнале раз в 30 секунд одна минута давала «2 человека» вместо одного.
-- Отметки уникальны по (эфир, минута, регистрация) — повторный сигнал ничего не добавляет.
CREATE TABLE IF NOT EXISTS webinar_presence_marks (
  stream_id       uuid NOT NULL REFERENCES live_streams(id) ON DELETE CASCADE,
  minute          integer NOT NULL,
  registration_id bigint NOT NULL REFERENCES live_registrations(id) ON DELETE CASCADE,
  PRIMARY KEY (stream_id, minute, registration_id)
);

-- Суточная уборка удаляла записи по колонкам без индекса — на больших таблицах это
-- превращалось в долгий последовательный проход с блокировками.
CREATE INDEX IF NOT EXISTS notifications_created_idx ON notifications(created_at);
CREATE INDEX IF NOT EXISTS video_views_last_at_idx   ON video_views(last_at);
CREATE INDEX IF NOT EXISTS jobs_finished_idx         ON jobs(finished_at) WHERE finished_at IS NOT NULL;
CREATE INDEX IF NOT EXISTS audit_log_created_idx     ON audit_log(created_at);

-- Захват задания фильтрует по типу и сортирует по приоритету и идентификатору:
-- прежний индекс этого не покрывал и при длинной очереди каждый захват досортировывал.
CREATE INDEX IF NOT EXISTS jobs_claim_idx ON jobs(type, priority DESC, id) WHERE status = 'queued';
