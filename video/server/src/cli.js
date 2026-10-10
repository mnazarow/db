#!/usr/bin/env node
// Утилита командной строки: миграции, администраторы, домены, настройки, служебные операции.
import fs from 'node:fs';
import { config, assertConfig } from './config.js';
import { runMigrations } from './migrate.js';
import { one, query, many, closeDb } from './db.js';
import { hashPassword } from './lib/crypto.js';
import { loadSettings, setSettings, settingsForAdmin, DEFAULTS } from './lib/settings.js';
import { EMAIL_RE, handleFromEmail } from './lib/util.js';
import { enqueue } from './lib/jobs.js';

/** Пароль: из stdin (--password-stdin), переменной окружения (--password-env ИМЯ) или аргумента --password. */
function readPassword(args) {
  if (args['password-stdin']) { try { return fs.readFileSync(0, 'utf8').replace(/\r?\n$/, ''); } catch { return ''; } }
  if (args['password-env']) return String(process.env[String(args['password-env'])] || '');
  return String(args.password || '');
}

function parseArgs(argv) {
  const args = { _: [] };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a.startsWith('--')) {
      // Делим по первому «=»: пароль «a=b» раньше обрезался до «a»
      const eq = a.indexOf('=');
      const k = eq > 0 ? a.slice(2, eq) : a.slice(2);
      const v = eq > 0 ? a.slice(eq + 1) : undefined;
      if (v !== undefined) args[k] = v;
      else if (argv[i + 1] && !argv[i + 1].startsWith('--')) args[k] = argv[++i];
      else args[k] = true;
    } else args._.push(a);
  }
  return args;
}

async function uniqueHandle(base) {
  let candidate = base;
  for (let i = 0; i < 50; i++) {
    if (!(await one('SELECT 1 FROM users WHERE handle = $1', [candidate]))) return candidate;
    candidate = `${base}${Math.floor(Math.random() * 9000 + 1000)}`;
  }
  return `${base}${Date.now()}`;
}

