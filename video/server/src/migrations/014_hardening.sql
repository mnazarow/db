-- 1.12.0 — доработки по списку «Что доделать»: надёжность рассылок, безопасность, производительность.

-- Письмо-послесловие вебинара: отметка по каждому участнику. Раньше отметка ставилась
-- на весь вебинар в конце рассылки — сбой посередине означал повторные письма всем.
ALTER TABLE live_registrations ADD COLUMN IF NOT EXISTS followup_at timestamptz;
-- Уже разосланные вебинары: всех их участников считаем получившими письмо
UPDATE live_registrations r SET followup_at = s.followup_sent_at
  FROM live_streams s WHERE s.id = r.stream_id AND s.followup_sent_at IS NOT NULL AND r.followup_at IS NULL;

-- Кривая присутствия на вебинаре учитывала только гостей (по регистрации): сотрудники
-- отмечают присутствие через страницу эфира и в кривую не попадали. Их отметки — по учётной записи.
CREATE TABLE IF NOT EXISTS webinar_presence_users (
  stream_id uuid    NOT NULL REFERENCES live_streams(id) ON DELETE CASCADE,
  minute    integer NOT NULL,
  user_id   uuid    NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  PRIMARY KEY (stream_id, minute, user_id)
);

-- Поиск по «идентификатору или короткому адресу» был написан как id::text = $1: приведение
-- столбца к тексту выключает индекс по первичному ключу, и каждое открытие видео, эфира или
-- плейлиста (а также каждый сигнал прогресса просмотра) проходило таблицу целиком.
-- cv_uuid превращает параметр в uuid (или NULL, если это не uuid) — сравнение идёт по индексу.
CREATE OR REPLACE FUNCTION cv_uuid(t text) RETURNS uuid
  LANGUAGE sql IMMUTABLE PARALLEL SAFE AS
$$ SELECT CASE WHEN t ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' THEN t::uuid END $$;

-- Личный токен лент (?ft=): у токена появляется дата выдачи — от неё считается срок жизни
-- (настройка feeds.token_days). Уже выданные токены отсчитывают срок с момента обновления.
ALTER TABLE users ADD COLUMN IF NOT EXISTS feed_token_created_at timestamptz;
UPDATE users SET feed_token_created_at = now() WHERE feed_token IS NOT NULL AND feed_token_created_at IS NULL;

-- Неудачные входы — по паре «логин + адрес». Раньше блокировалась учётная запись целиком:
-- зная почту коллеги, можно было держать его вход закрытым, перебирая пароли со своего компьютера.
CREATE TABLE IF NOT EXISTS login_failures (
  login        text        NOT NULL,
  ip           text        NOT NULL,
  failures     integer     NOT NULL DEFAULT 0,
  locked_until timestamptz,
  updated_at   timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (login, ip)
);
CREATE INDEX IF NOT EXISTS login_failures_updated_idx ON login_failures(updated_at);

-- Код TOTP одноразовый: запоминаем номер последнего принятого 30-секундного шага,
-- чтобы подсмотренный или перехваченный код нельзя было ввести повторно в пределах окна.
ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_last_counter bigint;

-- Экранирование шаблонов LIKE для значений, которые подставляются прямо в SQL ('%' || cv_like($1) || '%'):
-- поиск по «_» или «%» больше не совпадает со всем подряд.
CREATE OR REPLACE FUNCTION cv_like(t text) RETURNS text
  LANGUAGE sql IMMUTABLE PARALLEL SAFE AS
$$ SELECT replace(replace(replace(coalesce(t, ''), '\', '\\'), '%', '\%'), '_', '\_') $$;
