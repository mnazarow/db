-- 1.13.0 — справочник сотрудников из портала документации (раздел «Видео»).
--
-- Видеопортал по расписанию получает от портала список сотрудников: заводит учётные записи всем (а не только
-- заходившим в раздел — иначе им нельзя назначить обязательный просмотр), блокирует уволенных и собирает
-- группы по подразделениям портала.
--
-- portal_blocked — учётная запись заблокирована потому, что сотрудник заблокирован (или удалён) в портале;
-- такую блокировку снимает портал. Блокировку модератором видео (за нарушения) портал не трогает.
ALTER TABLE users ADD COLUMN IF NOT EXISTS portal_blocked boolean NOT NULL DEFAULT false;
ALTER TABLE users ADD COLUMN IF NOT EXISTS department text;

-- Группа-подразделение портала: состав ведёт синхронизация (участники, добавленные вручную, остаются).
ALTER TABLE groups ADD COLUMN IF NOT EXISTS portal_department citext;
CREATE UNIQUE INDEX IF NOT EXISTS groups_portal_department_key ON groups(portal_department) WHERE portal_department IS NOT NULL;
ALTER TABLE group_members ADD COLUMN IF NOT EXISTS via_portal boolean NOT NULL DEFAULT false;

-- Итог последней синхронизации — для «Мониторинга» портала и docportal video status.
CREATE TABLE IF NOT EXISTS portal_sync_state (
  id          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
  last_run_at timestamptz,
  last_ok_at  timestamptz,
  last_error  text,
  stats       jsonb NOT NULL DEFAULT '{}'
);
INSERT INTO portal_sync_state(id) VALUES (1) ON CONFLICT DO NOTHING;
