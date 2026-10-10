-- 1.13.0 — встраивание в портал документации (раздел «Видео», общий вход).
--
-- Учётная запись видеопортала привязывается к сотруднику портала (portal_user_id); вход выполняется
-- в портале, поэтому способ входа таких записей — 'portal'.
ALTER TABLE users ADD COLUMN IF NOT EXISTS portal_user_id integer;
CREATE UNIQUE INDEX IF NOT EXISTS users_portal_user_id_key ON users(portal_user_id);
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_auth_provider_check;
ALTER TABLE users ADD CONSTRAINT users_auth_provider_check CHECK (auth_provider IN ('local','ldap','oidc','portal'));