const commands = {
  async migrate() {
    const n = await runMigrations();
    console.log(n ? `Применено миграций: ${n}` : 'База данных актуальна');
  },
  async 'create-admin'(args) {
    const email = String(args.email || '').toLowerCase();
    // Пароль лучше передавать через переменную окружения (--password-env ИМЯ): аргументы командной
    // строки видны всем пользователям сервера в списке процессов
    const password = readPassword(args);
    const name = String(args.name || 'Администратор');
    if (!EMAIL_RE.test(email)) throw new Error('Укажите --email');
    if (password.length < 8) throw new Error('Укажите --password (не короче 8 символов)');
    const existing = await one('SELECT id, role, status FROM users WHERE email = $1', [email]);
    if (existing && !args['reset-password']) {
      // Повторный запуск установки не должен молча менять пароль действующего администратора
      console.log(`Пользователь ${email} уже есть — пароль не изменён (чтобы задать новый: --reset-password или corpvideo cli set-password).`);
      if (existing.role !== 'admin' || existing.status !== 'active') console.log('Внимание: у этого пользователя нет активной роли администратора — назначьте её в панели или через --reset-password.');
    } else if (existing) {
      await query(`UPDATE users SET password_hash = $2, role = 'admin', status = 'active', display_name = COALESCE(NULLIF($3, ''), display_name), auth_provider = 'local', deleted_at = NULL, email_verified_at = COALESCE(email_verified_at, now()), approved_at = COALESCE(approved_at, now()) WHERE id = $1`, [existing.id, hashPassword(password), name]);
      console.log(`Пользователь ${email} обновлён: роль admin, статус active, пароль задан.`);
    } else {
      const handle = await uniqueHandle(handleFromEmail(email));
      await query(`INSERT INTO users(email, handle, display_name, password_hash, role, status, email_verified_at, approved_at) VALUES ($1,$2,$3,$4,'admin','active', now(), now())`, [email, handle, name, hashPassword(password)]);
      console.log(`Администратор ${email} создан (канал @${handle}).`);
    }
    // Домен администратора добавляем в разрешённые
    const domain = email.split('@')[1];
    if (args['add-domain'] !== 'false') {
      await query('INSERT INTO allowed_domains(domain, auto_approve, note) VALUES ($1, false, $2) ON CONFLICT (domain) DO NOTHING', [domain, 'Добавлен автоматически при создании администратора']);
      console.log(`Домен @${domain} разрешён для регистрации.`);
    }
  },
  async 'set-password'(args) {
    const email = String(args.email || '').toLowerCase();
    const password = readPassword(args);
    if (!email || password.length < 8) throw new Error('Укажите --email и --password (не короче 8 символов)');
    const r = await query(`UPDATE users SET password_hash = $2, failed_logins = 0, locked_until = NULL, totp_enabled = false, totp_secret = NULL WHERE email = $1`, [email, hashPassword(password)]);
    if (!r.rowCount) throw new Error('Пользователь не найден');
    await query('DELETE FROM sessions WHERE user_id = (SELECT id FROM users WHERE email = $1)', [email]);
    await query('DELETE FROM login_failures WHERE login = $1 OR login = (SELECT handle FROM users WHERE email = $1)', [email]).catch(() => {});
    console.log('Пароль обновлён, двухфакторная аутентификация сброшена, блокировка входа снята, сессии завершены.');
  },
  async 'add-domain'(args) {
    const domain = String(args._[0] || '').toLowerCase().replace(/^@/, '');
    if (!domain) throw new Error('Использование: add-domain <домен> [--auto-approve]');
    await query('INSERT INTO allowed_domains(domain, auto_approve) VALUES ($1, $2) ON CONFLICT (domain) DO UPDATE SET auto_approve = EXCLUDED.auto_approve', [domain, !!args['auto-approve']]);
    console.log(`Домен @${domain} разрешён${args['auto-approve'] ? ' (автоодобрение)' : ''}.`);
  },
  async 'list-domains'() {
    const rows = await many('SELECT domain, auto_approve FROM allowed_domains ORDER BY domain');
    for (const r of rows) console.log(`${r.domain}\t${r.auto_approve ? 'автоодобрение' : 'ручное одобрение'}`);
    if (!rows.length) console.log('(разрешённых доменов нет)');
  },
  async 'settings'(args) {
    const [action, key, ...rest] = args._;
    if (action === 'get') {
      const all = await settingsForAdmin();
      if (key) console.log(JSON.stringify(all[key], null, 2));
      else console.log(JSON.stringify(all, null, 2));
    } else if (action === 'set') {
      if (!key || !(key in DEFAULTS)) throw new Error(`Неизвестный ключ. Доступные: ${Object.keys(DEFAULTS).join(', ')}`);
      let value = rest.join(' ');
      try { value = JSON.parse(value); } catch { /* строка */ }
      await setSettings({ [key]: value });
      console.log(`Сохранено: ${key} = ${JSON.stringify((await loadSettings(true))[key])}`);
    } else {
      throw new Error('Использование: settings get [ключ] | settings set <ключ> <значение>');
    }
  },
  async status() {
    const users = await one(`SELECT count(*)::int AS total, count(*) FILTER (WHERE status = 'pending_approval')::int AS pending FROM users WHERE deleted_at IS NULL`);
    const videos = await one(`SELECT count(*)::int AS total, count(*) FILTER (WHERE status IN ('queued','processing'))::int AS processing, coalesce(sum(storage_bytes),0)::bigint AS bytes FROM videos WHERE deleted_at IS NULL`);
    const jobs = await many(`SELECT status, count(*)::int AS n FROM jobs GROUP BY status`);
    console.log(`Версия: ${config.version}`);
    console.log(`Пользователи: ${users.total} (ожидают одобрения: ${users.pending})`);
    console.log(`Видео: ${videos.total} (в обработке: ${videos.processing}), хранилище: ${(Number(videos.bytes) / 1024 / 1024 / 1024).toFixed(2)} ГБ`);
    console.log(`Задания: ${jobs.map((j) => `${j.status}=${j.n}`).join(', ') || 'нет'}`);
  },
  async reindex() {
    const r = await query('UPDATE videos SET title = title');
    console.log(`Переиндексировано видео: ${r.rowCount}`);
  },
  async 'recompute-storage'() {
    await enqueue('recompute_storage', {}, { dedupe: false });
    console.log('Задание пересчёта поставлено в очередь.');
  },
  async 'retry-failed'() {
    const r = await query(`UPDATE jobs SET status = 'queued', run_at = now(), error = NULL, attempts = 0 WHERE status = 'failed'`);
    console.log(`Перезапущено заданий: ${r.rowCount}`);
  },
  async maintenance() {
    await enqueue('maintenance', { hourly: true, daily: true }, { dedupe: false });
    console.log('Задание обслуживания поставлено в очередь.');
  },
  async 'list-users'(args) {
    const rows = await many(`SELECT email, handle, display_name, role, status, auth_provider, created_at FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT $1`, [Number(args.limit) || 50]);
    for (const r of rows) console.log(`${r.email}\t@${r.handle}\t${r.display_name}\t${r.role}\t${r.status}\t${r.auth_provider}`);
  },
  async 'set-role'(args) {
    const email = String(args.email || '').toLowerCase();
    const role = String(args.role || '');
    if (!['user', 'moderator', 'admin'].includes(role)) throw new Error('--role user|moderator|admin');
    const r = await query('UPDATE users SET role = $2 WHERE email = $1', [email, role]);
    if (!r.rowCount) throw new Error('Пользователь не найден');
    console.log(`Роль ${email}: ${role}`);
  },
  async 'approve'(args) {
    const email = String(args.email || args._[0] || '').toLowerCase();
    const r = await query(`UPDATE users SET status = 'active', approved_at = now() WHERE email = $1`, [email]);
    if (!r.rowCount) throw new Error('Пользователь не найден');
    console.log(`Пользователь ${email} активирован.`);
  },
  help() {
    console.log(`CorpVideo CLI. Команды:
  migrate                                   применить миграции БД
  create-admin --email E --password P [--name N]   создать/обновить администратора
  set-password --email E --password P       сменить пароль пользователя
  set-role --email E --role R               роль user|moderator|admin
  approve --email E                         активировать пользователя
  add-domain <домен> [--auto-approve]       разрешить домен для регистрации
  list-domains | list-users [--limit N]     списки
  settings get [ключ] | settings set <ключ> <значение>
  status | reindex | recompute-storage | retry-failed | maintenance`);
  },
};

const args = parseArgs(process.argv.slice(2));
const cmd = args._.shift() || 'help';
if (!commands[cmd]) { console.error(`Неизвестная команда: ${cmd}`); commands.help(); process.exit(2); }
if (cmd !== 'help') assertConfig();
try {
  if (cmd !== 'help' && cmd !== 'migrate') await runMigrations({ log: () => {} });
  await commands[cmd](args);
  await closeDb();
  process.exit(0);
} catch (e) {
  console.error('Ошибка:', e.message);
  await closeDb().catch(() => {});
  process.exit(1);
}
