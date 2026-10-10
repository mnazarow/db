// Тесты связки с порталом документации на базе данных (раздел «Видео»): справочник сотрудников,
// пересылка уведомлений через портал, сведения для портала (состояние, модерация, поиск).
// Портал изображает локальный HTTP-сервер; нужна пустая база PostgreSQL (миграции применяются здесь же).
// Запуск: TEST_DATABASE_URL=postgres://user:pass@127.0.0.1:5432/corpvideo_test node --test test/portal-sync.test.js
// Без TEST_DATABASE_URL тесты пропускаются.
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';

const SECRET = 'test-video-secret-0123456789abcdef';
const DB = process.env.TEST_DATABASE_URL;
const skip = !DB && 'нет TEST_DATABASE_URL';

let server;
let calls = [];
let directory = [];
let notifyStatus = 200;
let m = {};

const person = (id, extra = {}) => ({ id, username: `user${id}`, displayName: `Сотрудник ${id}`, email: `user${id}@example.ru`, department: 'Отдел продаж', position: 'user', active: true, ...extra });

before(async () => {
  if (skip) return;
  server = http.createServer((req, res) => {
    let body = '';
    req.on('data', (c) => { body += c; });
    req.on('end', () => {
      calls.push({ method: req.method, url: req.url, headers: req.headers, body: body ? JSON.parse(body) : null });
      if (req.headers['x-video-secret'] !== SECRET) { res.writeHead(404, { 'content-type': 'application/json' }); res.end('{"error":"not found"}'); return; }
      if (req.url === '/internal/video/users') { res.writeHead(200, { 'content-type': 'application/json' }); res.end(JSON.stringify({ users: directory })); return; }
      if (req.url === '/internal/video/notify') { res.writeHead(notifyStatus, { 'content-type': 'application/json' }); res.end(JSON.stringify(notifyStatus === 200 ? { ok: true } : { error: 'сбой' })); return; }
      res.writeHead(404); res.end('{}');
    });
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  process.env.PORTAL_INTERNAL_URL = `http://127.0.0.1:${server.address().port}`;
  process.env.PORTAL_SHARED_SECRET = SECRET;
  process.env.DATABASE_URL = DB;
  process.env.BASE_URL = 'https://docs.example.ru/video';
  const { runMigrations } = await import('../src/migrate.js');
  await runMigrations({ log: () => {} });
  m = {
    db: await import('../src/db.js'),
    sync: await import('../src/lib/portal-sync.js'),
    notify: await import('../src/lib/portal-notify.js'),
    internal: await import('../src/lib/portal-internal.js'),
  };
});

after(async () => {
  if (skip) return;
  await new Promise((resolve) => server.close(resolve));
  await m.db.pool.end().catch(() => {});
});

const userByPortal = (id) => m.db.one('SELECT * FROM users WHERE portal_user_id = $1', [id]);
const members = async (department) => (await m.db.many(
  `SELECT u.portal_user_id FROM group_members gm JOIN groups g ON g.id = gm.group_id JOIN users u ON u.id = gm.user_id WHERE g.portal_department = $1 ORDER BY 1`, [department],
)).map((r) => r.portal_user_id);

test('справочник: учётные записи всем активным, роли, группы по подразделениям', { skip }, async () => {
  directory = [
    person(9001, { position: 'marketer', department: 'Маркетинг' }),
    person(9002, { position: 'assistant', department: 'маркетинг ' }),
    person(9003),
    person(9004, { active: false }),
    person(9005, { department: '' }),
  ];
  const stats = await m.sync.syncPortalDirectory();
  assert.equal(stats.active, 4);
  assert.equal(stats.created, 4);
  assert.equal(stats.groups, 2, 'подразделения «Маркетинг» и «Отдел продаж» (регистр и пробелы не важны)');
  const marketer = await userByPortal(9001);
  assert.equal(marketer.role, 'moderator');
  assert.equal(marketer.department, 'Маркетинг');
  assert.equal(marketer.auth_provider, 'portal');
  assert.equal((await userByPortal(9002)).can_upload, true);
  assert.equal(await userByPortal(9004), null, 'неактивному учётная запись не заводится');
  assert.deepEqual(await members('Маркетинг'), [9001, 9002]);
  assert.deepEqual(await members('Отдел продаж'), [9003]);
  const g = await m.db.one(`SELECT member_count FROM groups WHERE portal_department = 'Маркетинг'`);
  assert.equal(g.member_count, 2);
  const state = await m.sync.portalSyncStatus();
  assert.equal(state.enabled, true);
  assert.equal(state.lastError, null);
  assert.equal(state.users, 4);
  assert.ok(state.ageSeconds !== null && state.ageSeconds < 60);
});

test('справочник: блокировка уволенных, переводы между подразделениями, ручные участники и группы', { skip }, async () => {
  // Группа «Склад», заведённая вручную, и ручной участник группы подразделения.
  await m.db.query(`INSERT INTO groups(name) VALUES ('Склад') ON CONFLICT DO NOTHING`);
  const manual = await userByPortal(9005);
  const sales = await m.db.one(`SELECT id FROM groups WHERE portal_department = 'Отдел продаж'`);
  await m.db.query('INSERT INTO group_members(group_id, user_id) VALUES ($1, $2)', [sales.id, manual.id]);
  // Модератор видео заблокировал 9005 за нарушения — портал эту блокировку не снимает.
  await m.db.query(`UPDATE users SET status = 'blocked' WHERE id = $1`, [manual.id]);

  directory = [
    person(9001, { position: 'marketer', department: 'Маркетинг' }),
    person(9002, { active: false, department: 'Маркетинг' }),
    person(9003, { department: 'Склад' }),
    person(9005, { department: '' }),
    // 9006 — новый сотрудник, 9004 по-прежнему неактивен; кого нет в списке (никого) — удалён в портале.
    person(9006, { department: 'Склад' }),
  ];
  const stats = await m.sync.syncPortalDirectory();
  assert.equal(stats.blocked, 1, 'заблокирован только 9002');
  const assistant = await userByPortal(9002);
  assert.equal(assistant.status, 'blocked');
  assert.equal(assistant.portal_blocked, true);
  assert.equal((await userByPortal(9005)).status, 'blocked', 'блокировку модератора видео синхронизация не снимает');
  assert.deepEqual(await members('Маркетинг'), [9001], 'заблокированный выходит из группы подразделения');
  assert.deepEqual(await members('Склад'), [9003, 9006], 'переведённый сотрудник — в новой группе');
  assert.deepEqual(await members('Отдел продаж'), [9005], 'ручной участник остаётся, сотрудник от портала вышел');
  const stock = await m.db.one(`SELECT name FROM groups WHERE portal_department = 'Склад'`);
  assert.equal(stock.name, 'Склад (подразделение)', 'имя занято ручной группой — отдельная группа подразделения');

  // Удалён из портала — блокируется; вернулся — разблокируется.
  directory = directory.filter((u) => u.id !== 9006).map((u) => (u.id === 9002 ? { ...u, active: true } : u));
  const again = await m.sync.syncPortalDirectory();
  assert.equal(again.unblocked, 1);
  assert.equal((await userByPortal(9002)).status, 'active');
  assert.equal((await userByPortal(9002)).portal_blocked, false);
  assert.equal((await userByPortal(9006)).status, 'blocked');
});

test('справочник: пустой ответ портала не блокирует всех; ошибка портала — в состоянии синхронизации', { skip }, async () => {
  directory = [];
  await assert.rejects(m.sync.syncPortalDirectory(), /активных сотрудников нет/);
  assert.equal((await userByPortal(9001)).status, 'active');
  let state = await m.sync.portalSyncStatus();
  assert.match(state.lastError, /активных сотрудников нет/);

  process.env.PORTAL_SHARED_SECRET = 'wrong-secret-wrong-secret-wrong';
  const { config } = await import('../src/config.js');
  config.portal.secret = 'wrong-secret-wrong-secret-wrong';
  try {
    await assert.rejects(m.sync.syncPortalDirectory(), /404/);
  } finally {
    config.portal.secret = SECRET;
  }
  state = await m.sync.portalSyncStatus();
  assert.match(state.lastError, /секреты/);
  assert.equal(await m.sync.portalSyncDue(), false, 'следующая попытка — через PORTAL_SYNC_MINUTES');
});

test('уведомления о задачах уходят порталу: только активным сотрудникам, частями', { skip }, async () => {
  assert.equal(m.notify.shouldRelay('moderation'), true);
  assert.equal(m.notify.shouldRelay('assignment'), true);
  assert.equal(m.notify.shouldRelay('new_video'), false, 'о новых видео подписок — только внутри видеопортала');
  const ids = (await m.db.many('SELECT id FROM users WHERE portal_user_id IN (9001, 9002, 9006) ORDER BY portal_user_id')).map((r) => r.id);
  calls = [];
  const sent = await m.notify.relayToPortal({ userIds: [...ids, ids[0]], type: 'moderation', title: 'Видео ожидает проверки', body: 'Иванов: Обзор', link: '/admin/moderation' });
  assert.equal(sent, 2, '9006 заблокирован — ему не отправляется');
  const call = calls.find((c) => c.url === '/internal/video/notify');
  assert.equal(call.method, 'POST');
  assert.equal(call.headers['x-video-secret'], SECRET);
  assert.deepEqual(call.body.users.sort(), [9001, 9002]);
  assert.equal(call.body.type, 'moderation');
  assert.equal(call.body.link, '/admin/moderation');

  notifyStatus = 500;
  try {
    await assert.rejects(m.notify.relayToPortal({ userIds: ids, type: 'moderation', title: 'x' }), /500/);
  } finally {
    notifyStatus = 200;
  }
});

test('сведения для портала: подпись, число на модерации, состояние, поиск с учётом видимости', { skip }, async () => {
  assert.equal(m.internal.verifyPortalSecret(SECRET), true);
  assert.equal(m.internal.verifyPortalSecret('x'), false);
  assert.equal(m.internal.verifyPortalSecret(undefined), false);

  const owner = await userByPortal(9001);
  const add = (shortId, title, extra = {}) => m.db.query(
    `INSERT INTO videos(short_id, owner_id, title, description, status, moderation_status, visibility, published_at, duration, tags)
     VALUES ($1, $2, $3, $4, $5, $6, $7, now(), 754, '{}')`,
    [shortId, owner.id, title, extra.description || '', extra.status || 'ready', extra.moderation || 'approved', extra.visibility || 'internal'],
  );
  await add('pubvid0001', 'Насосная станция: обзор', { visibility: 'public', description: 'Монтаж насосной станции' });
  await add('intvid0001', 'Насосная станция — внутренний разбор');
  await add('modvid0001', 'Насосная станция на проверке', { moderation: 'pending' });

  assert.deepEqual(await m.internal.moderationSummary(), { pending: 1 });
  const employee = await m.internal.searchVideos({ q: 'насосная', audience: 'employee', limit: 5 });
  assert.deepEqual(employee.items.map((v) => v.shortId).sort(), ['intvid0001', 'pubvid0001'], 'на модерации — не находится');
  assert.equal(employee.total, 2);
  assert.equal(employee.items.find((v) => v.shortId === 'pubvid0001').duration, 754);
  const guest = await m.internal.searchVideos({ q: 'насосная', audience: 'guest' });
  assert.deepEqual(guest.items.map((v) => v.shortId), ['pubvid0001'], 'гостю — только открытые');
  assert.deepEqual(await m.internal.searchVideos({ q: 'н' }), { total: 0, items: [] });

  const status = await m.internal.statusReport();
  assert.equal(status.status, 'ok');
  assert.equal(status.db, 'ok');
  assert.equal(status.moderation.pending, 1);
  assert.equal(typeof status.queue.queued, 'number');
  assert.ok('freeBytes' in status.storage || 'error' in status.storage);
  assert.equal(status.portalSync.enabled, true);
});
