// Модульные тесты общего входа с порталом документации (lib/portal.js) — без базы данных и запущенного API:
// портал изображает локальный HTTP-сервер. Запуск: node --test test/portal.test.js
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';

const SECRET = 'test-video-secret-0123456789abcdef';
let server;
let calls = [];
let reply = () => ({ status: 200, body: { authenticated: false, state: 'guest', user: null } });
let portal;

before(async () => {
  server = http.createServer((req, res) => {
    calls.push({ url: req.url, headers: req.headers });
    const r = reply(req);
    const headers = { 'content-type': 'application/json' };
    if (r.setCookie) headers['set-cookie'] = r.setCookie;
    res.writeHead(r.status, headers);
    res.end(typeof r.body === 'string' ? r.body : JSON.stringify(r.body));
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  // Настройки читаются при загрузке модуля — окружение задаётся до импорта.
  process.env.PORTAL_INTERNAL_URL = `http://127.0.0.1:${server.address().port}`;
  process.env.PORTAL_SHARED_SECRET = SECRET;
  process.env.PORTAL_SESSION_CACHE_MS = '60000';
  process.env.BASE_URL = 'https://docs.example.ru/video';
  process.env.DATABASE_URL = 'postgres://nobody:nothing@127.0.0.1:1/none';
  portal = await import('../src/lib/portal.js');
});

after(async () => {
  await new Promise((resolve) => server.close(resolve));
  const { pool } = await import('../src/db.js');
  await pool.end().catch(() => {});
});

const fakeReq = (cookie, extra = {}) => ({ headers: { cookie, 'user-agent': 'test-agent' }, ip: '10.1.2.3', ...extra });

test('роли портала → права видеопортала', () => {
  const m = portal.POSITION_MAP;
  assert.deepEqual(m.admin, { role: 'admin', can_upload: true, can_stream: true });
  assert.deepEqual(m.marketer, { role: 'moderator', can_upload: true, can_stream: true });
  assert.deepEqual(m.assistant, { role: 'user', can_upload: true, can_stream: false });
  assert.deepEqual(m.user, { role: 'user', can_upload: false, can_stream: false });
  // В режиме портала видео помощника проходят премодерацию, регистрация и свой вход выключены.
  assert.equal(portal.FORCED_SETTINGS['upload.premoderation'], true);
  assert.equal(portal.FORCED_SETTINGS['registration.enabled'], false);
  assert.equal(portal.FORCED_SETTINGS['ldap.enabled'], false);
  assert.equal(portal.portalEnabled(), true);
});

test('собственные cookie видеопортала (cv_*) порталу не передаются', () => {
  assert.equal(portal.portalCookieHeader('cv_session=abc; PHPSESSID=s1; cv_theme=dark; REMEMBERME=r1'), 'PHPSESSID=s1; REMEMBERME=r1');
  assert.equal(portal.portalCookieHeader(''), '');
  assert.equal(portal.portalCookieHeader(undefined), '');
});

test('адрес-заглушка для сотрудника без почты', () => {
  assert.equal(portal.placeholderEmail('Ivanov.I', 7), 'ivanov.i@portal.invalid');
  assert.equal(portal.placeholderEmail('Петров', 8, 1), 'user+8@portal.invalid');
  assert.equal(portal.isPlaceholderEmail('ivanov@portal.invalid'), true);
  assert.equal(portal.isPlaceholderEmail('ivanov@example.ru'), false);
});

test('ссылки на портал', () => {
  const links = portal.portalLinks({ name: 'Портал', home: '/', login: '/login', moderation: '/moderation' });
  assert.deepEqual(links, { enabled: true, name: 'Портал', home: '/', login: '/login', moderation: '/moderation' });
});

test('без cookie портал не опрашивается — гость', async () => {
  calls = [];
  const s = await portal.portalSession(fakeReq(''));
  assert.equal(s.state, 'guest');
  assert.equal(s.authenticated, false);
  assert.equal(calls.length, 0);
});

test('сессия портала: секрет, cookie, адрес клиента; ответ кэшируется', async () => {
  portal.clearPortalCache();
  calls = [];
  reply = () => ({
    status: 200,
    body: {
      authenticated: true,
      state: 'ok',
      user: { id: 5, username: 'markova', displayName: 'Маркова Анна', email: 'markova@example.ru', position: 'marketer' },
      logout: { url: '/logout', token: 't' },
      portal: { name: 'Портал документации', home: '/', login: '/login', moderation: '/moderation' },
    },
  });
  const s = await portal.portalSession(fakeReq('PHPSESSID=abc; cv_session=zzz'));
  assert.equal(s.authenticated, true);
  assert.equal(s.state, 'ok');
  assert.equal(s.portalUser.position, 'marketer');
  assert.equal(s.links.moderation, '/moderation');
  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, '/internal/video/session');
  assert.equal(calls[0].headers['x-video-secret'], SECRET);
  assert.equal(calls[0].headers.cookie, 'PHPSESSID=abc');
  assert.equal(calls[0].headers['x-forwarded-for'], '10.1.2.3');
  assert.equal(calls[0].headers['x-forwarded-proto'], 'https');

  // Повторный запрос с теми же cookie — из кэша, портал не опрашивается.
  const again = await portal.portalSession(fakeReq('PHPSESSID=abc'));
  assert.equal(again.authenticated, true);
  assert.equal(calls.length, 1);
});

test('одновременные запросы страницы ждут один ответ портала', async () => {
  portal.clearPortalCache();
  calls = [];
  reply = () => ({ status: 200, body: { authenticated: false, state: 'guest', user: null } });
  const all = await Promise.all([1, 2, 3, 4].map(() => portal.portalSession(fakeReq('PHPSESSID=same'))));
  assert.equal(calls.length, 1);
  for (const s of all) assert.equal(s.state, 'guest');
});

test('«Запомнить меня»: новые cookie портала передаются браузеру и не кэшируются', async () => {
  portal.clearPortalCache();
  calls = [];
  reply = () => ({
    status: 200,
    setCookie: ['PHPSESSID=new; path=/; HttpOnly', 'REMEMBERME=r2; path=/; HttpOnly'],
    body: { authenticated: true, state: 'ok', user: { id: 9, username: 'belov', position: 'assistant' } },
  });
  const s = await portal.portalSession(fakeReq('REMEMBERME=r1'));
  assert.equal(s.authenticated, true);
  assert.equal(s.setCookies.length, 2);
  const s2 = await portal.portalSession(fakeReq('REMEMBERME=r1'));
  assert.equal(calls.length, 2, 'ответ с Set-Cookie не кэшируется');
  assert.equal(s2.setCookies.length, 2);
});

test('второй фактор не пройден — не вошёл', async () => {
  portal.clearPortalCache();
  reply = () => ({ status: 200, body: { authenticated: false, state: 'two_factor', user: null } });
  const s = await portal.portalSession(fakeReq('PHPSESSID=2fa'));
  assert.equal(s.authenticated, false);
  assert.equal(s.state, 'two_factor');
  assert.equal(s.portalUser, null);
});

test('портал отклонил секрет (404) или недоступен — гость с описанием ошибки', async () => {
  portal.clearPortalCache();
  reply = () => ({ status: 404, body: { error: 'not found' } });
  const s = await portal.portalSession(fakeReq('PHPSESSID=x404'));
  assert.equal(s.authenticated, false);
  assert.match(s.error, /PORTAL_SHARED_SECRET/);

  portal.clearPortalCache();
  reply = () => ({ status: 200, body: 'не JSON' });
  const s2 = await portal.portalSession(fakeReq('PHPSESSID=xjson'));
  assert.equal(s2.authenticated, false);
  assert.ok(s2.error);
});
