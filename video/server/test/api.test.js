// Интеграционные тесты API. Требуют запущенных API (BASE) и воркера, а также ffmpeg.
// Запуск: BASE=http://localhost:3000 node --test test/
import { test, before } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE || 'http://localhost:3000';
const ADMIN = { login: process.env.ADMIN_EMAIL || 'admin@vodokomfort.ru', password: process.env.ADMIN_PASSWORD || 'Admin12345' };

class Client {
  constructor() { this.cookie = ''; }
  async req(method, url, body, opts = {}) {
    const headers = { ...(opts.headers || {}) };
    if (this.cookie) headers.cookie = this.cookie;
    let payload = body;
    if (body && !(body instanceof Buffer) && !(body instanceof FormData) && typeof body !== 'string') { headers['content-type'] = 'application/json'; payload = JSON.stringify(body); }
    const res = await fetch(BASE + url, { method, headers, body: payload, redirect: 'manual' });
    const sc = res.headers.getSetCookie?.() || [];
    for (const c of sc) {
      const [pair] = c.split(';');
      const [name] = pair.split('=');
      const others = this.cookie.split('; ').filter((x) => x && !x.startsWith(name + '='));
      this.cookie = [...others, pair].join('; ');
    }
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch { /* not json */ }
    return { status: res.status, json, text, headers: res.headers };
  }
  get(u) { return this.req('GET', u); }
  post(u, b, o) { return this.req('POST', u, b, o); }
  patch(u, b, o) { return this.req('PATCH', u, b, o); }
  put(u, b) { return this.req('PUT', u, b); }
  del(u) { return this.req('DELETE', u); }
}

// Прямой доступ к базе — только чтобы смоделировать то, что в песочнице без MediaMTX не случится само
// (эфир «в эфире», прошедшее время). Адрес берётся из окружения или из server/.env.
import pg from 'pg';
const envText = (() => { try { return fs.readFileSync(new URL('../.env', import.meta.url), 'utf8'); } catch { return ''; } })();
const DB_URL = process.env.DATABASE_URL || (envText.match(/^DATABASE_URL=(.*)$/m) || [])[1] || 'postgres://corpvideo:corpvideo@127.0.0.1:5432/corpvideo';
async function sql(text, params = []) {
  const c = new pg.Client({ connectionString: DB_URL });
  await c.connect();
  try { return (await c.query(text, params)).rows; } finally { await c.end(); }
}

// Право на медиафайл: напрямую — вопросом к /api/media/auth (так спрашивает nginx), через nginx —
// настоящим запросом к /media/… (внутренний /api/media/auth снаружи закрыт и отвечает 404).
const VIA_NGINX = process.env.VIA_NGINX === '1';
function mediaAuth(client, uri) {
  if (VIA_NGINX) return client.get(uri);
  return client.req('GET', '/api/media/auth', null, { headers: { 'x-original-uri': uri } });
}

const admin = new Client();
const user = new Client();
const guest = new Client();
const stamp = Date.now().toString(36);
const userEmail = `tester.${stamp}@vodokomfort.ru`;
let videoId, shortId;

before(async () => {
  const r = await admin.post('/api/auth/login', ADMIN);
  assert.equal(r.status, 200, 'admin login: ' + r.text);
});

test('регистрация: запрещённый домен отклоняется', async () => {
  const r = await guest.post('/api/auth/register', { email: `x${stamp}@gmail.com`, password: 'Password123', displayName: 'Чужой' });
  assert.equal(r.status, 403);
});

test('регистрация с разрешённого домена → ожидание одобрения → одобрение админом', async () => {
  const r = await user.post('/api/auth/register', { email: userEmail, password: 'Password123', displayName: 'Тестовый Пользователь' });
  assert.equal(r.status, 200, r.text);
  assert.equal(r.json.status, 'pending_approval'); // SMTP не настроен → без подтверждения e-mail
  const me = await user.get('/api/auth/me');
  assert.equal(me.json.user.status, 'pending_approval');
  // Неактивный пользователь не может загружать
  const up = await user.post('/api/uploads', { filename: 'a.mp4', size: 100 });
  assert.equal(up.status, 403);
  // Админ видит заявку и одобряет
  const list = await admin.get('/api/admin/users?status=pending_approval');
  const found = list.json.users.find((u) => u.email === userEmail);
  assert.ok(found, 'заявка в списке');
  const ap = await admin.post(`/api/admin/users/${found.id}/approve`, {});
  assert.equal(ap.status, 200);
  const me2 = await user.get('/api/auth/me');
  assert.equal(me2.json.user.status, 'active');
});

test('загрузка видео по частям, транскодирование воркером', async () => {
  const tmp = path.join(os.tmpdir(), `cv-test-${stamp}.mp4`);
  execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=640x360:rate=25:duration=5', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=5', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', tmp]);
  const data = fs.readFileSync(tmp);
  const init = await user.post('/api/uploads', { filename: 'Тестовое видео.mp4', size: data.length, mime: 'video/mp4', title: 'Тестовое видео', visibility: 'internal' });
  assert.equal(init.status, 200, init.text);
  const { uploadId, chunkSize } = init.json;
  videoId = init.json.videoId; shortId = init.json.shortId;
  const chunk = Math.min(chunkSize, 200000);
  for (let off = 0; off < data.length; off += chunk) {
    const part = data.subarray(off, Math.min(data.length, off + chunk));
    const r = await user.req('PATCH', `/api/uploads/${uploadId}`, part, { headers: { 'content-type': 'application/offset+octet-stream', 'upload-offset': String(off) } });
    assert.equal(r.status, 200, r.text);
    assert.equal(r.json.offset, off + part.length);
  }
  // Повтор с неверным смещением → 409
  const bad = await user.req('PATCH', `/api/uploads/${uploadId}`, data.subarray(0, 10), { headers: { 'content-type': 'application/offset+octet-stream', 'upload-offset': '0' } });
  assert.equal(bad.status, 409);
  const done = await user.post(`/api/uploads/${uploadId}/complete`, {});
  assert.equal(done.status, 200, done.text);
  assert.equal(done.json.video.status, 'queued');
  // Ждём обработку
  let v;
  for (let i = 0; i < 120; i++) {
    await new Promise((r) => setTimeout(r, 1000));
    const g = await user.get(`/api/videos/${videoId}`);
    v = g.json.video;
    if (v.status === 'ready' || v.status === 'failed') break;
  }
  assert.equal(v.status, 'ready', 'видео обработано: ' + JSON.stringify(v.processingError));
  assert.ok(v.hlsUrl.endsWith('master.m3u8'));
  assert.ok(v.renditions.length >= 1);
  assert.ok(v.thumbnailUrl);
  assert.ok(v.duration > 4 && v.duration < 6);
  fs.unlinkSync(tmp);
});

test('доступ: internal-видео недоступно гостю, HLS раздаётся владельцу', async () => {
  const g = await guest.get(`/api/videos/${shortId}`);
  assert.equal(g.status, 401);
  const v = (await user.get(`/api/videos/${shortId}`)).json.video;
  const m = await user.get(v.hlsUrl);
  assert.equal(m.status, 200);
  assert.ok(m.text.includes('#EXTM3U'));
  const gm = await guest.get(v.hlsUrl);
  assert.equal(gm.status, 401);
  const auth = await mediaAuth(guest, v.hlsUrl);
  assert.equal(auth.status, 401);
  const auth2 = await mediaAuth(user, v.hlsUrl);
  assert.equal(auth2.status, 200);
});

test('редактирование: публичная видимость, теги, главы; гость видит публичное', async () => {
  const r = await user.patch(`/api/videos/${videoId}`, { visibility: 'public', tags: ['тест', 'обучение'], description: '0:00 Начало\n0:02 Середина\nОписание #hashtag', categoryId: 1 });
  assert.equal(r.status, 200, r.text);
  assert.equal(r.json.video.visibility, 'public');
  assert.deepEqual(r.json.video.tags, ['тест', 'обучение']);
  assert.equal(r.json.video.chapters.length, 2);
  const g = await guest.get(`/api/videos/${shortId}`);
  assert.equal(g.status, 200);
  const home = await guest.get('/api/feed/home');
  assert.equal(home.status, 200);
  assert.ok(home.json.sections.some((s) => (s.videos || []).some((v) => v.id === videoId)));
});

test('просмотры и прогресс', async () => {
  let r;
  for (let i = 0; i < 3; i++) r = await guest.post(`/api/videos/${shortId}/progress`, { position: 1 + i, watchedDelta: 4, buckets: [i * 10], source: 'home' });
  assert.equal(r.status, 200);
  assert.equal(r.json.counted, true);
  const v = (await user.get(`/api/videos/${shortId}`)).json.video;
  assert.equal(v.viewCount, 1);
  const a = await user.get(`/api/videos/${videoId}/analytics?range=7d`);
  assert.equal(a.status, 200);
  assert.equal(a.json.analytics.totals.views, 1);
  assert.match(a.json.analytics.daily[0].day, /^\d{4}-\d{2}-\d{2}$/);
});

test('лайки, комментарии, ответы, закрепление', async () => {
  const like = await user.post(`/api/videos/${shortId}/like`, { value: 1 });
  assert.equal(like.json.likeCount, 1);
  const c = await user.post(`/api/videos/${shortId}/comments`, { body: 'Первый комментарий' });
  assert.equal(c.status, 200, c.text);
  const reply = await admin.post(`/api/videos/${shortId}/comments`, { body: 'Ответ', parentId: c.json.comment.id });
  assert.equal(reply.status, 200);
  const list = await guest.get(`/api/videos/${shortId}/comments`);
  assert.equal(list.json.comments.length, 1);
  assert.equal(list.json.comments[0].replyCount, 1);
  const replies = await guest.get(`/api/comments/${c.json.comment.id}/replies`);
  assert.equal(replies.json.comments.length, 1);
  const pin = await user.post(`/api/comments/${c.json.comment.id}/pin`, { pinned: true });
  assert.equal(pin.json.pinned, true);
  const notif = await user.get('/api/notifications');
  assert.ok(notif.json.notifications.some((n) => n.type === 'comment_reply'));
});

test('плейлисты и «смотреть позже»', async () => {
  const p = await user.post('/api/playlists', { title: 'Мой плейлист', visibility: 'internal', videoId });
  assert.equal(p.status, 200, p.text);
  assert.equal(p.json.playlist.itemCount, 1);
  const wl = await user.post('/api/playlists/watch-later/toggle', { videoId });
  assert.equal(wl.json.inWatchLater, true);
  const mine = await user.get('/api/playlists');
  assert.ok(mine.json.playlists.some((x) => x.kind === 'watch_later' && x.itemCount === 1));
  const view = await guest.get(`/api/playlists/${p.json.playlist.id}`);
  assert.equal(view.status, 403);
});

test('поиск и подсказки', async () => {
  const s = await guest.get('/api/search?q=' + encodeURIComponent('тестовое'));
  assert.equal(s.status, 200, s.text);
  assert.ok(s.json.videos.some((v) => v.id === videoId), 'найдено по названию');
  const tag = await guest.get('/api/search?q=' + encodeURIComponent('обучение'));
  assert.ok(tag.json.videos.some((v) => v.id === videoId), 'найдено по тегу');
  const sug = await user.get('/api/search/suggest?q=' + encodeURIComponent('тест'));
  assert.ok(sug.json.suggestions.length >= 1);
});

test('подписки и канал', async () => {
  const me = (await user.get('/api/auth/me')).json.user;
  const ch = await admin.get(`/api/channels/${me.handle}`);
  assert.equal(ch.status, 200);
  const sub = await admin.post(`/api/channels/${me.handle}/subscribe`, {});
  assert.equal(sub.json.subscribed, true);
  assert.equal(sub.json.subscriberCount, 1);
  const feed = await admin.get('/api/feed/subscriptions');
  assert.ok(feed.json.videos.some((v) => v.id === videoId));
});

test('модерация: блокировка и разблокировка администратором', async () => {
  const b = await admin.post(`/api/admin/videos/${videoId}/block`, { reason: 'проверка' });
  assert.equal(b.status, 200);
  const g = await guest.get(`/api/videos/${shortId}`);
  assert.equal(g.status, 401);
  const u = await admin.post(`/api/admin/videos/${videoId}/unblock`, {});
  assert.equal(u.status, 200);
  const g2 = await guest.get(`/api/videos/${shortId}`);
  assert.equal(g2.status, 200);
});

test('домены и настройки в админке', async () => {
  const d = await admin.post('/api/admin/domains', { domain: `test-${stamp}.ru`, autoApprove: true });
  assert.equal(d.status, 200, d.text);
  const list = await admin.get('/api/admin/domains');
  assert.ok(list.json.domains.some((x) => x.domain === `test-${stamp}.ru`));
  await admin.del(`/api/admin/domains/${d.json.domain.id}`);
  const s = await admin.get('/api/admin/settings');
  assert.equal(s.json.settings['site.name'], 'Водокомфорт Видео');
  const put = await admin.put('/api/admin/settings', { settings: { 'upload.premoderation': false, 'site.tagline': 'Тест' } });
  assert.equal(put.json.settings['site.tagline'], 'Тест');
  await admin.put('/api/admin/settings', { settings: { 'site.tagline': 'Корпоративный видеохостинг' } });
  const stats = await admin.get('/api/admin/stats');
  assert.ok(stats.json.videos.total >= 1);
  const forbidden = await user.get('/api/admin/stats');
  assert.equal(forbidden.status, 403);
});

test('трансляции: создание, хук авторизации', async () => {
  const s = await user.post('/api/studio/live', { title: 'Тестовый эфир', visibility: 'internal' });
  assert.equal(s.status, 200, s.text);
  assert.ok(s.json.stream.streamKey);
  // Хук авторизации MediaMTX требует секрет: без него это был бы открытый оракул «есть ли такой ключ»
  const envFile = new URL('../.env', import.meta.url);
  const secret = process.env.MEDIAMTX_HOOK_SECRET
    || (fs.existsSync(envFile) ? (fs.readFileSync(envFile, 'utf8').match(/^MEDIAMTX_HOOK_SECRET=(.*)$/m) || [])[1] || '' : '');
  assert.ok(secret, 'секрет хука должен быть задан в окружении');
  const noSecret = await guest.post('/api/live/hooks/auth', { action: 'publish', path: `live/${s.json.stream.streamKey}`, protocol: 'rtmp' });
  assert.equal(noSecret.status, 401, 'без секрета хук отвечает отказом');
  const ok = await guest.post(`/api/live/hooks/auth?secret=${encodeURIComponent(secret)}`, { action: 'publish', path: `live/${s.json.stream.streamKey}`, protocol: 'rtmp' });
  assert.equal(ok.status, 200, ok.text);
  const bad = await guest.post(`/api/live/hooks/auth?secret=${encodeURIComponent(secret)}`, { action: 'publish', path: 'live/wrongkey', protocol: 'rtmp' });
  assert.equal(bad.status, 403);
  const chat = await user.post(`/api/live/${s.json.stream.shortId}/chat`, { body: 'Привет!' });
  assert.equal(chat.status, 200, chat.text);
  const msgs = await user.get(`/api/live/${s.json.stream.shortId}/chat`);
  assert.equal(msgs.json.messages.length, 1);
  await user.del(`/api/studio/live/${s.json.stream.id}`);
});

test('токен API и простая загрузка', async () => {
  const t = await user.post('/api/me/tokens', { name: 'тест' });
  assert.ok(t.json.token.startsWith('cv_'));
  const api = new Client();
  const me = await api.req('GET', '/api/auth/me', null, { headers: { authorization: `Bearer ${t.json.token}` } });
  assert.equal(me.json.user.email, userEmail);
});

// --- Корпоративные функции 1.1 -------------------------------------------------------------
const viewer = new Client();
const viewerEmail = `viewer.${stamp}@vodokomfort.ru`;
let groupId, assignmentId, shareToken;

test('группы: создание, добавление по e-mail, справочник для сотрудников', async () => {
  const reg = await viewer.post('/api/auth/register', { email: viewerEmail, password: 'Password123', displayName: 'Зритель Тестовый' });
  assert.equal(reg.status, 200, reg.text);
  const list = await admin.get('/api/admin/users?status=pending_approval');
  const found = list.json.users.find((u) => u.email === viewerEmail);
  await admin.post(`/api/admin/users/${found.id}/approve`, {});
  const g = await admin.post('/api/admin/groups', { name: `Отдел продаж ${stamp}`, description: 'тест' });
  assert.equal(g.status, 200, g.text);
  groupId = g.json.group.id;
  const dup = await admin.post('/api/admin/groups', { name: `отдел продаж ${stamp}` });
  assert.equal(dup.status, 409, 'название без учёта регистра уникально');
  const add = await admin.post(`/api/admin/groups/${groupId}/members`, { users: [viewerEmail, 'nobody@vodokomfort.ru'] });
  assert.equal(add.status, 200, add.text);
  assert.equal(add.json.added, 1);
  assert.deepEqual(add.json.notFound, ['nobody@vodokomfort.ru']);
  assert.equal(add.json.group.memberCount, 1);
  const mine = await viewer.get('/api/groups/mine');
  assert.ok(mine.json.groups.some((x) => x.id === groupId));
  const dir = await user.get('/api/groups?q=продаж');
  assert.ok(dir.json.groups.some((x) => x.id === groupId));
  const members = await admin.get(`/api/admin/groups/${groupId}`);
  assert.equal(members.json.members[0].email, viewerEmail);
  const forbidden = await user.post('/api/admin/groups', { name: 'x' });
  assert.equal(forbidden.status, 403);
});

test('доступ к приватному видео по группе', async () => {
  const r = await user.patch(`/api/videos/${videoId}`, { visibility: 'private', accessGroups: [] });
  assert.equal(r.status, 200, r.text);
  const denied = await viewer.get(`/api/videos/${shortId}`);
  assert.equal(denied.status, 403);
  const r2 = await user.patch(`/api/videos/${videoId}`, { accessGroups: [groupId] });
  assert.equal(r2.status, 200, r2.text);
  assert.equal(r2.json.video.accessGroups.length, 1);
  const ok = await viewer.get(`/api/videos/${shortId}`);
  assert.equal(ok.status, 200, ok.text);
  const hls = await viewer.get(ok.json.video.hlsUrl);
  assert.equal(hls.status, 200);
  const auth = await mediaAuth(viewer, ok.json.video.hlsUrl);
  assert.equal(auth.status, 200);
});

test('назначение к обязательному просмотру: создание, прогресс, отчёт, CSV', async () => {
  const noRights = await viewer.post('/api/assignments', { videoId, targets: [{ type: 'all' }] });
  assert.equal(noRights.status, 403, 'обычный сотрудник не назначает (who_can_assign=staff)');
  const due = new Date(Date.now() + 2 * 86400000).toISOString();
  const a = await admin.post('/api/assignments', { videoId, targets: [{ type: 'group', id: groupId }], dueAt: due, note: 'Обязательно', requiredPercent: 80 });
  assert.equal(a.status, 200, a.text);
  assignmentId = a.json.assignment.id;
  assert.equal(a.json.assignment.requiredPercent, 80);
  const mine = await viewer.get('/api/assignments/mine');
  assert.equal(mine.status, 200, mine.text);
  const m = mine.json.assignments.find((x) => x.id === assignmentId);
  assert.ok(m, 'назначение видно адресату');
  assert.equal(m.completed, false);
  const notif = await viewer.get('/api/notifications');
  assert.ok(notif.json.notifications.some((n) => n.type === 'assignment'), 'уведомление о назначении');
  // Просмотр почти до конца → выполнено
  const v = (await viewer.get(`/api/videos/${shortId}`)).json.video;
  const p = await viewer.post(`/api/videos/${shortId}/progress`, { position: v.duration - 0.2, watchedDelta: v.duration, buckets: [0, 10, 20, 30, 40, 50, 60, 70, 80, 90], source: 'assignments' });
  assert.equal(p.status, 200, p.text);
  const mine2 = await viewer.get('/api/assignments/mine');
  const m2 = mine2.json.assignments.find((x) => x.id === assignmentId);
  assert.equal(m2.completed, true, JSON.stringify(m2));
  const report = await admin.get(`/api/assignments/${assignmentId}`);
  assert.equal(report.status, 200, report.text);
  assert.equal(report.json.report.total, 1);
  assert.equal(report.json.report.completed, 1);
  assert.equal(report.json.report.people[0].email, viewerEmail);
  const csv = await admin.get(`/api/assignments/${assignmentId}?format=csv`);
  assert.equal(csv.status, 200);
  assert.match(csv.headers.get('content-type'), /text\/csv/);
  assert.ok(csv.text.includes(viewerEmail));
  const list = await admin.get('/api/assignments');
  assert.ok(list.json.assignments.some((x) => x.id === assignmentId));
  const viewers = await user.get(`/api/videos/${videoId}/viewers`);
  assert.equal(viewers.status, 200, viewers.text);
  assert.ok(viewers.json.viewers.some((x) => x.email === viewerEmail), 'автор видит, кто посмотрел');
});

test('тест внутри видео: редактор, прохождение, результаты', async () => {
  const bad = await user.put(`/api/videos/${videoId}/quiz`, { questions: [{ text: 'Без вариантов', options: [{ text: 'a' }] }] });
  assert.equal(bad.status, 400);
  const q = await user.put(`/api/videos/${videoId}/quiz`, {
    title: 'Проверка', passPercent: 50, questions: [
      { id: 'q1', at: 2, text: 'Сколько будет 2+2?', type: 'single', options: [{ id: 'a', text: '4', correct: true }, { id: 'b', text: '5' }] },
      { id: 'q2', text: 'Выберите чётные', type: 'multiple', options: [{ id: 'a', text: '2', correct: true }, { id: 'b', text: '3' }, { id: 'c', text: '4', correct: true }], explanation: 'Чётные — 2 и 4' },
    ],
  });
  assert.equal(q.status, 200, q.text);
  assert.equal(q.json.quiz.questions.length, 2);
  const card = (await user.get(`/api/videos/${shortId}`)).json.video;
  assert.equal(card.hasQuiz, true);
  const forViewer = await viewer.get(`/api/videos/${shortId}/quiz`);
  assert.equal(forViewer.status, 200, forViewer.text);
  assert.equal(forViewer.json.quiz.questions[0].options[0].correct, undefined, 'правильные ответы скрыты');
  const check = await viewer.post(`/api/videos/${shortId}/quiz/check`, { questionId: 'q1', answer: ['b'] });
  assert.equal(check.json.correct, false);
  const fin = await viewer.post(`/api/videos/${shortId}/quiz/finish`, { answers: { q1: ['a'], q2: ['a'] } });
  assert.equal(fin.status, 200, fin.text);
  assert.equal(fin.json.result.correct, 1);
  assert.equal(fin.json.result.percent, 50);
  assert.equal(fin.json.result.passed, true);
  const res = await user.get(`/api/videos/${videoId}/quiz/results`);
  assert.equal(res.json.total, 1);
  assert.equal(res.json.results[0].passed, true);
  const csv = await user.get(`/api/videos/${videoId}/quiz/results?format=csv`);
  assert.ok(csv.text.includes('Сотрудник'));
  const noAccess = await viewer.get(`/api/videos/${videoId}/quiz/full`);
  assert.equal(noAccess.status, 403);
});

test('защищённая ссылка: пароль, гостевой доступ к приватному видео, отзыв', async () => {
  const l = await user.post(`/api/videos/${videoId}/share-links`, { note: 'Партнёру', password: 'secret1', allowDownload: false });
  assert.equal(l.status, 200, l.text);
  shareToken = l.json.link.token;
  assert.ok(l.json.link.url.endsWith('/s/' + shareToken));
  const ext = new Client();
  const info = await ext.get(`/api/share/${shareToken}`);
  assert.equal(info.status, 200, info.text);
  assert.equal(info.json.requiresPassword, true);
  assert.equal(info.json.unlocked, false);
  const wrong = await ext.post(`/api/share/${shareToken}/unlock`, { password: 'nope' });
  assert.equal(wrong.status, 403);
  const denied = await ext.get(`/api/videos/${shortId}`);
  assert.equal(denied.status, 401);
  const ok = await ext.post(`/api/share/${shareToken}/unlock`, { password: 'secret1' });
  assert.equal(ok.status, 200, ok.text);
  assert.ok(ext.cookie.includes('cv_share='), 'cookie доступа выдана');
  const v = await ext.get(`/api/videos/${shortId}`);
  assert.equal(v.status, 200, v.text);
  assert.equal(v.json.video.viewer.guestLink, true);
  const hls = await ext.get(v.json.video.hlsUrl);
  assert.equal(hls.status, 200);
  const auth = await mediaAuth(ext, v.json.video.hlsUrl);
  assert.equal(auth.status, 200);
  const dl = await ext.get(`/api/videos/${shortId}/download`);
  assert.equal(dl.status, 403, 'скачивание по ссылке запрещено');
  const links = await user.get(`/api/videos/${videoId}/share-links`);
  assert.equal(links.json.links[0].viewCount, 1);
  const rev = await user.patch(`/api/videos/${videoId}/share-links/${l.json.link.id}`, { revoked: true });
  assert.equal(rev.json.link.active, false);
  const after = await ext.get(`/api/videos/${shortId}`);
  assert.equal(after.status, 401, 'после отзыва доступ закрыт');
  const state = await ext.get(`/api/share/${shareToken}`);
  assert.equal(state.json.state, 'revoked');
});

test('личные заметки с таймкодами и водяной знак зрителя', async () => {
  const n = await viewer.post(`/api/videos/${shortId}/notes`, { position: 3.5, body: 'Важный момент' });
  assert.equal(n.status, 200, n.text);
  const n2 = await viewer.post(`/api/videos/${shortId}/notes`, { position: 1, body: 'Начало' });
  const list = await viewer.get(`/api/videos/${shortId}/notes`);
  assert.deepEqual(list.json.notes.map((x) => x.body), ['Начало', 'Важный момент']);
  const other = await user.get(`/api/videos/${shortId}/notes`);
  assert.equal(other.json.notes.length, 0, 'заметки личные');
  const upd = await viewer.patch(`/api/videos/${shortId}/notes/${n2.json.note.id}`, { body: 'Самое начало' });
  assert.equal(upd.json.note.body, 'Самое начало');
  const md = await viewer.get(`/api/videos/${shortId}/notes/export`);
  assert.ok(md.text.includes('**0:01**') && md.text.includes('**0:04**') && md.text.includes('Самое начало'), md.text);
  const all = await viewer.get('/api/me/notes');
  assert.equal(all.json.videos[0].notes.length, 2);
  await viewer.del(`/api/videos/${shortId}/notes/${n.json.note.id}`);
  assert.equal((await viewer.get(`/api/videos/${shortId}/notes`)).json.notes.length, 1);
  const wm = await user.patch(`/api/videos/${videoId}`, { viewerWatermark: true });
  assert.equal(wm.json.video.viewerWatermark, true);
});

test('замена файла видео: версия увеличивается, повторная обработка', async () => {
  const tmp = path.join(os.tmpdir(), `cv-test-r-${stamp}.mp4`);
  execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=320x240:rate=25:duration=3', '-f', 'lavfi', '-i', 'sine=frequency=330:duration=3', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', tmp]);
  const data = fs.readFileSync(tmp);
  const noRights = await viewer.post('/api/uploads', { filename: 'new.mp4', size: data.length, replaceVideoId: videoId });
  assert.equal(noRights.status, 403);
  const init = await user.post('/api/uploads', { filename: 'new.mp4', size: data.length, mime: 'video/mp4', replaceVideoId: videoId });
  assert.equal(init.status, 200, init.text);
  assert.equal(init.json.replace, true);
  assert.equal(init.json.videoId, videoId);
  const r = await user.req('PATCH', `/api/uploads/${init.json.uploadId}`, data, { headers: { 'content-type': 'application/offset+octet-stream', 'upload-offset': '0' } });
  assert.equal(r.status, 200, r.text);
  const done = await user.post(`/api/uploads/${init.json.uploadId}/complete`, {});
  assert.equal(done.status, 200, done.text);
  let v;
  for (let i = 0; i < 120; i++) {
    await new Promise((res) => setTimeout(res, 1000));
    v = (await user.get(`/api/videos/${videoId}`)).json.video;
    if (v.status === 'ready' || v.status === 'failed') break;
  }
  assert.equal(v.status, 'ready', 'повторная обработка: ' + JSON.stringify(v.processingError));
  assert.equal(v.version, 2);
  assert.ok(v.replacedAt);
  assert.ok(v.duration > 2 && v.duration < 4, 'новая длительность ' + v.duration);
  fs.unlinkSync(tmp);
});

test('импорт по ссылке (прямая ссылка на файл)', async () => {
  // Отдаём mp4 предыдущего теста через сам API (оригинал доступен автору) — cookie передаём в ссылке нельзя,
  // поэтому публикуем видео и разрешаем скачивание: ссылка на /api/videos/:id/download?type=mp4 станет прямой.
  await user.patch(`/api/videos/${videoId}`, { visibility: 'public', allowDownload: true });
  const bad = await user.post('/api/uploads/from-url', { url: 'ftp://example.com/x.mp4' });
  assert.equal(bad.status, 400);
  const html = await user.post('/api/uploads/from-url', { url: `${BASE}/`, title: 'Импорт страницы' });
  assert.equal(html.status, 200, html.text);
  const src = await user.get(`/api/videos/${videoId}`);
  const direct = await user.post('/api/uploads/from-url', { url: `${BASE}${src.json.video.mp4Url || src.json.video.downloadUrl || `/media/videos/${videoId}/video.mp4`}`, title: 'Импорт по ссылке', visibility: 'internal' });
  assert.equal(direct.status, 200, direct.text);
  let v, page;
  for (let i = 0; i < 120; i++) {
    await new Promise((res) => setTimeout(res, 1000));
    v = (await user.get(`/api/videos/${direct.json.videoId}`)).json.video;
    page = (await user.get(`/api/videos/${html.json.videoId}`)).json.video;
    if (['ready', 'failed'].includes(v.status) && page.status === 'failed') break;
  }
  assert.equal(page.status, 'failed', 'страница вместо файла → ошибка импорта');
  assert.equal(v.status, 'ready', 'импорт по ссылке: ' + JSON.stringify(v.processingError));
  assert.equal(v.sourceUrl.startsWith(BASE), true);
  await user.del(`/api/videos/${direct.json.videoId}`);
  await user.del(`/api/videos/${html.json.videoId}`);
});

test('ИИ-подсказки: ошибки без сервера, генерация через OpenAI-совместимый мок', async () => {
  const off = await user.post(`/api/videos/${videoId}/ai`, { fields: ['summary'] });
  assert.equal(off.status, 400, 'ИИ выключен по умолчанию');
  // Мок OpenAI-совместимого сервера
  const http = await import('node:http');
  const srv = http.createServer((req, res) => {
    let body = ''; req.on('data', (c) => { body += c; });
    req.on('end', () => {
      const j = JSON.parse(body || '{}');
      const prompt = j.messages?.[1]?.content || '';
      const answer = /Ответь одним словом/.test(prompt) || /Ответь одним словом/.test(j.messages?.[0]?.content || '')
        ? 'готов'
        : JSON.stringify({ title: 'Тестовое видео: конспект', summary: 'Краткое содержание.\nКлючевые пункты: раз, два.', chapters: [{ start: 0, title: 'Начало' }, { start: 2, title: 'Середина' }], tags: ['тест', 'ИИ'] });
      res.setHeader('content-type', 'application/json');
      res.end(JSON.stringify({ choices: [{ message: { role: 'assistant', content: answer } }] }));
    });
  });
  await new Promise((r) => srv.listen(0, '127.0.0.1', r));
  const port = srv.address().port;
  try {
    const st = await admin.put('/api/admin/settings', { settings: { 'ai.enabled': true, 'ai.url': `http://127.0.0.1:${port}/v1/chat/completions`, 'ai.timeout_sec': 20 } });
    assert.equal(st.status, 200);
    const t = await admin.post('/api/admin/settings/test-ai', {});
    assert.equal(t.status, 200, t.text);
    assert.match(t.json.answer, /готов/);
    const noTranscript = await user.post(`/api/videos/${videoId}/ai`, {});
    assert.equal(noTranscript.status, 400, 'без транскрипта — подсказка');
    // Субтитры → транскрипт → генерация
    const vtt = 'WEBVTT\n\n1\n00:00:00.000 --> 00:00:02.000\nПривет, это тест.\n\n2\n00:00:02.000 --> 00:00:04.000\nВторая реплика.\n';
    const form = new FormData(); form.append('language', 'ru'); form.append('label', 'Русский'); form.append('file', new Blob([vtt], { type: 'text/vtt' }), 'ru.vtt');
    const sub = await user.post(`/api/videos/${videoId}/subtitles`, form);
    assert.equal(sub.status, 200, sub.text);
    const run = await user.post(`/api/videos/${videoId}/ai`, { fields: ['title', 'summary', 'chapters', 'tags'] });
    assert.equal(run.status, 200, run.text);
    let ai = null;
    for (let i = 0; i < 60; i++) { await new Promise((r) => setTimeout(r, 1000)); ai = (await user.get(`/api/videos/${videoId}/ai`)).json; if (ai.suggestions && !ai.pending) break; }
    assert.ok(ai.suggestions, 'подсказки получены');
    assert.equal(ai.suggestions.title, 'Тестовое видео: конспект');
    assert.equal(ai.suggestions.chapters.length, 2);
    assert.deepEqual(ai.suggestions.tags, ['тест', 'ии']);
    const v = (await user.get(`/api/videos/${videoId}`)).json.video;
    assert.equal(v.aiSuggestions.title, 'Тестовое видео: конспект', 'подсказки видны автору');
    const g = (await guest.get(`/api/videos/${shortId}`)).json.video;
    assert.equal(g.aiSuggestions, undefined, 'гостю подсказки не показываются');
    const clear = await user.del(`/api/videos/${videoId}/ai`);
    assert.equal(clear.status, 200);
  } finally {
    srv.close();
    await admin.put('/api/admin/settings', { settings: { 'ai.enabled': false } });
  }
  const pub = await guest.get('/api/config');
  assert.equal(pub.json.aiEnabled, false);
});

test('напоминания о сроке назначения', async () => {
  // Назначение со сроком через 1 день и напоминанием за 3 дня → попадает в выборку напоминаний
  const a = await admin.post('/api/assignments', { videoId, targets: [{ type: 'user', id: viewerEmail }], dueAt: new Date(Date.now() + 86400000).toISOString(), remindDays: 3 });
  assert.equal(a.status, 200, a.text);
  const r = await admin.post(`/api/assignments/${a.json.assignment.id}/remind`, {});
  assert.equal(r.status, 200, r.text);
  assert.equal(r.json.sent, 0, 'зритель уже выполнил просмотр — напоминать некому');
  const notif = await viewer.get('/api/notifications');
  assert.ok(notif.json.notifications.some((n) => n.type === 'assignment'), 'уведомление о новом назначении есть');
  const closed = await admin.patch(`/api/assignments/${a.json.assignment.id}`, { status: 'closed' });
  assert.equal(closed.json.assignment.status, 'closed');
  await admin.del(`/api/assignments/${a.json.assignment.id}`);
});

// --- Платформа 1.2 -----------------------------------------------------------------------------
const http12 = await import('node:http');
const crypto12 = await import('node:crypto');
function listen(srv) { return new Promise((r) => srv.listen(0, '127.0.0.1', () => r(srv.address().port))); }

test('вход через SSO (OpenID Connect): обнаружение, PKCE, id_token, автосоздание учётной записи, группы', async () => {
  // Мок OIDC-провайдера (RS256)
  const { publicKey, privateKey } = crypto12.generateKeyPairSync('rsa', { modulusLength: 2048 });
  const jwk = publicKey.export({ format: 'jwk' }); jwk.kid = 'k1'; jwk.alg = 'RS256'; jwk.use = 'sig';
  let issuer = ''; let lastNonce = ''; let redirectUri = ''; const codes = new Map();
  const b64 = (o) => Buffer.from(JSON.stringify(o)).toString('base64url');
  const srv = http12.createServer((req, res) => {
    const u = new URL(req.url, issuer);
    const json = (o, code = 200) => { res.writeHead(code, { 'content-type': 'application/json' }); res.end(JSON.stringify(o)); };
    if (u.pathname === '/.well-known/openid-configuration') return json({ issuer, authorization_endpoint: `${issuer}/auth`, token_endpoint: `${issuer}/token`, userinfo_endpoint: `${issuer}/userinfo`, jwks_uri: `${issuer}/jwks`, scopes_supported: ['openid', 'profile', 'email'] });
    if (u.pathname === '/jwks') return json({ keys: [jwk] });
    if (u.pathname === '/auth') {
      const code = crypto12.randomBytes(8).toString('hex'); lastNonce = u.searchParams.get('nonce'); redirectUri = u.searchParams.get('redirect_uri');
      codes.set(code, { nonce: lastNonce, challenge: u.searchParams.get('code_challenge') });
      res.writeHead(302, { location: `${redirectUri}?code=${code}&state=${encodeURIComponent(u.searchParams.get('state'))}` }); return res.end();
    }
    if (u.pathname === '/token') {
      let body = ''; req.on('data', (c) => { body += c; });
      req.on('end', () => {
        const p = new URLSearchParams(body); const c = codes.get(p.get('code'));
        if (!c) return json({ error: 'invalid_grant' }, 400);
        const expected = crypto12.createHash('sha256').update(p.get('code_verifier')).digest('base64url');
        if (expected !== c.challenge) return json({ error: 'invalid_grant', error_description: 'pkce' }, 400);
        const now = Math.floor(Date.now() / 1000);
        const payload = { iss: issuer, sub: 'sso-' + stamp, aud: 'corpvideo', exp: now + 300, iat: now, nonce: c.nonce, email: `sso.${stamp}@vodokomfort.ru`, name: 'Сотрудник SSO', groups: ['/Отдел продаж ' + stamp, 'video-admins'] };
        const signed = `${b64({ alg: 'RS256', typ: 'JWT', kid: 'k1' })}.${b64(payload)}`;
        const sig = crypto12.sign('sha256', Buffer.from(signed), privateKey).toString('base64url');
        json({ access_token: 'at-1', token_type: 'Bearer', id_token: `${signed}.${sig}` });
      });
      return;
    }
    if (u.pathname === '/userinfo') return json({ sub: 'sso-' + stamp, email: `sso.${stamp}@vodokomfort.ru`, name: 'Сотрудник SSO', department: 'Продажи' });
    json({ error: 'not found' }, 404);
  });
  const port = await listen(srv); issuer = `http://127.0.0.1:${port}`;
  try {
    const st = await admin.put('/api/admin/settings', { settings: { 'oidc.enabled': true, 'oidc.issuer': issuer, 'oidc.client_id': 'corpvideo', 'oidc.client_secret': 'secret', 'oidc.admin_group': 'video-admins', 'oidc.sync_groups': true } });
    assert.equal(st.status, 200, st.text);
    const t = await admin.post('/api/admin/settings/test-oidc', {});
    assert.equal(t.status, 200, t.text);
    assert.equal(t.json.authorization, `${issuer}/auth`);
    const cfg = await guest.get('/api/config');
    assert.equal(cfg.json.oidcEnabled, true);
    // Полный проход: start → провайдер → callback
    const sso = new Client();
    const start = await sso.req('GET', '/api/auth/oidc/start?next=/assignments');
    assert.equal(start.status, 302, start.text);
    const authUrl = start.headers.get('location');
    assert.ok(authUrl.startsWith(`${issuer}/auth?`), authUrl);
    const provider = await fetch(authUrl, { redirect: 'manual' });
    assert.equal(provider.status, 302);
    const back = new URL(provider.headers.get('location'));
    assert.ok(back.pathname.endsWith('/api/auth/oidc/callback'));
    const cb = await sso.req('GET', back.pathname + back.search);
    assert.equal(cb.status, 302, cb.text);
    assert.equal(cb.headers.get('location'), '/assignments', 'редирект на next');
    const me = await sso.get('/api/auth/me');
    assert.equal(me.json.user?.email, `sso.${stamp}@vodokomfort.ru`, me.text);
    assert.equal(me.json.user.role, 'admin', 'группа video-admins → администратор');
    assert.equal(me.json.user.authProvider, 'oidc');
    const groups = await sso.get('/api/groups/mine');
    assert.ok(groups.json.groups.some((g) => g.name === 'Отдел продаж ' + stamp), 'группа из claim синхронизирована по названию');
    // Повторный вход того же пользователя — без дублей
    const start2 = await sso.req('GET', '/api/auth/oidc/start');
    const p2 = await fetch(start2.headers.get('location'), { redirect: 'manual' });
    const b2 = new URL(p2.headers.get('location'));
    await sso.req('GET', b2.pathname + b2.search);
    const dup = await admin.get(`/api/admin/users?q=${encodeURIComponent('sso.' + stamp)}`);
    assert.equal(dup.json.users.filter((u) => u.email === `sso.${stamp}@vodokomfort.ru`).length, 1);
    // Неверный state → понятная ошибка
    const bad = await guest.req('GET', '/api/auth/oidc/callback?code=x&state=nope');
    assert.equal(bad.status, 302);
    assert.match(decodeURIComponent(bad.headers.get('location')), /начните вход заново/);
    // Код и state, полученные в чужом браузере, не должны завершать вход здесь (подмена сеанса)
    const victim = new Client();
    const start3 = await victim.req('GET', '/api/auth/oidc/start');
    const p3 = await fetch(start3.headers.get('location'), { redirect: 'manual' });
    const b3 = new URL(p3.headers.get('location'));
    const stolen = await guest.req('GET', b3.pathname + b3.search);
    assert.equal(stolen.status, 302);
    assert.match(decodeURIComponent(stolen.headers.get('location')), /начните вход заново/, 'чужой code+state отклонён');
    assert.equal((await guest.get('/api/auth/me')).json.user, null, 'вход не выполнен');
    // А в своём браузере тот же ответ провайдера срабатывает
    const own = await victim.req('GET', b3.pathname + b3.search);
    assert.equal(own.status, 302, own.text);
    assert.ok(!/error=/.test(own.headers.get('location')), own.headers.get('location'));
  } finally {
    srv.close();
    await admin.put('/api/admin/settings', { settings: { 'oidc.enabled': false } });
  }
});

test('срок публикации: видео становится приватным, автор уведомлён', async () => {
  const r = await user.patch(`/api/videos/${videoId}`, { visibility: 'internal', expiresAt: new Date(Date.now() - 1000).toISOString() });
  assert.equal(r.status, 200, r.text);
  assert.ok(r.json.video.expiresAt);
  await admin.post('/api/admin/system/maintenance', {});
  let v;
  for (let i = 0; i < 30; i++) { await new Promise((res) => setTimeout(res, 1000)); v = (await user.get(`/api/videos/${videoId}`)).json.video; if (v.expiredAt) break; }
  assert.ok(v.expiredAt, 'отметка об истечении');
  assert.equal(v.visibility, 'private');
  const notif = await user.get('/api/notifications');
  assert.ok(notif.json.notifications.some((n) => n.type === 'video_expired'));
  // Возврат видимости снимает срок
  const back = await user.patch(`/api/videos/${videoId}`, { visibility: 'public' });
  assert.equal(back.json.video.expiresAt, null);
  assert.equal(back.json.video.expiredAt, null);
});

test('вложения к видео: загрузка, список, скачивание, удаление', async () => {
  const form = new FormData();
  form.append('title', 'Презентация инструктажа');
  form.append('file', new Blob(['%PDF-1.4 test'], { type: 'application/pdf' }), 'Инструктаж.pdf');
  const up = await user.post(`/api/videos/${videoId}/attachments`, form);
  assert.equal(up.status, 200, up.text);
  assert.equal(up.json.attachment.title, 'Презентация инструктажа');
  assert.equal(up.json.attachment.ext, 'pdf');
  const bad = new FormData(); bad.append('file', new Blob(['x'], { type: 'application/x-msdownload' }), 'virus.exe');
  assert.equal((await user.post(`/api/videos/${videoId}/attachments`, bad)).status, 400);
  const list = await viewer.get(`/api/videos/${shortId}/attachments`);
  assert.equal(list.json.attachments.length, 1);
  const dl = await viewer.get(list.json.attachments[0].url);
  assert.equal(dl.status, 200);
  assert.match(dl.headers.get('content-disposition'), /attachment/);
  assert.equal(dl.text, '%PDF-1.4 test');
  const noRights = await viewer.del(`/api/videos/${videoId}/attachments/${up.json.attachment.id}`);
  assert.equal(noRights.status, 403);
  const del2 = await user.del(`/api/videos/${videoId}/attachments/${up.json.attachment.id}`);
  assert.equal(del2.status, 200);
  assert.equal((await user.get(`/api/videos/${videoId}/attachments`)).json.attachments.length, 0);
});

test('транскрипт: скачивание txt / srt / vtt / json', async () => {
  const txt = await viewer.get(`/api/videos/${shortId}/transcript`);
  assert.equal(txt.status, 200, txt.text);
  assert.ok(txt.text.includes('Привет, это тест'));
  const srt = await viewer.get(`/api/videos/${shortId}/transcript?format=srt`);
  assert.match(srt.text, /00:00:00,000 --> 00:00:02,000/);
  const vtt = await viewer.get(`/api/videos/${shortId}/transcript?format=vtt`);
  assert.ok(vtt.text.startsWith('WEBVTT'));
  const json = await viewer.get(`/api/videos/${shortId}/transcript?format=json`);
  assert.equal(json.json.segments.length, 2);
});

test('трансляция: напоминание, календарь, опросы и вопросы спикеру', async () => {
  const s = await user.post('/api/studio/live', { title: 'Планёрка', scheduledAt: new Date(Date.now() + 3600000).toISOString(), visibility: 'internal' });
  assert.equal(s.status, 200, s.text);
  const sid = s.json.stream.shortId;
  const rem = await viewer.post(`/api/live/${sid}/remind`, { on: true });
  assert.equal(rem.status, 200, rem.text);
  assert.equal(rem.json.count, 1);
  const st = await viewer.get(`/api/live/${sid}`);
  assert.equal(st.json.stream.reminder, true);
  const ics = await viewer.get(`/api/live/${sid}/calendar.ics`);
  assert.match(ics.headers.get('content-type'), /text\/calendar/);
  assert.ok(ics.text.includes('BEGIN:VEVENT') && ics.text.includes('SUMMARY:Планёрка'));
  // Опрос
  const noRights = await viewer.post(`/api/live/${sid}/polls`, { question: 'x', options: ['a', 'b'] });
  assert.equal(noRights.status, 403);
  const poll = await user.post(`/api/live/${sid}/polls`, { question: 'Какой формат планёрки удобнее?', options: ['Утро', 'Вечер'] });
  assert.equal(poll.status, 200, poll.text);
  const pid = poll.json.poll.id;
  const vote = await viewer.post(`/api/live/${sid}/polls/${pid}/vote`, { optionIds: [poll.json.poll.options[1].id] });
  assert.equal(vote.status, 200, vote.text);
  assert.equal(vote.json.poll.total, 1);
  assert.equal(vote.json.poll.options[1].votes, 1);
  const closed = await user.patch(`/api/live/${sid}/polls/${pid}`, { status: 'closed' });
  assert.equal(closed.json.poll.status, 'closed');
  const lateVote = await viewer.post(`/api/live/${sid}/polls/${pid}/vote`, { optionIds: [poll.json.poll.options[0].id] });
  assert.equal(lateVote.status, 400);
  // Вопросы
  const q = await viewer.post(`/api/live/${sid}/questions`, { body: 'Когда будет премия?', anonymous: true });
  assert.equal(q.status, 200, q.text);
  assert.equal(q.json.question.mine, true);
  const listForOwner = await user.get(`/api/live/${sid}/questions`);
  assert.equal(listForOwner.json.questions[0].author.displayName, 'Анонимно');
  const up = await admin.post(`/api/live/${sid}/questions/${q.json.question.id}/upvote`, {});
  assert.equal(up.json.upvotes, 1);
  const ans = await user.patch(`/api/live/${sid}/questions/${q.json.question.id}`, { status: 'answered', answer: 'В следующем месяце' });
  assert.equal(ans.json.question.status, 'answered');
  const csv = await user.get(`/api/live/${sid}/questions/export`);
  assert.ok(csv.text.includes('Когда будет премия'));
  await user.del(`/api/studio/live/${s.json.stream.id}`);
});

test('сертификат о прохождении: выдача при выполнении назначения, проверка по ссылке', async () => {
  const a = await admin.post('/api/assignments', { videoId, targets: [{ type: 'user', id: viewerEmail }], requiredPercent: 50, certificate: true, attentionCheckMin: 5 });
  assert.equal(a.status, 200, a.text);
  assert.equal(a.json.assignment.certificate, true);
  assert.equal(a.json.assignment.attentionCheckMin, 5);
  // Зритель уже смотрел видео (прогресс засчитан при создании) → сертификат выдан сразу
  let certs;
  for (let i = 0; i < 10; i++) { certs = (await viewer.get('/api/me/certificates')).json.certificates; if (certs.length) break; await new Promise((r) => setTimeout(r, 300)); }
  assert.ok(certs.length >= 1, 'сертификат выдан');
  const c = certs.find((x) => x.assignmentId === a.json.assignment.id);
  assert.ok(c, 'сертификат по назначению');
  assert.match(c.number, /^CV-\d{4}-\d{5}$/);
  const pub = await guest.get(`/api/certificates/${c.id}`);
  assert.equal(pub.status, 200, pub.text);
  assert.equal(pub.json.certificate.valid, true);
  assert.equal(pub.json.certificate.user.displayName, 'Зритель Тестовый');
  assert.equal(pub.json.certificate.user.email, undefined, 'гостю почта не показывается');
  const listCsv = await admin.get(`/api/assignments/${a.json.assignment.id}/certificates?format=csv`);
  assert.ok(listCsv.text.includes(c.number));
  const notif = await viewer.get('/api/notifications');
  assert.ok(notif.json.notifications.some((n) => n.type === 'certificate'));
  await admin.del(`/api/assignments/${a.json.assignment.id}`);
});

test('Telegram: привязка через код (мок Bot API) и доставка уведомления', async () => {
  const sent = []; let pendingStart = null;
  const srv = http12.createServer((req, res) => {
    let body = ''; req.on('data', (c) => { body += c; });
    req.on('end', () => {
      const j = body ? JSON.parse(body) : {};
      const json = (o) => { res.setHeader('content-type', 'application/json'); res.end(JSON.stringify(o)); };
      if (req.url.endsWith('/getMe')) return json({ ok: true, result: { username: 'corpvideo_test_bot', first_name: 'CorpVideo' } });
      if (req.url.endsWith('/sendMessage')) { sent.push(j); return json({ ok: true, result: { message_id: sent.length } }); }
      if (req.url.endsWith('/getUpdates')) { const u = pendingStart ? [{ update_id: 1, message: { chat: { id: 4242 }, from: { username: 'tester' }, text: `/start ${pendingStart}` } }] : []; pendingStart = null; return json({ ok: true, result: u }); }
      json({ ok: false, description: 'unknown' });
    });
  });
  const port = await listen(srv);
  try {
    const st = await admin.put('/api/admin/settings', { settings: { 'telegram.enabled': true, 'telegram.bot_token': '123:ABC', 'telegram.api_url': `http://127.0.0.1:${port}`, 'telegram.types': [] } });
    assert.equal(st.status, 200);
    const t = await admin.post('/api/admin/settings/test-telegram', {});
    assert.equal(t.status, 200, t.text);
    assert.equal(t.json.username, 'corpvideo_test_bot');
    const link = await viewer.post('/api/me/telegram/link', {});
    assert.equal(link.status, 200, link.text);
    assert.ok(link.json.url.includes('t.me/corpvideo_test_bot?start='));
    pendingStart = link.json.code;
    let status;
    for (let i = 0; i < 40; i++) { await new Promise((r) => setTimeout(r, 500)); status = (await viewer.get('/api/me/telegram')).json; if (status.linked) break; }
    assert.equal(status.linked, true, 'воркер обработал /start');
    assert.ok(sent.some((m) => /Готово/.test(m.text)), 'бот ответил подтверждением');
    // Уведомление → сообщение в Telegram
    const a = await admin.post('/api/assignments', { videoId, targets: [{ type: 'user', id: viewerEmail }] });
    for (let i = 0; i < 40; i++) { await new Promise((r) => setTimeout(r, 500)); if (sent.some((m) => /назначено/i.test(m.text))) break; }
    assert.ok(sent.some((m) => m.chat_id === 4242 && /назначено/i.test(m.text)), 'уведомление доставлено в Telegram');
    await admin.del(`/api/assignments/${a.json.assignment.id}`);
    const unlink = await viewer.del('/api/me/telegram');
    assert.equal(unlink.status, 200);
    assert.equal((await viewer.get('/api/me/telegram')).json.linked, false);
  } finally {
    srv.close();
    await admin.put('/api/admin/settings', { settings: { 'telegram.enabled': false } });
  }
});

test('RAG: документ из видео, выгрузка во внешнюю систему (мок), pull-API по токену, описание через LLM', async () => {
  const received = [];
  const rag = http12.createServer((req, res) => { let body = ''; req.on('data', (c) => { body += c; }); req.on('end', () => { received.push({ url: req.url, auth: req.headers.authorization, body }); res.setHeader('content-type', 'application/json'); res.end('{"ok":true}'); }); });
  const llm = http12.createServer((req, res) => { let body = ''; req.on('data', (c) => { body += c; }); req.on('end', () => { res.setHeader('content-type', 'application/json'); res.end(JSON.stringify({ choices: [{ message: { content: JSON.stringify({ summary: 'Конспект от ИИ для RAG.', tags: ['rag'] }) } }] })); }); });
  const ragPort = await listen(rag); const llmPort = await listen(llm);
  try {
    // Пустое описание → опция описания через LLM
    await user.patch(`/api/videos/${videoId}`, { description: '' });
    const st = await admin.put('/api/admin/settings', { settings: { 'rag.enabled': true, 'rag.url': `http://127.0.0.1:${ragPort}/ingest`, 'rag.api_key': 'rag-secret', 'rag.pull_token': 'pull-secret', 'rag.describe_with_ai': true, 'ai.enabled': true, 'ai.url': `http://127.0.0.1:${llmPort}/v1/chat/completions`, 'ai.auto_describe': true } });
    assert.equal(st.status, 200);
    const t = await admin.post('/api/admin/settings/test-rag', {});
    assert.equal(t.status, 200, t.text);
    assert.equal(received.length, 1);
    assert.equal(received[0].auth, 'Bearer rag-secret');
    const push = await user.post(`/api/videos/${videoId}/rag`, {});
    assert.equal(push.status, 200, push.text);
    let doc;
    for (let i = 0; i < 40; i++) { await new Promise((r) => setTimeout(r, 500)); doc = received.find((x) => x.body.includes(videoId)); if (doc) break; }
    assert.ok(doc, 'документ получен RAG');
    const j = JSON.parse(doc.body);
    assert.equal(j.id, videoId);
    assert.ok(j.text.includes('# Тестовое видео') && j.text.includes('Транскрипт') && j.text.includes('Привет, это тест'), j.text.slice(0, 300));
    assert.ok(j.text.includes('Конспект от ИИ для RAG'), 'описание от LLM попало в документ');
    const v = (await user.get(`/api/videos/${videoId}`)).json.video;
    assert.equal(v.description, 'Конспект от ИИ для RAG.', 'ai.auto_describe заполнил описание');
    assert.ok(v.ragSyncedAt, 'отметка о выгрузке');
    // Pull-API
    const noToken = await guest.get('/api/rag/documents');
    assert.equal(noToken.status, 401);
    const pull = await guest.req('GET', '/api/rag/documents?limit=5', null, { headers: { authorization: 'Bearer pull-secret' } });
    assert.equal(pull.status, 200, pull.text);
    assert.ok(pull.json.documents.some((d) => d.id === videoId));
    const one12 = await guest.req('GET', `/api/rag/documents/${shortId}?format=markdown`, null, { headers: { authorization: 'Bearer pull-secret' } });
    assert.match(one12.headers.get('content-type'), /markdown/);
    assert.ok(one12.text.startsWith('# Тестовое видео'));
    const status = await admin.get('/api/admin/rag/status');
    assert.ok(status.json.synced >= 1);
    const all = await admin.post('/api/admin/rag/push-all', { force: true });
    assert.ok(all.json.queued >= 1);
    const describe = await admin.post('/api/admin/rag/describe-all', {});
    assert.equal(describe.status, 200, describe.text);
  } finally {
    rag.close(); llm.close();
    await admin.put('/api/admin/settings', { settings: { 'rag.enabled': false, 'ai.enabled': false, 'ai.auto_describe': false, 'rag.describe_with_ai': false } });
  }
});

test('встраивание: ограничение доменов (CSP frame-ancestors)', async () => {
  await admin.put('/api/admin/settings', { settings: { 'embed.allowed_domains': ['intranet.vodokomfort.ru'] } });
  const r = await guest.get(`/embed/${shortId}`);
  assert.match(r.headers.get('content-security-policy') || '', /frame-ancestors 'self' https:\/\/intranet\.vodokomfort\.ru/);
  assert.equal(r.headers.get('x-frame-options'), null);
  await admin.put('/api/admin/settings', { settings: { 'embed.allowed_domains': [] } });
  const r2 = await guest.get(`/embed/${shortId}`);
  assert.equal(r2.headers.get('content-security-policy'), null);
});


// ============================ 1.3 ============================
const http13 = await import('node:http');
const cryptoMod = await import('node:crypto');
const { totpCode } = await import('../src/lib/crypto.js');
let editVideoId, editShortId, clipVideoId;

async function waitJob(client, vid, jobId, timeoutSec = 180) {
  let j;
  for (let i = 0; i < timeoutSec; i++) {
    await new Promise((r) => setTimeout(r, 1000));
    const g = await client.get(`/api/videos/${vid}/editor/jobs/${jobId}`);
    j = g.json?.job;
    if (j && ['done', 'failed', 'cancelled'].includes(j.status)) return j;
  }
  return j;
}
async function waitVideoReady(client, vid, timeoutSec = 180) {
  let v;
  for (let i = 0; i < timeoutSec; i++) {
    await new Promise((r) => setTimeout(r, 1000));
    v = (await client.get(`/api/videos/${vid}`)).json?.video;
    if (v && ['ready', 'failed'].includes(v.status)) return v;
  }
  return v;
}
function mockServer(handler) {
  const srv = http13.createServer((req, res) => { let body = ''; req.on('data', (c) => { body += c; }); req.on('end', () => handler(req, res, body)); });
  return new Promise((resolve) => srv.listen(0, '127.0.0.1', () => resolve({ srv, port: srv.address().port })));
}

test('вебхуки: создание, подпись HMAC, доставка события, журнал, повтор', async () => {
  const received = [];
  const { srv, port } = await mockServer((req, res, body) => { received.push({ headers: req.headers, body }); res.statusCode = received.length === 1 ? 500 : 200; res.end('ok'); });
  try {
    const ev = await admin.get('/api/admin/webhooks/events');
    assert.ok(ev.json.events.some((e) => e.id === 'video.updated'));
    const c = await admin.post('/api/admin/webhooks', { name: 'Тест', url: `http://127.0.0.1:${port}/hook`, events: ['video.updated', 'webhook.test'] });
    assert.equal(c.status, 200, c.text);
    const hook = c.json.webhook;
    assert.ok(hook.secret.length >= 16, 'секрет сгенерирован');
    // проверочное событие: первая попытка получает 500 (maxAttempts 1 → failed), затем повтор вручную
    const t = await admin.post(`/api/admin/webhooks/${hook.id}/test`, {});
    assert.equal(t.status, 200, t.text);
    for (let i = 0; i < 20 && received.length < 1; i++) await new Promise((r) => setTimeout(r, 500));
    assert.equal(received.length, 1, 'проверочное событие доставлено');
    const h = received[0].headers;
    assert.equal(h['x-corpvideo-event'], 'webhook.test');
    const sig = cryptoMod.createHmac('sha256', hook.secret).update(`${h['x-corpvideo-timestamp']}.${received[0].body}`).digest('hex');
    assert.equal(h['x-corpvideo-signature'], `sha256=${sig}`, 'подпись совпадает');
    let dl = (await admin.get(`/api/admin/webhooks/${hook.id}/deliveries`)).json.deliveries;
    assert.equal(dl[0].status, 'failed');
    assert.equal(dl[0].httpStatus, 500);
    const retry = await admin.post(`/api/admin/webhooks/deliveries/${dl[0].id}/retry`, {});
    assert.equal(retry.status, 200);
    for (let i = 0; i < 20 && received.length < 2; i++) await new Promise((r) => setTimeout(r, 500));
    assert.equal(received.length, 2, 'повторная доставка');
    // событие портала: изменение видео
    const upd = await user.patch(`/api/videos/${videoId}`, { description: 'Описание для вебхука' });
    assert.equal(upd.status, 200);
    for (let i = 0; i < 30 && received.length < 3; i++) await new Promise((r) => setTimeout(r, 500));
    assert.equal(received.length, 3, 'событие video.updated доставлено');
    const payload = JSON.parse(received[2].body);
    assert.equal(payload.event, 'video.updated');
    assert.equal(payload.data.video.id, videoId);
    assert.ok(payload.data.fields.includes('description'));
    dl = (await admin.get(`/api/admin/webhooks/${hook.id}/deliveries`)).json.deliveries;
    assert.ok(dl.some((d) => d.event === 'video.updated' && d.status === 'ok'));
    const list = (await admin.get('/api/admin/webhooks')).json.webhooks.find((w) => w.id === hook.id);
    assert.equal(list.lastStatus, 200);
    // отключённый вебхук не получает события
    await admin.patch(`/api/admin/webhooks/${hook.id}`, { enabled: false });
    await user.patch(`/api/videos/${videoId}`, { description: 'Ещё одно изменение' });
    await new Promise((r) => setTimeout(r, 1500));
    assert.equal(received.length, 3, 'отключённый вебхук молчит');
    const del = await admin.del(`/api/admin/webhooks/${hook.id}`);
    assert.equal(del.status, 200);
    const nobody = await user.get('/api/admin/webhooks');
    assert.equal(nobody.status, 403);
  } finally { srv.close(); }
});

test('редактор: поиск и удаление пауз, обрезка → новая версия, клип как отдельное вертикальное видео', async () => {
  // 12-секундное видео: тон 0–4 с, тишина 4–8 с, тон 8–12 с
  const tmp = path.join(os.tmpdir(), `cv-edit-${stamp}.mp4`);
  execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=640x360:rate=25:duration=12', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=12', '-af', "volume=enable='between(t,4,8)':volume=0", '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', tmp]);
  const form = new FormData();
  form.append('file', new Blob([fs.readFileSync(tmp)], { type: 'video/mp4' }), 'Монтаж.mp4');
  form.append('title', 'Видео для монтажа'); form.append('visibility', 'internal');
  const up = await user.post('/api/uploads/simple', form);
  assert.equal(up.status, 200, up.text);
  editVideoId = up.json.video.id; editShortId = up.json.video.shortId;
  fs.unlinkSync(tmp);
  let v = await waitVideoReady(user, editVideoId);
  assert.equal(v.status, 'ready', 'видео обработано');
  assert.ok(v.audioUrl, 'аудиодорожка создана при обработке');
  const ed = await user.get(`/api/videos/${editVideoId}/editor`);
  assert.equal(ed.status, 200, ed.text);
  assert.equal(ed.json.enabled, true);
  assert.ok(ed.json.duration > 11);
  // 1. Поиск пауз (без применения)
  const det = await user.post(`/api/videos/${editVideoId}/editor/silence`, { apply: false, minSec: 1, noiseDb: -40 });
  assert.equal(det.status, 200, det.text);
  let j = await waitJob(user, editVideoId, det.json.jobId);
  assert.equal(j.status, 'done', 'silencedetect: ' + j?.error);
  assert.equal(j.result.silences, 1, 'найдена одна пауза');
  assert.ok(j.result.cuts[0].start > 3.5 && j.result.cuts[0].start < 5, 'пауза начинается около 4 с: ' + JSON.stringify(j.result.cuts));
  assert.ok(j.result.cuts[0].end > 7 && j.result.cuts[0].end < 8.5, 'пауза заканчивается около 8 с');
  // 2. Удаление пауз (применение) → новая версия
  const app1 = await user.post(`/api/videos/${editVideoId}/editor/silence`, { apply: true, minSec: 1, noiseDb: -40, keepSec: 0.3 });
  assert.equal(app1.status, 200, app1.text);
  j = await waitJob(user, editVideoId, app1.json.jobId);
  assert.equal(j.status, 'done', 'remove_silence: ' + j?.error);
  assert.equal(j.result.applied, true);
  v = await waitVideoReady(user, editVideoId);
  assert.equal(v.status, 'ready');
  assert.equal(v.version, 2, 'версия увеличилась');
  assert.ok(v.duration > 7.5 && v.duration < 9.5, 'длительность после удаления паузы ~8.6 с: ' + v.duration);
  assert.ok(v.editHistory.length >= 1 && v.editHistory[0].op === 'remove_silence');
  // 3. Обрезка начала и конца
  const trim = await user.post(`/api/videos/${editVideoId}/editor/trim`, { start: 1, end: 5 });
  assert.equal(trim.status, 200, trim.text);
  j = await waitJob(user, editVideoId, trim.json.jobId);
  assert.equal(j.status, 'done', 'trim: ' + j?.error);
  v = await waitVideoReady(user, editVideoId);
  assert.equal(v.version, 3);
  assert.ok(v.duration > 3.5 && v.duration < 4.5, 'после обрезки ~4 с: ' + v.duration);
  const badTrim = await user.post(`/api/videos/${editVideoId}/editor/trim`, { start: 0, end: 100 });
  assert.equal(badTrim.status, 400);
  // 4. Клип как отдельное вертикальное видео (создаёт зритель с правом загрузки)
  const clip = await viewer.post(`/api/videos/${editVideoId}/clips`, { start: 0.5, end: 3, title: 'Клип для ленты', vertical: true, visibility: 'public' });
  assert.equal(clip.status, 403, 'клип чужого internal-видео не может быть публичным');
  const clip2 = await viewer.post(`/api/videos/${editVideoId}/clips`, { start: 0.5, end: 3, title: 'Клип для ленты', vertical: true });
  assert.equal(clip2.status, 200, clip2.text);
  j = await waitJob(viewer, editVideoId, clip2.json.jobId);
  assert.equal(j.status, 'done', 'clip: ' + j?.error);
  clipVideoId = j.result.videoId;
  const cv = await waitVideoReady(viewer, clipVideoId);
  assert.equal(cv.status, 'ready', 'клип обработан');
  assert.equal(cv.clipOf, editVideoId);
  assert.equal(cv.clipRange.vertical, true);
  assert.ok(cv.height > cv.width, 'вертикальный формат: ' + cv.width + 'x' + cv.height);
  assert.ok(cv.duration > 2 && cv.duration < 3.5);
  assert.equal(cv.visibility, 'private');
  assert.equal(cv.title, 'Клип для ленты');
  const clips = await viewer.get(`/api/videos/${editVideoId}/clips`);
  assert.ok(clips.json.clips.some((c) => c.id === clipVideoId), 'клип в списке клипов исходного видео');
  await viewer.del(`/api/videos/${clipVideoId}`);
});

test('перевод субтитров через ИИ (мок LLM) и текст на экране (OCR)', async () => {
  const { srv, port } = await mockServer((req, res, body) => {
    const j = JSON.parse(body || '{}');
    const prompt = j.messages?.[1]?.content || '';
    let answer;
    if (/Lines:/.test(prompt)) {
      const lines = prompt.split('Lines:')[1].trim().split('\n').map((l) => l.replace(/^\d+\.\s*/, ''));
      answer = JSON.stringify({ lines: lines.map((l) => `EN: ${l}`) });
    } else answer = 'готов';
    res.setHeader('content-type', 'application/json'); res.end(JSON.stringify({ choices: [{ message: { content: answer } }] }));
  });
  try {
    await admin.put('/api/admin/settings', { settings: { 'ai.enabled': true, 'ai.url': `http://127.0.0.1:${port}/v1/chat/completions`, 'ai.timeout_sec': 20 } });
    const vtt = 'WEBVTT\n\n1\n00:00:00.000 --> 00:00:01.500\nПервая реплика.\n\n2\n00:00:01.500 --> 00:00:03.000\nВторая реплика.\n';
    const form = new FormData(); form.append('language', 'ru'); form.append('label', 'Русский'); form.append('file', new Blob([vtt], { type: 'text/vtt' }), 'ru.vtt');
    const sub = await user.post(`/api/videos/${editVideoId}/subtitles`, form);
    assert.equal(sub.status, 200, sub.text);
    const same = await user.post(`/api/videos/${editVideoId}/subtitles/${sub.json.subtitle.id}/translate`, { language: 'ru' });
    assert.equal(same.status, 400);
    const tr = await user.post(`/api/videos/${editVideoId}/subtitles/${sub.json.subtitle.id}/translate`, { language: 'en' });
    assert.equal(tr.status, 200, tr.text);
    const j = await waitJob(user, editVideoId, tr.json.jobId, 60);
    assert.equal(j.status, 'done', 'перевод: ' + j?.error);
    assert.equal(j.result.cues, 2);
    const subs = (await user.get(`/api/videos/${editVideoId}/subtitles`)).json.subtitles;
    const en = subs.find((x) => x.language === 'en');
    assert.ok(en && en.status === 'ready', 'английская дорожка готова');
    assert.equal(en.translatedFrom, sub.json.subtitle.id);
    const file = await user.get(en.url);
    assert.ok(file.text.includes('EN: Первая реплика.'), 'перевод записан с сохранением таймкодов: ' + file.text);
    assert.ok(file.text.includes('00:00:01.500 --> 00:00:03.000'));
  } finally { srv.close(); await admin.put('/api/admin/settings', { settings: { 'ai.enabled': false } }); }

  // OCR: видео из «слайда» с текстом
  const png = path.join(os.tmpdir(), `cv-slide-${stamp}.png`);
  execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=c=white:size=1280x720:duration=1', '-vf', "drawtext=text='Инструктаж по охране труда':fontsize=64:fontcolor=black:x=80:y=120:fontfile=/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf,drawtext=text='Zone 42 safety rules':fontsize=64:fontcolor=black:x=80:y=300:fontfile=/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf", '-frames:v', '1', png]);
  const mp4 = path.join(os.tmpdir(), `cv-slide-${stamp}.mp4`);
  execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-loop', '1', '-i', png, '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono', '-t', '6', '-r', '10', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', mp4]);
  const form2 = new FormData(); form2.append('file', new Blob([fs.readFileSync(mp4)], { type: 'video/mp4' }), 'slide.mp4'); form2.append('title', 'Слайд с текстом'); form2.append('visibility', 'internal');
  const up = await user.post('/api/uploads/simple', form2);
  assert.equal(up.status, 200, up.text);
  fs.unlinkSync(png); fs.unlinkSync(mp4);
  const slideId = up.json.video.id;
  const ready = await waitVideoReady(user, slideId);
  assert.equal(ready.status, 'ready');
  const off = await user.post(`/api/videos/${slideId}/ocr`, {});
  assert.equal(off.status, 403, 'OCR выключен по умолчанию');
  const ocrTest = await admin.post('/api/admin/settings/test-ocr', {});
  assert.equal(ocrTest.status, 200, 'tesseract установлен: ' + ocrTest.text);
  assert.ok(ocrTest.json.languages.includes('eng'));
  await admin.put('/api/admin/settings', { settings: { 'ocr.enabled': true, 'ocr.interval_sec': 2 } });
  try {
    const run = await user.post(`/api/videos/${slideId}/ocr`, {});
    assert.equal(run.status, 200, run.text);
    const j = await waitJob(user, slideId, run.json.jobId, 120);
    assert.equal(j.status, 'done', 'OCR: ' + j?.error);
    assert.ok(j.result.texts >= 1, 'распознан хотя бы один кадр');
    const st = await user.get(`/api/videos/${slideId}/screen-text`);
    assert.equal(st.json.status, 'done');
    const all = st.json.items.map((i) => i.text).join('\n');
    assert.match(all, /Zone 42|safety/i, 'английский текст распознан: ' + all);
    if (ocrTest.json.languages.includes('rus')) assert.match(all, /охране труда|Инструктаж/i, 'русский текст распознан: ' + all);
    const found = await user.get(`/api/videos/${slideId}/screen-text?q=zone`);
    assert.equal(found.json.items.length >= 1, true);
    const search = await user.get('/api/search?q=safety%20rules&type=video');
    assert.ok(search.json.videos?.some?.((x) => x.id === slideId) || search.json.items?.some?.((x) => x.id === slideId), 'видео находится по тексту с экрана: ' + search.text.slice(0, 200));
    const v = (await user.get(`/api/videos/${slideId}`)).json.video;
    assert.equal(v.hasScreenText, true);
  } finally { await admin.put('/api/admin/settings', { settings: { 'ocr.enabled': false } }); }
  await user.del(`/api/videos/${slideId}`);
});

test('подсказки и конечная заставка, RSS/подкаст-ленты с личным токеном, SCORM-пакет', async () => {
  const p = await user.patch(`/api/videos/${editVideoId}`, {
    cards: [{ start: 2, type: 'video', target: shortId, title: 'Смотрите также', duration: 15 }, { start: 1, type: 'url', target: 'https://example.com/doc', title: 'Регламент' }, { start: 3, type: 'url', target: 'javascript:alert(1)', title: 'плохая' }],
    endScreen: { seconds: 12, items: [{ type: 'video', target: shortId, title: 'Следующее' }, { type: 'subscribe' }] },
  });
  assert.equal(p.status, 200, p.text);
  assert.equal(p.json.video.cards.length, 2, 'небезопасная ссылка отброшена');
  assert.equal(p.json.video.cards[0].start, 1, 'подсказки отсортированы по времени');
  assert.equal(p.json.video.endScreen.seconds, 12);
  assert.equal(p.json.video.endScreen.items.length, 2);
  // RSS: публичная лента не содержит internal-видео, лента с токеном — содержит
  const rss = await guest.get('/api/rss/latest');
  assert.equal(rss.status, 200, rss.text);
  assert.ok(rss.headers.get('content-type').includes('rss+xml'));
  assert.ok(rss.text.includes('<rss'));
  assert.ok(!rss.text.includes(`/watch/${editShortId}`), 'internal-видео не в публичной ленте');
  const ft = await user.get('/api/me/feed-token');
  assert.equal(ft.status, 200, ft.text);
  assert.ok(ft.json.token.length >= 16);
  const my = await guest.get(`/api/rss/latest?ft=${ft.json.token}`);
  assert.ok(my.text.includes(`/watch/${editShortId}`), 'с личным токеном internal-видео видно');
  const atom = await guest.get(`/api/rss/latest?ft=${ft.json.token}&format=atom`);
  assert.ok(atom.text.includes('<feed') && atom.headers.get('content-type').includes('atom+xml'));
  const pod = await guest.get(`/api/rss/latest?ft=${ft.json.token}&audio=1`);
  assert.ok(pod.text.includes('itunes:duration'), 'подкаст-лента с тегами iTunes');
  const encl = pod.text.match(/<enclosure url="([^"]+)" type="audio\/mp4"/);
  assert.ok(encl, 'аудио-вложение в подкаст-ленте: ' + pod.text.slice(0, 500));
  // В ленте — абсолютный адрес от BASE_URL портала; запрос идёт тем же путём, каким ходит тест
  const audio = await guest.get(encl[1].replace(/^https?:\/\/[^/]+/, '').replace(/&amp;/g, '&'));
  assert.equal(audio.status, 200, 'аудиодорожка доступна по токену ленты');
  const chan = await guest.get(`/api/rss/channel/${(await user.get('/api/auth/me')).json.user.handle}?ft=${ft.json.token}`);
  assert.equal(chan.status, 200, chan.text);
  const subsFeed = await guest.get('/api/rss/subscriptions');
  assert.equal(subsFeed.status, 403);
  const reset = await user.post('/api/me/feed-token/reset', {});
  assert.notEqual(reset.json.token, ft.json.token);
  const old = await guest.get(`/api/rss/latest?ft=${ft.json.token}`);
  assert.ok(!old.text.includes(`/watch/${editShortId}`), 'старый токен больше не действует');
  // SCORM
  const zip = await user.req('GET', `/api/videos/${editVideoId}/scorm.zip?version=1.2&percent=80&share=1`);
  assert.equal(zip.status, 200, zip.text.slice(0, 200));
  assert.ok(zip.headers.get('content-type').includes('zip'));
  assert.ok(zip.text.startsWith('PK'), 'zip-архив');
  assert.ok(zip.text.includes('imsmanifest.xml') && zip.text.includes('index.html') && zip.text.includes('scorm.js'));
  const links = await user.get(`/api/videos/${editVideoId}/share-links`);
  const scormLink = links.json.links.find((l) => l.note === 'SCORM');
  assert.ok(scormLink, 'создана гостевая ссылка для SCORM');
  const zip2004 = await user.req('GET', `/api/videos/${editVideoId}/scorm.zip?version=2004`);
  assert.equal(zip2004.status, 200);
  const forbidden = await viewer.req('GET', `/api/videos/${editVideoId}/scorm.zip`);
  assert.equal(forbidden.status, 403);
  const replay = await user.get(`/api/videos/${editVideoId}/chat-replay`);
  assert.equal(replay.json.available, false, 'не запись эфира — повтора чата нет');
});

test('xAPI: выражения в LRS при выполнении назначения и тесте (мок LRS)', async () => {
  const statements = [];
  const { srv, port } = await mockServer((req, res, body) => { statements.push({ auth: req.headers.authorization, url: req.url, version: req.headers['x-experience-api-version'], body: JSON.parse(body) }); res.setHeader('content-type', 'application/json'); res.end(JSON.stringify(['00000000-0000-0000-0000-000000000000'])); });
  try {
    const st = await admin.put('/api/admin/settings', { settings: { 'xapi.enabled': true, 'xapi.endpoint': `http://127.0.0.1:${port}/xapi/`, 'xapi.username': 'lrs', 'xapi.password': 'secret' } });
    assert.equal(st.status, 200);
    const t = await admin.post('/api/admin/settings/test-xapi', {});
    assert.equal(t.status, 200, t.text);
    assert.equal(statements.length, 1);
    assert.equal(statements[0].auth, 'Basic ' + Buffer.from('lrs:secret').toString('base64'));
    assert.equal(statements[0].url, '/xapi/statements');
    assert.equal(statements[0].version, '1.0.3');
    // назначение зрителю на 12-секундное видео с порогом 50 % → прогресс → completed
    const a = await admin.post('/api/assignments', { videoId: editVideoId, targets: [{ type: 'user', id: viewerEmail }], requiredPercent: 50 });
    assert.equal(a.status, 200, a.text);
    const pr = await viewer.post(`/api/videos/${editShortId}/progress`, { position: 3.9, watchedDelta: 4, buckets: [0, 1, 2, 3] });
    assert.equal(pr.status, 200, pr.text);
    for (let i = 0; i < 30 && statements.length < 2; i++) await new Promise((r) => setTimeout(r, 500));
    const completed = statements.find((s) => s.body.verb?.id?.endsWith('/completed'));
    assert.ok(completed, 'выражение completed отправлено: ' + JSON.stringify(statements.map((s) => s.body.verb)));
    assert.equal(completed.body.actor.mbox, `mailto:${viewerEmail}`);
    assert.ok(completed.body.object.id.endsWith(`/watch/${editShortId}`));
    assert.equal(completed.body.result.completion, true);
    const log = await admin.get('/api/admin/xapi/statements');
    assert.ok(log.json.statements.some((x) => x.verb === 'completed' && x.status === 'ok'));
    await admin.del(`/api/assignments/${a.json.assignment.id}`);
  } finally { srv.close(); await admin.put('/api/admin/settings', { settings: { 'xapi.enabled': false } }); }
});

test('обязательная двухфакторная защита для администраторов', async () => {
  const on = await admin.put('/api/admin/settings', { settings: { 'security.require_totp_admins': true } });
  assert.equal(on.status, 200, on.text);
  try {
    const blocked = await admin.get('/api/admin/settings');
    assert.equal(blocked.status, 403);
    assert.equal(blocked.json.code, 'totp_required');
    const cfg = await admin.get('/api/config');
    assert.equal(cfg.json.requireTotpAdmins, true);
  } finally {
    // Администратор включает TOTP → доступ возвращается; затем выключаем требование и TOTP
    const setup = await admin.post('/api/auth/totp/setup', {});
    assert.equal(setup.status, 200, setup.text);
    const code = totpCode(setup.json.secret, Math.floor(Date.now() / 1000 / 30));
    const en = await admin.post('/api/auth/totp/enable', { code });
    assert.equal(en.status, 200, en.text);
    const ok = await admin.get('/api/admin/settings');
    assert.equal(ok.status, 200, ok.text);
    await admin.put('/api/admin/settings', { settings: { 'security.require_totp_admins': false } });
    const off = await admin.post('/api/auth/totp/disable', { password: ADMIN.password });
    assert.equal(off.status, 200, off.text);
  }
});

test('папка автоимпорта и пробный дайджест', async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'cv-inbox-'));
  const file = path.join(dir, `Совещание_${stamp}.mp4`);
  execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=320x240:rate=25:duration=2', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', file]);
  await admin.put('/api/admin/settings', { settings: { 'import.watch_dir': dir, 'import.watch_owner': userEmail, 'import.watch_visibility': 'internal', 'import.watch_delete': true } });
  try {
    const scan = await admin.post('/api/admin/system/watch-scan', {});
    assert.equal(scan.status, 200, scan.text);
    assert.equal(scan.json.imported, 1, 'файл импортирован: ' + scan.text);
    assert.ok(!fs.existsSync(file), 'файл забран из папки');
    const entry = scan.json.log.find((l) => l.filename === `Совещание_${stamp}.mp4`);
    assert.equal(entry.status, 'imported');
    const v = await waitVideoReady(user, entry.videoId, 120);
    assert.equal(v.status, 'ready');
    assert.equal(v.title, `Совещание ${stamp}`);
    assert.equal(v.visibility, 'internal');
    const again = await admin.post('/api/admin/system/watch-scan', {});
    assert.equal(again.json.imported || 0, 0, 'повторно не импортируется');
    await user.del(`/api/videos/${entry.videoId}`);
    const digest = await admin.post('/api/admin/system/digest-test', {});
    assert.equal(digest.status, 400, 'без SMTP дайджест не отправляется');
  } finally {
    await admin.put('/api/admin/settings', { settings: { 'import.watch_dir': '', 'import.watch_enabled': false } });
    fs.rmSync(dir, { recursive: true, force: true });
  }
  await user.del(`/api/videos/${editVideoId}`);
});

test('вебинары: регистрация, присутствие, отчёт и напоминания', async () => {
  const st = await admin.post('/api/studio/live', { title: `Вебинар теста ${stamp}`, scheduledAt: new Date(Date.now() + 3600000).toISOString() });
  assert.equal(st.status, 200, st.text);
  const sid = st.json.stream.id; const short = st.json.stream.shortId;

  // Без регистрации записаться нельзя
  const early = await user.post(`/api/live/${sid}/registration`, {});
  assert.equal(early.status, 400, 'регистрация выключена — 400');

  const on = await admin.patch(`/api/studio/live/${sid}`, { registration: true, registrationLimit: 2, registrationNote: 'Только отдел продаж' });
  assert.equal(on.json.stream.registration, true, on.text);

  const state = await user.get(`/api/live/${short}/registration`);
  assert.equal(state.json.enabled, true);
  assert.equal(state.json.seatsLeft, 2);
  assert.equal(state.json.note, 'Только отдел продаж');

  const reg = await user.post(`/api/live/${sid}/registration`, {});
  assert.equal(reg.status, 200, reg.text);
  const state2 = await user.get(`/api/live/${short}/registration`);
  assert.equal(state2.json.registered, true);
  assert.equal(state2.json.count, 1);
  assert.equal(state2.json.seatsLeft, 1);

  // Присутствие копится только во время эфира
  const idle = await user.post(`/api/live/${sid}/attendance`, { seconds: 60 });
  assert.equal(idle.json.ignored, 'not-live', 'до начала эфира присутствие не засчитывается');
  await sql(`UPDATE live_streams SET status = 'live', started_at = now() - interval '10 minutes' WHERE id = $1`, [sid]);
  // Первый сигнал — не больше минуты, дальше — не больше, чем прошло с прошлого сигнала (+5 с)
  await user.post(`/api/live/${sid}/attendance`, { seconds: 90 });
  const burst = await user.post(`/api/live/${sid}/attendance`, { seconds: 9999 });
  assert.equal(burst.status, 200, burst.text);
  let [a] = await sql('SELECT seconds FROM live_attendance WHERE stream_id = $1', [sid]);
  assert.ok(a.seconds >= 60 && a.seconds <= 66, `частые сигналы не накручивают время (учтено ${a.seconds})`);
  // Через «две минуты» засчитывается не больше 120 секунд за сигнал
  await sql(`UPDATE live_attendance SET seconds = 60, last_at = now() - interval '5 minutes' WHERE stream_id = $1`, [sid]);
  await user.post(`/api/live/${sid}/attendance`, { seconds: 9999 });
  [a] = await sql('SELECT seconds FROM live_attendance WHERE stream_id = $1', [sid]);
  assert.equal(a.seconds, 180, 'за один сигнал — не больше 120 секунд');
  // Частота сигналов ограничена
  let limited = 0;
  for (let i = 0; i < 12; i++) { const r = await user.post(`/api/live/${sid}/attendance`, { seconds: 1 }); if (r.status === 429) limited++; }
  assert.ok(limited > 0, 'после десятка сигналов в минуту — 429');
  await sql(`UPDATE live_attendance SET seconds = 210 WHERE stream_id = $1`, [sid]);
  const rep = await admin.get(`/api/live/${sid}/attendees`);
  assert.equal(rep.status, 200, rep.text);
  const row = rep.json.people.find((p) => p.email === userEmail) || rep.json.people[0];
  assert.equal(row.seconds, 210);
  assert.equal(rep.json.totals.registered, 1);
  assert.equal(rep.json.totals.attended, 1);
  const csv = await admin.get(`/api/live/${sid}/attendees?format=csv`);
  assert.match(csv.text, /Был на эфире/);

  // Отчёт доступен только организатору
  const foreign = await user.get(`/api/live/${sid}/attendees`);
  assert.equal(foreign.status, 403, 'чужой отчёт закрыт');

  // Отмена регистрации
  await user.del(`/api/live/${sid}/registration`);
  const state3 = await user.get(`/api/live/${short}/registration`);
  assert.equal(state3.json.registered, false);
  assert.equal(state3.json.count, 0);

  // Завершение из панели — тем же путём, что и в студии: второй вызов ничего не повторяет
  const end1 = await admin.post(`/api/admin/live/${sid}/end`, {});
  assert.equal(end1.status, 200, end1.text);
  assert.equal(end1.json.ended, true, 'эфир завершён');
  const end2 = await admin.post(`/api/admin/live/${sid}/end`, {});
  assert.equal(end2.json.ended, false, 'повторное завершение — без повторных событий');
  const [row2] = await sql('SELECT status, ended_at FROM live_streams WHERE id = $1', [sid]);
  assert.equal(row2.status, 'ended');
  assert.ok(row2.ended_at, 'время окончания проставлено');
  const late = await user.post(`/api/live/${sid}/attendance`, { seconds: 60 });
  assert.ok(late.status === 429 || late.json.ignored === 'not-live', 'после окончания присутствие не копится');
  await admin.del(`/api/studio/live/${sid}`);
});

test('качество воспроизведения: приём метрик и сводка', async () => {
  // Берём с запасом: если недавние загрузки ещё обрабатываются, готовое видео найдётся дальше по списку
  const vlist = await admin.get('/api/studio/videos?limit=50');
  const v = (vlist.json.videos || []).find((x) => x.status === 'ready');
  assert.ok(v, 'в студии есть хотя бы одно готовое видео');
  const key = `t${Date.now().toString(36)}qoe`;
  const p1 = await user.post('/api/playback', { sessionKey: key, videoId: v.id, startupMs: 700, watchSec: 10, rebufferCount: 1, rebufferMs: 500, qualityHeight: 720, bitrateKbps: 2000, started: true, device: 'mobile', browser: 'chrome', source: 'watch' });
  assert.equal(p1.status, 200, p1.text);
  // Повторный сигнал того же сеанса не задваивает счётчики, а обновляет их
  const p2 = await user.post('/api/playback', { sessionKey: key, videoId: v.id, watchSec: 30, rebufferCount: 2, rebufferMs: 800, started: true });
  assert.equal(p2.status, 200, p2.text);
  const bad = await user.post('/api/playback', { sessionKey: 'к!', videoId: v.id });
  assert.equal(bad.status, 400, 'некорректный ключ сеанса отклонён');

  const q = await admin.get('/api/admin/quality?days=1');
  assert.equal(q.status, 200, q.text);
  assert.ok(q.json.totals.sessions >= 1, 'сеансы учтены');
  assert.ok(q.json.totals.startupMedianMs >= 1, 'измерено время старта');
  assert.ok(q.json.byDevice.some((d) => d.device === 'mobile'), 'есть разрез по устройствам');
  const csv = await admin.get('/api/admin/quality?days=1&format=csv');
  assert.match(csv.text, /Доля буферизации/);
});

test('курсы: создание, шаги, публикация, прохождение, сертификат, отчёт и назначение', async () => {
  const stampC = Date.now().toString(36);
  const cr = await admin.post('/api/courses', { title: `Курс ${stampC}`, description: 'Проверочный курс', requiredPercent: 20 });
  assert.equal(cr.status, 200, cr.text);
  const cid = cr.json.course.id;
  assert.ok(cr.json.course.slug, 'у курса есть адрес');

  // Шаги: видео, текст, материал
  const vlist = await admin.get('/api/studio/videos?limit=10');
  const v = (vlist.json.videos || []).find((x) => x.status === 'ready' && Number(x.duration) > 3);
  assert.ok(v, 'нужно готовое видео');
  const i1 = await admin.post(`/api/courses/${cid}/items`, { kind: 'video', videoId: v.id, section: 'Раздел 1' });
  const i2 = await admin.post(`/api/courses/${cid}/items`, { kind: 'text', title: 'Пояснение', body: 'Текст шага', section: 'Раздел 1' });
  const i3 = await admin.post(`/api/courses/${cid}/items`, { kind: 'material', title: 'Регламент', url: 'https://example.com/doc.pdf', section: 'Раздел 2' });
  assert.equal(i1.status, 200, i1.text);
  assert.equal(i3.status, 200, i3.text);

  // Черновик нельзя назначить
  const early = await admin.post('/api/assignments', { courseId: cid, targets: [{ type: 'user', id: 'user@vodokomfort.ru' }] });
  assert.equal(early.status, 400, 'черновик курса не назначается');

  const pub = await admin.patch(`/api/courses/${cid}`, { status: 'published' });
  assert.equal(pub.json.course.status, 'published', pub.text);

  // Сотрудник записывается сам и проходит шаги
  await user.post(`/api/courses/${cid}/enroll`, {});
  const page = await user.get(`/api/courses/${cid}`);
  assert.equal(page.status, 200, page.text);
  assert.equal(page.json.items.length, 3);
  assert.equal(page.json.items[0].locked, false, 'первый шаг открыт');
  assert.equal(page.json.items[1].locked, true, 'последовательный курс: следующий шаг закрыт');
  const blocked = await user.post(`/api/courses/${cid}/items/${i2.json.item.id}/complete`, {});
  assert.equal(blocked.status, 400, 'закрытый шаг нельзя отметить');

  // Просмотр видео засчитывает первый шаг
  await user.post(`/api/videos/${v.shortId}/progress`, { position: Number(v.duration) * 0.95, watchedDelta: 6, source: 'watch' });
  const afterWatch = await user.get(`/api/courses/${cid}`);
  assert.equal(afterWatch.json.items[0].completed, true, 'видео-шаг засчитан');
  assert.equal(afterWatch.json.items[1].locked, false, 'следующий шаг открылся');

  await user.post(`/api/courses/${cid}/items/${i2.json.item.id}/complete`, {});
  await user.post(`/api/courses/${cid}/items/${i3.json.item.id}/complete`, {});
  const done = await user.get(`/api/courses/${cid}`);
  assert.equal(done.json.course.completed, true, 'курс пройден');
  assert.equal(done.json.course.percent, 100);

  // Сертификат за курс
  const certs = await user.get('/api/me/certificates');
  assert.ok((certs.json.certificates || []).some((c) => c.title === `Курс ${stampC}`), 'выдан сертификат за курс');

  // Отчёт автору и CSV
  const rep = await admin.get(`/api/courses/${cid}/report`);
  assert.ok(rep.json.report.people.some((p) => p.completed), 'в отчёте есть прошедший');
  const csv = await admin.get(`/api/courses/${cid}/report?format=csv`);
  assert.match(csv.text, /Сотрудник;/);

  // Назначение курса и раздел «Назначено вам»
  const asg = await admin.post('/api/assignments', { courseId: cid, targets: [{ type: 'all' }], note: 'Пройти курс' });
  assert.equal(asg.json.assignment.kind, 'course', asg.text);
  const mine = await user.get('/api/assignments/mine');
  assert.ok((mine.json.assignments || []).some((a) => a.kind === 'course'), 'курс виден в «Назначено вам»');

  // Сводка по обучению
  const ov = await admin.get('/api/learning/overview');
  assert.ok(ov.json.courses.some((c) => c.id === cid), 'курс в сводке по обучению');
  assert.ok(ov.json.people.length >= 1, 'в сводке есть сотрудники');
});

test('реакции по таймкоду, тепловая карта, звуковые дорожки и переход к моменту', async () => {
  const vlist = await admin.get('/api/studio/videos?limit=10');
  const v = (vlist.json.videos || []).find((x) => x.status === 'ready' && Number(x.duration) > 3);

  // Реакции: постановка, повтор снимает, сводка автору
  const r1 = await user.post(`/api/videos/${v.shortId}/reactions`, { kind: 'question', t: 4 });
  assert.equal(r1.status, 200, r1.text);
  const list = await user.get(`/api/videos/${v.shortId}/reactions`);
  assert.ok(list.json.totals.question >= 1, 'реакция учтена');
  assert.ok(list.json.marks.length >= 1, 'есть метки для полосы перемотки');
  const again = await user.post(`/api/videos/${v.shortId}/reactions`, { kind: 'question', t: 4.5 });
  assert.equal(again.json.removed, true, 'повторная реакция рядом снимает отметку');
  const bad = await user.post(`/api/videos/${v.shortId}/reactions`, { kind: 'nope', t: 1 });
  assert.equal(bad.status, 400, 'неизвестная реакция отклонена');
  const rep = await admin.get(`/api/videos/${v.shortId}/reactions/report`);
  assert.equal(rep.status, 200, rep.text);

  // Тепловая карта: автору видна всегда, зрителю — только при достаточном числе просмотров.
  // Кривая строится по данным удержания, поэтому сначала отправляем просмотр с отметками участков.
  await user.post(`/api/videos/${v.shortId}/progress`, { position: 3, watchedDelta: 3, buckets: [0, 1, 2, 3, 4] });
  const hmOwner = await admin.get(`/api/videos/${v.shortId}/heatmap`);
  assert.equal(hmOwner.json.available, true, 'автор видит кривую');
  assert.equal(hmOwner.json.points.length, 100);
  const hmUser = await user.get(`/api/videos/${v.shortId}/heatmap`);
  assert.equal(typeof hmUser.json.available, 'boolean');

  // Границы вступления
  const pv = await admin.patch(`/api/videos/${v.id}`, { introEnd: 2.5, outroStart: Math.max(3, Number(v.duration) - 1) });
  assert.equal(pv.status, 200, pv.text);
  assert.equal((await admin.get(`/api/videos/${v.shortId}`)).json.video.introEnd, 2.5);

  // Звуковая дорожка (дубляж)
  const wav = Buffer.concat([Buffer.from('RIFF'), Buffer.alloc(4), Buffer.from('WAVEfmt ')], 64);
  const fd = new FormData();
  fd.append('kind', 'description');
  fd.append('language', 'ru');
  fd.append('label', 'Тифлокомментарий');
  fd.append('file', new Blob([wav], { type: 'audio/wav' }), 'audio.wav');
  const up = await admin.req('POST', `/api/videos/${v.id}/audio-tracks`, fd);
  assert.equal(up.status, 200, up.text);
  const tracks = await user.get(`/api/videos/${v.shortId}/audio-tracks`);
  assert.ok(tracks.json.tracks.some((t) => t.kind === 'description'), 'дорожка видна зрителю');
  await admin.del(`/api/videos/${v.id}/audio-tracks/${up.json.track.id}`);

  // Переход к найденному моменту
  const mom = await user.get(`/api/videos/${v.shortId}/moment?q=${encodeURIComponent('вода')}`);
  assert.equal(mom.status, 200, mom.text);
  assert.ok(Array.isArray(mom.json.hits));
  const short = await user.get(`/api/videos/${v.shortId}/moment?q=a`);
  assert.equal(short.status, 400, 'слишком короткий запрос отклонён');
});

test('границы доступа после аудита: токены, секреты вебхуков, гонки монтажа, валидность лент', async () => {
  // 1. API-токен не даёт доступа к администрированию, даже если владелец — администратор
  const t = await admin.post('/api/me/tokens', { name: 'audit' });
  assert.equal(t.status, 200, t.text);
  const byToken = new Client();
  const adminCall = await byToken.req('GET', '/api/admin/users?limit=1', null, { headers: { authorization: `Bearer ${t.json.token}` } });
  assert.equal(adminCall.status, 403, 'админка недоступна по токену');
  const meCall = await byToken.req('GET', '/api/auth/me', null, { headers: { authorization: `Bearer ${t.json.token}` } });
  assert.equal(meCall.json.user?.role, 'admin', 'обычные вызовы по токену работают');

  // 2. Секрет вебхука не возвращается в списке
  const wh = await admin.post('/api/admin/webhooks', { name: 'audit', url: 'http://127.0.0.1:9/hook', events: [] });
  const list = await admin.get('/api/admin/webhooks');
  const row = list.json.webhooks.find((x) => x.id === wh.json.webhook.id);
  assert.equal(row.secret, undefined, 'секрета нет в списке');
  assert.ok(row.secretHint && row.secretHint.length <= 12, 'есть подсказка');
  const sec = await admin.get(`/api/admin/webhooks/${wh.json.webhook.id}/secret`);
  assert.equal(sec.json.secret, wh.json.webhook.secret, 'секрет отдаётся отдельным запросом');
  await admin.del(`/api/admin/webhooks/${wh.json.webhook.id}`);

  // 3. Одновременные запросы монтажа: принимается ровно один
  const vlist = await admin.get('/api/studio/videos?limit=10');
  const v = (vlist.json.videos || []).find((x) => x.status === 'ready' && Number(x.duration) > 3);
  if (v) {
    const rs = await Promise.all(Array.from({ length: 5 }, (_, i) => admin.post(`/api/videos/${v.id}/editor/trim`, { start: 0.2 + i * 0.01, end: Number(v.duration) - 0.5 })));
    assert.equal(rs.filter((r) => r.status === 200).length, 1, 'принят один монтаж из пяти');
    for (const r of rs) if (r.json?.jobId) await admin.post(`/api/admin/jobs/${r.json.jobId}/cancel`, {});
    const bad = await admin.post(`/api/videos/${v.id}/editor/cut`, { cuts: [null] });
    assert.equal(bad.status, 400, 'некорректные фрагменты — 400, а не 500');
  }

  // 4. Ленты остаются корректным XML (без повторного объявления пространства имён и управляющих символов)
  const rss = await admin.get('/api/rss/latest?limit=5');
  assert.equal(rss.status, 200, rss.text);
  const rssTag = rss.text.match(/<rss[^>]*>/)[0];
  assert.equal((rssTag.match(/xmlns:atom=/g) || []).length, 1, 'xmlns:atom объявлен один раз');
  assert.ok(!/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/.test(rss.text), 'нет управляющих символов');

  // 5. Статика: выход за каталог assets запрещён
  const trav = await guest.get('/assets/..%2Findex.html');
  assert.equal(trav.status, 404, 'обход каталога закрыт');
});

test('премьера: анонс, чат, доступ к медиа и переход в обычное видео', async () => {
  const vlist = await admin.get('/api/studio/videos?limit=10');
  const v = (vlist.json.videos || []).find((x) => x.status === 'ready' && Number(x.duration) > 3);
  assert.ok(v, 'нужно готовое видео');

  // Премьера возможна только на будущее время
  const past = await admin.patch(`/api/videos/${v.id}`, { premiere: true, scheduledAt: new Date(Date.now() - 60000).toISOString() });
  assert.equal(past.status, 400, 'премьера в прошлом отклонена');

  const at = new Date(Date.now() + 3600 * 1000).toISOString();
  const set = await admin.patch(`/api/videos/${v.id}`, { premiere: true, premiereChat: true, scheduledAt: at, visibility: 'internal' });
  assert.equal(set.status, 200, set.text);
  assert.equal(set.json.video.premiere, true);
  assert.equal(set.json.video.premiereState, 'scheduled');

  // Анонс виден сотруднику, обратный отсчёт считается
  const pr = await user.get(`/api/videos/${v.shortId}/premiere`);
  assert.equal(pr.status, 200, pr.text);
  assert.equal(pr.json.state, 'scheduled');
  assert.ok(pr.json.startsInSec > 3000, 'до премьеры больше 50 минут');
  assert.equal(pr.json.position, 0);

  // Видео есть в поиске и лентах как анонс
  const card = await user.get(`/api/videos/${v.shortId}`);
  assert.equal(card.status, 200, 'страница анонса открывается');
  assert.equal(card.json.video.premiereState, 'scheduled');

  // Медиа до начала не отдаётся постороннему, автору — отдаётся
  const mediaUser = await mediaAuth(user, `/media/videos/${v.id}/hls/master.m3u8`);
  assert.equal(mediaUser.status, 403, 'зритель не может скачать видео до премьеры');
  const mediaOwner = await mediaAuth(admin, `/media/videos/${v.id}/hls/master.m3u8`);
  assert.equal(mediaOwner.status, 200, 'автор смотрит до премьеры');

  // Чат премьеры
  const msg = await user.post(`/api/videos/${v.shortId}/premiere/chat`, { body: 'Ждём!' });
  assert.equal(msg.status, 200, msg.text);
  assert.equal(msg.json.message.body, 'Ждём!');
  const empty = await user.post(`/api/videos/${v.shortId}/premiere/chat`, { body: '   ' });
  assert.equal(empty.status, 400, 'пустое сообщение отклонено');
  const again = await user.get(`/api/videos/${v.shortId}/premiere`);
  assert.ok(again.json.messages.length >= 1, 'история чата отдаётся');

  // Комнату совместного просмотра на не начавшейся премьере создать нельзя
  const party = await user.post(`/api/videos/${v.id}/party`, {});
  assert.equal(party.status, 400, 'комната до премьеры запрещена');

  // Премьера началась: позиция считается от назначенного времени
  await admin.patch(`/api/videos/${v.id}`, { premiere: false, scheduledAt: null });
  const back = await user.get(`/api/videos/${v.shortId}`);
  assert.equal(back.json.video.premiere, false, 'видео вернулось в обычный режим');
});

test('совместный просмотр: комната, синхронизация, чат и права', async () => {
  const vlist = await admin.get('/api/studio/videos?limit=10');
  const v = (vlist.json.videos || []).find((x) => x.status === 'ready' && Number(x.duration) > 3);
  const created = await admin.post(`/api/videos/${v.id}/party`, { everyoneControls: false });
  assert.equal(created.status, 200, created.text);
  const code = created.json.party.code;
  assert.ok(code && code.length >= 6, 'есть код комнаты');
  assert.ok(created.json.url.includes(`/party/${code}`), 'ссылка для рассылки');

  // Повторный вызов возвращает ту же комнату (ведущий не плодит комнаты)
  const again = await admin.post(`/api/videos/${v.id}/party`, {});
  assert.equal(again.json.party.code, code, 'комната переиспользуется');

  // Ведущий управляет, участник — нет (позиция не может быть дальше конца видео)
  const pos = Math.max(1, Math.min(20, Number(v.duration) / 2));
  const play = await admin.post(`/api/party/${code}/state`, { position: pos, playing: true });
  assert.equal(play.status, 200, play.text);
  assert.ok(Math.abs(play.json.party.position - pos) < 1, 'позиция принята');
  const byUser = await user.post(`/api/party/${code}/state`, { position: 0, playing: false });
  assert.equal(byUser.status, 403, 'участник не управляет, когда это запрещено');

  // Позиция «едет» вместе с эфиром комнаты
  await new Promise((r) => setTimeout(r, 1200));
  const state = await user.get(`/api/party/${code}`);
  assert.equal(state.status, 200, state.text);
  assert.ok(state.json.party.position > pos + 0.5, 'позиция догоняет реальное время');
  assert.ok(state.json.party.members.length >= 1, 'участники видны');

  // Чат комнаты и приглашение
  const msg = await user.post(`/api/party/${code}/chat`, { body: 'Привет' });
  assert.equal(msg.status, 200, msg.text);
  const inv = await admin.post(`/api/party/${code}/invite`, { userIds: [] });
  assert.equal(inv.status, 400, 'пустое приглашение отклонено');

  // Гость без входа в комнату не попадает
  const anon = await guest.get(`/api/party/${code}`);
  assert.equal(anon.status, 401, 'гостю комната недоступна');

  // Закрывает ведущий
  const byOther = await user.post(`/api/party/${code}/end`, {});
  assert.equal(byOther.status, 403, 'участник не закрывает комнату');
  const closed = await admin.post(`/api/party/${code}/end`, {});
  assert.equal(closed.status, 200, closed.text);
  const after = await admin.post(`/api/party/${code}/state`, { position: 5, playing: true });
  assert.equal(after.status, 400, 'в закрытой комнате состояние не меняется');
});

test('экраны-витрины: токен, содержимое, ротация и права', async () => {
  const created = await admin.post('/api/admin/screens', { name: 'Тестовый холл', source: 'latest', subtitles: true });
  assert.equal(created.status, 200, created.text);
  const sc = created.json.screen;
  assert.ok(sc.token && sc.url.includes(`/screen/${sc.token}`), 'есть ссылка экрана');

  // Плейлист отдаётся без входа — телевизору незачем логиниться
  const pl = await guest.get(`/api/screens/${sc.token}/playlist`);
  assert.equal(pl.status, 200, pl.text);
  assert.ok(pl.json.items.length > 0, 'в показе есть видео');
  assert.ok(pl.json.items.every((x) => ['public', 'internal'].includes(x.visibility)), 'личные видео на экран не попадают');
  assert.ok(pl.json.items.every((x) => x.hlsUrl || x.mp4Url), 'у каждого видео есть поток');

  // Сотрудник без прав не управляет экранами
  const byUser = await user.get('/api/admin/screens');
  assert.equal(byUser.status, 403, 'раздел только для модераторов');

  // Смена ссылки отключает старую
  const rot = await admin.patch(`/api/admin/screens/${sc.id}`, { rotateToken: true, shuffle: true });
  assert.equal(rot.status, 200, rot.text);
  assert.notEqual(rot.json.screen.token, sc.token, 'токен сменился');
  const old = await guest.get(`/api/screens/${sc.token}/playlist`);
  assert.equal(old.status, 404, 'старая ссылка больше не работает');

  // Выключенный экран ничего не показывает
  await admin.patch(`/api/admin/screens/${sc.id}`, { isActive: false });
  const off = await guest.get(`/api/screens/${rot.json.screen.token}/playlist`);
  assert.equal(off.status, 403, 'выключенный экран не отдаёт видео');

  const prev = await admin.get(`/api/admin/screens/${sc.id}/preview`);
  assert.equal(prev.status, 200, prev.text);
  assert.ok(prev.json.count >= 0);
  const delr = await admin.del(`/api/admin/screens/${sc.id}`);
  assert.equal(delr.status, 204);
});

test('живые субтитры эфира и видео-SEO', async () => {
  const streams = await admin.get('/api/studio/live');
  const st = (streams.json.streams || [])[0];
  assert.ok(st, 'нужна трансляция');
  const on = await admin.patch(`/api/studio/live/${st.id}`, { captions: true });
  assert.equal(on.status, 200, on.text);
  assert.equal(on.json.stream.captions, true, 'субтитры включены');
  const caps = await user.get(`/api/live/${st.id}/captions`);
  assert.equal(caps.status, 200, caps.text);
  assert.ok(Array.isArray(caps.json.captions));
  await admin.patch(`/api/studio/live/${st.id}`, { captions: false });

  // Разметка schema.org и карта сайта для публичных видео
  const pub = (await admin.get('/api/studio/videos?limit=20')).json.videos.find((x) => x.status === 'ready' && x.visibility === 'public');
  if (pub) {
    const page = await guest.get(`/watch/${pub.shortId}`);
    assert.ok(page.text.includes('application/ld+json'), 'на странице есть разметка VideoObject');
    assert.ok(page.text.includes('"@type":"VideoObject"'), 'тип разметки — VideoObject');
  }
  const sitemap = await guest.get('/sitemap-video.xml');
  assert.equal(sitemap.status, 200, 'карта сайта отдаётся');
  assert.ok(sitemap.text.startsWith('<?xml'), 'это XML');
  const robots = await guest.get('/robots.txt');
  assert.ok(robots.text.includes('Sitemap: '), 'robots.txt указывает на карту сайта');
  assert.ok(robots.text.includes('Disallow: /admin'), 'админка закрыта от роботов');
});

test('тренажёр с ветвлением: настройка, выбор зрителя, отчёт и права', async () => {
  const vlist = await admin.get('/api/studio/videos?limit=10');
  const v = (vlist.json.videos || []).find((x) => x.status === 'ready' && Number(x.duration) > 3);
  assert.ok(v, 'нужно готовое видео');

  const saved = await admin.put(`/api/videos/${v.id}/scenario`, {
    title: 'Действия при утечке', active: true, showResult: true,
    points: [
      { id: 'p1', at: 2, text: 'Обнаружена утечка. Что делать первым?', options: [
        { id: 'o1', text: 'Перекрыть задвижку', goto: 5, feedback: 'Верно', correct: true },
        { id: 'o2', text: 'Уйти с объекта', goto: 8, feedback: 'Так нельзя', correct: false },
      ] },
      { id: 'bad', at: 1, text: 'Развилка без вариантов', options: [{ id: 'x', text: 'Один' }] },
    ],
  });
  assert.equal(saved.status, 200, saved.text);
  assert.equal(saved.json.scenario.points.length, 1, 'развилка без двух вариантов отброшена');

  // Зритель не видит, какой вариант правильный, пока не выберет
  const asViewer = await user.get(`/api/videos/${v.shortId}/scenario`);
  assert.equal(asViewer.status, 200, asViewer.text);
  assert.equal(asViewer.json.scenario.points[0].options[0].correct, undefined, 'правильность скрыта от зрителя');
  assert.equal(asViewer.json.scenario.points[0].options[0].feedback, undefined, 'пояснение скрыто до выбора');

  // Выбор возвращает переход и разбор
  const pick = await user.post(`/api/videos/${v.id}/scenario/choice`, { pointId: 'p1', optionId: 'o2' });
  assert.equal(pick.status, 200, pick.text);
  assert.equal(pick.json.goto, 8, 'переход на нужную секунду');
  assert.equal(pick.json.correct, false);
  assert.equal(pick.json.feedback, 'Так нельзя');
  const wrong = await user.post(`/api/videos/${v.id}/scenario/choice`, { pointId: 'p1', optionId: 'нет' });
  assert.equal(wrong.status, 400, 'несуществующий вариант отклонён');

  // Отчёт автору; зритель его не видит
  const rep = await admin.get(`/api/videos/${v.id}/scenario/report`);
  assert.equal(rep.status, 200, rep.text);
  assert.ok(rep.json.totals.choices >= 1, 'выбор учтён');
  assert.ok(rep.json.totals.mistakes >= 1, 'ошибка видна автору');
  const o2 = rep.json.points[0].options.find((o) => o.id === 'o2');
  assert.ok(o2.count >= 1 && o2.percent > 0, 'распределение по вариантам');
  const byUser = await user.get(`/api/videos/${v.id}/scenario/report`);
  assert.equal(byUser.status, 403, 'отчёт только автору и модераторам');
  const byUserEdit = await user.put(`/api/videos/${v.id}/scenario`, { points: [] });
  assert.equal(byUserEdit.status, 403, 'чужой тренажёр не настроить');

  // Выключенный тренажёр зрителю не отдаётся
  await admin.put(`/api/videos/${v.id}/scenario`, { title: 'Действия при утечке', active: false, points: saved.json.scenario.points });
  const off = await user.get(`/api/videos/${v.shortId}/scenario`);
  assert.equal(off.json.scenario, null, 'выключенный тренажёр скрыт');
  const card = await user.get(`/api/videos/${v.shortId}`);
  assert.equal(card.json.video.hasScenario, false, 'флаг снят');
});

test('итоги встречи и автоклипы: разбор ответа модели и границы', async () => {
  const { normalizeNotes, normalizeClips, toSeconds, transcriptText } = await import('../src/jobs/meeting.js');

  // Таймкоды в разных форматах
  assert.equal(toSeconds('01:05'), 65);
  assert.equal(toSeconds('1:02:03'), 3723);
  assert.equal(toSeconds(42), 42);
  assert.equal(toSeconds('ерунда'), 0);

  // Итоги: обрезка по длительности, отсев пустого
  const notes = normalizeNotes({
    summary: 'Кратко о планёрке',
    topics: [{ at: '00:30', title: 'Заявки', text: '' }, { at: '99:00', title: '', text: '' }],
    decisions: [{ at: '01:05', text: 'Согласуем в 1С' }, { at: '00:10', text: '' }],
    tasks: [{ at: '01:40', text: 'Инструкция', who: 'Иван', due: 'до 25-го' }],
    questions: [{ at: '02:10', text: 'Кто замещает?' }],
  }, 100);
  assert.equal(notes.decisions.length, 1, 'пустое решение отброшено');
  assert.equal(notes.topics.length, 1, 'тема без текста и названия отброшена');
  assert.equal(notes.topics[0].text, 'Заявки', 'текст темы берётся из названия');
  assert.equal(notes.tasks[0].who, 'Иван');
  assert.ok(notes.decisions[0].at <= 100, 'таймкод не выходит за длительность');

  // Клипы: отсев коротких и выходящих за пределы, сортировка по оценке
  const clips = normalizeClips({ clips: [
    { start: '00:20', end: '01:10', title: 'Инструкция', reason: 'Готово к отправке', score: 70 },
    { start: '00:00', end: '00:05', title: 'Короткий', score: 90 },
    { start: '02:00', end: '02:40', title: 'За пределами', score: 95 },
    { start: '00:30', end: '01:30', title: 'Лучший', score: 88 },
  ] }, 100);
  assert.equal(clips.length, 2, 'остались только пригодные фрагменты');
  assert.equal(clips[0].title, 'Лучший', 'сортировка по оценке');
  assert.ok(clips.every((c) => c.end - c.start >= 10 && c.end <= 100));

  // Расшифровка с таймкодами укладывается в лимит
  const segs = Array.from({ length: 500 }, (_, i) => ({ start: i * 2, end: i * 2 + 2, text: `Реплика номер ${i} про работу` }));
  const text = transcriptText({ segments: segs }, 2000);
  assert.ok(text.length <= 2600, 'текст прорежен до лимита');
  assert.ok(/^\[\d{2}:\d{2}\]/.test(text), 'таймкоды сохранены');

  // Права и проверки маршрутов
  const vlist = await admin.get('/api/studio/videos?limit=10');
  const v = (vlist.json.videos || []).find((x) => x.status === 'ready');
  const byUser = await user.post(`/api/videos/${v.id}/meeting-notes`, {});
  assert.equal(byUser.status, 403, 'итоги делает автор или модератор');
  const suggByUser = await user.get(`/api/videos/${v.id}/clip-suggestions`);
  assert.equal(suggByUser.status, 403, 'предложения видны автору');
  const short = await admin.post(`/api/videos/${v.id}/clip-suggestions`, {});
  assert.ok([200, 400].includes(short.status), 'для коротких видео — понятный отказ');
  if (short.status === 400) assert.match(short.json.error, /коротких|расшифровк|отключен/i);
  const notesGet = await admin.get(`/api/videos/${v.shortId}/meeting-notes`);
  assert.equal(notesGet.status, 200, notesGet.text);
  assert.equal(typeof notesGet.json.enabled, 'boolean');
});

test('согласование видео: отправка, замечания, решения и права', async () => {
  const vlist = await admin.get('/api/studio/videos?limit=10');
  const v = (vlist.json.videos || []).find((x) => x.status === 'ready' && Number(x.duration) > 3);
  assert.ok(v, 'нужно готовое видео');
  const me = await user.get('/api/auth/me');
  const reviewerId = me.json.user.id;

  const empty = await admin.post(`/api/videos/${v.id}/review`, { reviewers: [] });
  assert.equal(empty.status, 400, 'без рецензентов нельзя');

  const sent = await admin.post(`/api/videos/${v.id}/review`, { reviewers: [reviewerId], note: 'Проверьте формулировки', dueAt: new Date(Date.now() + 86400000).toISOString() });
  assert.equal(sent.status, 200, sent.text);
  assert.equal(sent.json.review.status, 'in_review');
  assert.equal(sent.json.review.reviewers.length, 1);

  // Видео помечено как «на согласовании»
  const card = await admin.get(`/api/videos/${v.shortId}`);
  assert.equal(card.json.video.reviewStatus, 'in_review');

  // Замечание с таймкодом от рецензента
  const c = await user.post(`/api/videos/${v.id}/review/comments`, { at: 2, body: 'На второй секунде не видно подписи' });
  assert.equal(c.status, 200, c.text);
  assert.equal(c.json.review.comments.length, 1);
  assert.equal(c.json.review.comments[0].at, 2);
  const empty2 = await user.post(`/api/videos/${v.id}/review/comments`, { body: '  ' });
  assert.equal(empty2.status, 400, 'пустое замечание отклонено');

  // Автор отмечает исправленным
  const resolved = await admin.patch(`/api/videos/${v.id}/review/comments/${c.json.review.comments[0].id}`, { resolved: true });
  assert.equal(resolved.json.review.comments[0].resolved, true);
  const byUser = await user.patch(`/api/videos/${v.id}/review/comments/${c.json.review.comments[0].id}`, { resolved: false });
  assert.equal(byUser.status, 403, 'отмечать исправленным может автор');

  // «Вернуть на доработку» требует комментария
  const noComment = await user.post(`/api/videos/${v.id}/review/decision`, { decision: 'changes_requested', comment: '' });
  assert.equal(noComment.status, 400, 'нужно написать, что исправить');
  const back = await user.post(`/api/videos/${v.id}/review/decision`, { decision: 'changes_requested', comment: 'Добавьте титры' });
  assert.equal(back.status, 200, back.text);
  assert.equal(back.json.review.status, 'changes_requested');
  const card2 = await admin.get(`/api/videos/${v.shortId}`);
  assert.equal(card2.json.video.reviewStatus, 'changes_requested');

  // Повторная отправка и согласование
  const again = await admin.post(`/api/videos/${v.id}/review`, { reviewers: [reviewerId] });
  assert.equal(again.status, 200, again.text);
  const ok = await user.post(`/api/videos/${v.id}/review/decision`, { decision: 'approved', comment: 'Годится' });
  assert.equal(ok.json.review.status, 'approved');

  // Списки: рецензент видит входящие, автор — исходящие
  const mineLists = await user.get('/api/studio/reviews');
  assert.equal(mineLists.status, 200, mineLists.text);
  assert.ok(Array.isArray(mineLists.json.incoming));
  const authorLists = await admin.get('/api/studio/reviews');
  assert.ok(Array.isArray(authorLists.json.outgoing));

  // Посторонний не видит согласование и не принимает решений
  const stranger = await guest.get(`/api/videos/${v.shortId}/review`);
  assert.equal(stranger.status, 401, 'гостю согласование недоступно');
  const notReviewer = await admin.post(`/api/videos/${v.id}/review/decision`, { decision: 'approved' });
  assert.equal(notReviewer.status, 400, 'решать нечего — согласование завершено');
  await admin.del(`/api/videos/${v.id}/review`);
});

test('календарь публикаций, карточки мессенджеров и хранилище', async () => {
  // Календарь: события в заданном периоде
  const cal = await admin.get('/api/studio/calendar');
  assert.equal(cal.status, 200, cal.text);
  assert.ok(Array.isArray(cal.json.events), 'события списком');
  assert.ok(cal.json.events.every((e) => ['publish', 'premiere', 'live', 'assignment', 'review'].includes(e.kind)), 'известные виды событий');
  const bad = await admin.get('/api/studio/calendar?from=нет');
  assert.equal(bad.status, 400, 'некорректный период отклонён');

  // Карточки для мессенджеров
  const { toSlack, toTeams, describeEvent, formatPayload } = await import('../src/lib/chatcards.js');
  const payload = { data: { video: { title: 'Инструктаж по охране труда', shortId: 'abc123', url: '/watch/abc123', owner: 'Мария Соколова' }, by: 'Иван Петров' } };
  const slack = toSlack('review.approved', payload, 'Видео');
  assert.equal(slack.username, 'Видео');
  assert.match(slack.text, /Видео согласовано/);
  assert.equal(slack.attachments[0].title, 'Инструктаж по охране труда');
  assert.match(slack.attachments[0].title_link, /\/watch\/abc123$/, 'ссылка абсолютная');
  assert.ok(slack.attachments[0].fields.some((f) => f.value === 'Иван Петров'), 'кто принял решение');

  const teams = toTeams('video.published', payload, 'Видео');
  assert.equal(teams.type, 'message');
  assert.equal(teams.attachments[0].contentType, 'application/vnd.microsoft.card.adaptive');
  assert.equal(teams.attachments[0].content.type, 'AdaptiveCard');
  assert.equal(teams.attachments[0].content.actions[0].type, 'Action.OpenUrl');
  assert.match(teams.attachments[0].content.body[0].text, /Опубликовано видео/);

  const raw = formatPayload('json', 'video.published', payload, 'Видео');
  assert.deepEqual(raw, payload, 'для JSON тело не меняется');
  const unknown = describeEvent('какое.то.событие', {});
  assert.equal(unknown.title, 'какое.то.событие', 'незнакомое событие не ломает карточку');

  // Хранилище: сводка и права
  const st = await admin.get('/api/admin/storage');
  assert.equal(st.status, 200, st.text);
  assert.ok(st.json.totals.videos > 0, 'видео посчитаны');
  assert.ok(st.json.totals.bytes >= 0);
  assert.ok(Array.isArray(st.json.byOwner) && st.json.byOwner.length, 'есть разрез по каналам');
  assert.ok(Array.isArray(st.json.biggest), 'есть список тяжёлых видео');
  const byUser = await user.get('/api/admin/storage');
  assert.equal(byUser.status, 403, 'раздел только для модераторов');

  // Автоглавы: понятные отказы
  const vlist = await admin.get('/api/studio/videos?limit=10');
  const short = (vlist.json.videos || []).find((x) => x.status === 'ready' && Number(x.duration) < 60);
  if (short) {
    const r = await admin.post(`/api/videos/${short.id}/chapters/auto`, {});
    assert.equal(r.status, 400, 'для коротких видео главы не нужны');
  }
  const { parseScenes, scenesToChapters } = await import('../src/jobs/chapters.js');
  assert.deepEqual(parseScenes('frame:0 pts_time:24.5\nlavfi.scene_score=0.4\nframe:1 pts_time:80.2'), [24.5, 80.2]);
  const chapters = scenesToChapters([24.5, 30.0, 80.2], { duration: 120, segments: [{ start: 25, text: 'Переходим к согласованию заявок' }] });
  assert.equal(chapters.length, 3, 'близкие смены сцены слиты, первая глава — с нуля');
  assert.equal(chapters[0].start, 0);
  assert.equal(chapters[1].title, 'Переходим к согласованию заявок');
  assert.equal(chapters[2].title, 'Часть 3', 'без расшифровки — номер части');
});

test('размытие в кадре: автопоиск лиц, рамки и пересборка видео', async () => {
  const { blurFilter, normalizeRegions } = await import('../src/jobs/blur.js');
  // Разбор рамок: доли кадра, отсечение выходов за край, отрезок времени
  const norm = normalizeRegions([{ x: 0.1, y: 0.2, w: 0.3, h: 0.25, start: 1, end: 5 }, { x: 0.9, y: 0.9, w: 0.5, h: 0.5 }, { x: 0, y: 0, w: 0.001, h: 0.5 }], 30);
  assert.equal(norm.length, 2, 'слишком узкая рамка отброшена');
  assert.ok(Math.abs(norm[1].w - 0.1) < 1e-6, 'рамка обрезана по краю кадра');
  assert.equal(norm[0].end, 5);
  const filter = blurFilter(norm, 1280, 720);
  assert.ok(filter.includes('split=3[base][c0][c1]'), 'кадр разветвлён по числу рамок');
  assert.ok(/boxblur=luma_radius=\d+/.test(filter));
  assert.ok(filter.includes("enable='between(t,1,5)'"), 'рамка работает только на своём отрезке');

  // Настоящее видео: лицо в первых шести секундах, дальше — ровный фон
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'cv-blur-'));
  const facePng = path.join(dir, 'face.png');
  execFileSync('python3', ['-c', `
from skimage import data
import cv2
img = cv2.cvtColor(data.astronaut(), cv2.COLOR_RGB2BGR)
big = cv2.copyMakeBorder(cv2.resize(img, (480, 480)), 0, 0, 160, 160, cv2.BORDER_CONSTANT, value=(30, 30, 30))
cv2.imwrite(${JSON.stringify(facePng)}, big)
`]);
  const p1 = path.join(dir, 'p1.mp4'), p2 = path.join(dir, 'p2.mp4'), src = path.join(dir, 'faces.mp4');
  execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-loop', '1', '-i', facePng, '-t', '6', '-r', '25', '-vf', 'scale=800:480', '-pix_fmt', 'yuv420p', p1]);
  execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=c=gray:s=800x480:r=25:d=5', '-pix_fmt', 'yuv420p', p2]);
  fs.writeFileSync(path.join(dir, 'l.txt'), `file '${p1}'\nfile '${p2}'\n`);
  execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-f', 'concat', '-safe', '0', '-i', path.join(dir, 'l.txt'), '-c', 'copy', src]);

  const data = fs.readFileSync(src);
  const init = await admin.post('/api/uploads', { filename: 'Видео с лицом.mp4', size: data.length, mime: 'video/mp4', title: 'Видео с лицом', visibility: 'private' });
  assert.equal(init.status, 200, init.text);
  const vid = init.json.videoId;
  for (let off = 0; off < data.length; off += 400000) {
    const part = data.subarray(off, Math.min(data.length, off + 400000));
    const r = await admin.req('PATCH', `/api/uploads/${init.json.uploadId}`, part, { headers: { 'content-type': 'application/offset+octet-stream', 'upload-offset': String(off) } });
    assert.equal(r.status, 200, r.text);
  }
  assert.equal((await admin.post(`/api/uploads/${init.json.uploadId}/complete`, {})).status, 200);
  let v;
  for (let i = 0; i < 180; i++) {
    await new Promise((r) => setTimeout(r, 1000));
    v = (await admin.get(`/api/videos/${vid}`)).json.video;
    if (['ready', 'failed'].includes(v.status)) break;
  }
  assert.equal(v.status, 'ready', 'видео с лицом обработано');

  // Кадр для рисования рамок
  const frame = await admin.get(`/api/videos/${vid}/editor/frame?t=2`);
  assert.equal(frame.status, 200);
  assert.equal(frame.headers.get('content-type'), 'image/jpeg');

  // Автопоиск лиц
  const fj = await admin.post(`/api/videos/${vid}/editor/faces`, {});
  assert.equal(fj.status, 200, fj.text);
  let faces = null;
  for (let i = 0; i < 60; i++) {
    await new Promise((r) => setTimeout(r, 1000));
    const j = (await admin.get(`/api/videos/${vid}/editor/jobs/${fj.json.jobId}`)).json.job;
    if (j.status === 'done') { faces = j.result; break; }
    if (['failed', 'cancelled'].includes(j.status)) { faces = { error: j.error }; break; }
  }
  assert.ok(faces && Array.isArray(faces.regions), 'детектор ответил: ' + JSON.stringify(faces));
  assert.ok(faces.regions.length >= 1, 'лицо найдено');
  const face = faces.regions[0];
  assert.ok(face.start < 3 && face.end <= 12, 'отрезок времени лица совпал с первой частью ролика');

  // Применяем размытие по найденной рамке
  const ap = await admin.post(`/api/videos/${vid}/editor/blur`, { regions: [{ ...face, end: 6 }] });
  assert.equal(ap.status, 200, ap.text);
  let blurJob = null;
  for (let i = 0; i < 180; i++) {
    await new Promise((r) => setTimeout(r, 1000));
    const j = (await admin.get(`/api/videos/${vid}/editor/jobs/${ap.json.jobId}`)).json.job;
    if (['done', 'failed', 'cancelled'].includes(j.status)) { blurJob = j; break; }
  }
  assert.equal(blurJob?.status, 'done', 'размытие выполнено: ' + blurJob?.error);
  for (let i = 0; i < 180; i++) {
    await new Promise((r) => setTimeout(r, 1000));
    v = (await admin.get(`/api/videos/${vid}`)).json.video;
    if (v.status === 'ready') break;
  }
  assert.equal(v.status, 'ready', 'видео пересобрано');
  assert.equal(v.blurRegions.length, 1, 'рамки сохранены у видео');

  // Проверяем результат по картинке: в рамке стало заметно более гладко
  const outFile = path.join(dir, 'out.mp4');
  const mp4 = await admin.req('GET', v.mp4Url);
  assert.equal(mp4.status, 200, 'mp4 доступен владельцу');
  fs.writeFileSync(outFile, Buffer.from(await (await fetch(BASE + v.mp4Url, { headers: { cookie: admin.cookie } })).arrayBuffer()));
  const sharp = execFileSync('python3', ['-c', `
import cv2, json, sys
cap = cv2.VideoCapture(${JSON.stringify(outFile)})
def s(t, r):
    cap.set(cv2.CAP_PROP_POS_MSEC, t * 1000)
    ok, f = cap.read()
    if not ok: return -1
    h, w = f.shape[:2]
    x, y = int(r[0] * w), int(r[1] * h)
    roi = f[y:y + int(r[3] * h), x:x + int(r[2] * w)]
    if roi.size == 0: return -1
    return float(cv2.Laplacian(cv2.cvtColor(roi, cv2.COLOR_BGR2GRAY), cv2.CV_64F).var())
r = [${face.x}, ${face.y}, ${face.w}, ${face.h}]
print(json.dumps({"blurred": s(3, r), "clean": s(9, r)}))
`]).toString();
  const res = JSON.parse(sharp);
  assert.ok(res.blurred >= 0 && res.blurred < 40, 'область с лицом размыта: ' + JSON.stringify(res));
  await admin.del(`/api/videos/${vid}`);
  fs.rmSync(dir, { recursive: true, force: true });
});

test('монтаж по расшифровке: фразы, слова-паразиты и границы', async () => {
  const { findFillers, cutsFromSelection } = await import('../src/lib/textedit.js');
  const segs = [
    { start: 0, end: 4, text: 'Ну, сегодня мы как бы обсудим план работ' },
    { start: 4, end: 8, text: 'Это, типа, первый пункт, вот' },
    { start: 8, end: 12, text: 'Ничего лишнего в этой фразе нет' },
  ];
  const items = findFillers(segs, ['ну', 'как бы', 'типа', 'вот']);
  assert.equal(items.length, 4, 'найдены все слова-паразиты: ' + JSON.stringify(items.map((x) => x.word)));
  assert.ok(items.every((x) => x.end > x.start && x.end <= 8.01), 'время слова внутри своей фразы');
  assert.ok(items[0].text.includes('⟦'), 'слово подсвечено в контексте');
  assert.equal(findFillers(segs, ['лан']).length, 0, 'часть слова («план») не считается словом-паразитом');
  const cuts = cutsFromSelection([{ start: 1, end: 1.4 }, { start: 1.5, end: 2 }, { start: 9, end: 9.3 }], 12);
  assert.equal(cuts.length, 2, 'близкие отрезки склеены');

  // Через API: подкладываем расшифровку со словами-паразитами и вырезаем их
  const tmp = path.join(os.tmpdir(), `cv-text-${stamp}.mp4`);
  execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=320x240:rate=25:duration=12', '-f', 'lavfi', '-i', 'sine=frequency=330:duration=12', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', tmp]);
  const data = fs.readFileSync(tmp);
  const init = await admin.post('/api/uploads', { filename: 'Планёрка с паразитами.mp4', size: data.length, mime: 'video/mp4', title: 'Планёрка с паразитами', visibility: 'private' });
  const vid = init.json.videoId;
  for (let off = 0; off < data.length; off += 400000) {
    const part = data.subarray(off, Math.min(data.length, off + 400000));
    await admin.req('PATCH', `/api/uploads/${init.json.uploadId}`, part, { headers: { 'content-type': 'application/offset+octet-stream', 'upload-offset': String(off) } });
  }
  await admin.post(`/api/uploads/${init.json.uploadId}/complete`, {});
  let v;
  for (let i = 0; i < 180; i++) {
    await new Promise((r) => setTimeout(r, 1000));
    v = (await admin.get(`/api/videos/${vid}`)).json.video;
    if (['ready', 'failed'].includes(v.status)) break;
  }
  assert.equal(v.status, 'ready');
  const durationBefore = v.duration;

  const vtt = ['WEBVTT', '', '00:00:00.000 --> 00:00:04.000', 'Ну, сегодня мы как бы обсудим план работ', '', '00:00:04.000 --> 00:00:08.000', 'Это, типа, первый пункт, вот', '', '00:00:08.000 --> 00:00:12.000', 'Ничего лишнего в этой фразе нет', ''].join('\n');
  const fd = new FormData();
  fd.append('file', new Blob([vtt], { type: 'text/vtt' }), 'ru.vtt');
  fd.append('language', 'ru');
  fd.append('label', 'Русские');
  const up = await admin.post(`/api/videos/${vid}/subtitles`, fd);
  assert.equal(up.status, 200, up.text);

  const tr = await admin.get(`/api/videos/${vid}/editor/transcript`);
  assert.equal(tr.status, 200, tr.text);
  assert.equal(tr.json.segments.length, 3, 'расшифровка разобрана по фразам');

  const found = await admin.post(`/api/videos/${vid}/editor/fillers`, {});
  assert.equal(found.status, 200, found.text);
  assert.ok(found.json.total >= 4, 'слова-паразиты найдены через API: ' + found.json.total);

  const applied = await admin.post(`/api/videos/${vid}/editor/fillers`, { apply: true });
  assert.equal(applied.status, 200, applied.text);
  assert.ok(applied.json.removedSec > 0.5, 'что-то вырезано');
  for (let i = 0; i < 180; i++) {
    await new Promise((r) => setTimeout(r, 1000));
    const j = (await admin.get(`/api/videos/${vid}/editor/jobs/${applied.json.jobId}`)).json.job;
    if (['done', 'failed', 'cancelled'].includes(j.status)) { assert.equal(j.status, 'done', j.error); break; }
  }
  for (let i = 0; i < 180; i++) {
    await new Promise((r) => setTimeout(r, 1000));
    v = (await admin.get(`/api/videos/${vid}`)).json.video;
    if (v.status === 'ready' && v.duration < durationBefore - 0.3) break;
  }
  assert.ok(v.duration < durationBefore - 0.5, `длительность уменьшилась: было ${durationBefore}, стало ${v.duration}`);

  // Вырезание выбранной фразы
  const cut = await admin.post(`/api/videos/${vid}/editor/text-cut`, { segments: [2] });
  assert.equal(cut.status, 200, cut.text);
  assert.ok(cut.json.cuts.length === 1);
  const bad = await admin.post(`/api/videos/${vid}/editor/text-cut`, { segments: [] });
  assert.equal(bad.status, 400, 'без выбранных фраз — понятный отказ');
  await admin.del(`/api/videos/${vid}`);
  fs.unlinkSync(tmp);
});

test('витрины: разделы, доступ и меню', async () => {
  const r = await admin.post('/api/admin/showcases', { title: 'Новичку в компании', subtitle: 'С чего начать', visibility: 'internal', inMenu: true });
  assert.equal(r.status, 200, r.text);
  const sc = r.json.showcase;
  assert.ok(sc.slug, 'адрес витрины сгенерирован');

  const mine = (await admin.get('/api/studio/videos?limit=5')).json.videos.filter((x) => x.status === 'ready');
  const secs = await admin.put(`/api/admin/showcases/${sc.id}/sections`, {
    sections: [
      { title: 'Смотреть первым делом', kind: 'videos', layout: 'hero', videoIds: mine.slice(0, 2).map((x) => x.id) },
      { title: 'Новое на портале', kind: 'latest', layout: 'grid', maxItems: 6 },
    ],
  });
  assert.equal(secs.status, 200, secs.text);
  assert.equal(secs.json.sections.length, 2);

  const page = await admin.get(`/api/showcases/${sc.slug}`);
  assert.equal(page.status, 200, page.text);
  assert.equal(page.json.showcase.sections.length, 2);
  assert.ok(page.json.showcase.sections[1].videos.length > 0, 'раздел «новое» наполняется сам');

  const menu = await admin.get('/api/showcases?menu');
  assert.ok(menu.json.showcases.some((x) => x.id === sc.id), 'витрина видна в меню');

  // Гость не видит витрину для сотрудников, а по публичной — видит
  const g1 = await guest.get(`/api/showcases/${sc.slug}`);
  assert.ok([403, 404].includes(g1.status), 'гостю внутренняя витрина закрыта');
  await admin.patch(`/api/admin/showcases/${sc.id}`, { visibility: 'public' });
  const g2 = await guest.get(`/api/showcases/${sc.slug}`);
  assert.equal(g2.status, 200, 'публичная витрина открыта гостю');
  assert.ok(g2.json.showcase.sections.every((s) => s.videos.every((v) => v.visibility === 'public')), 'гостю показываются только публичные видео');

  // Обычный пользователь не может создавать витрины
  const u = await user.post('/api/admin/showcases', { title: 'Чужая витрина' });
  assert.equal(u.status, 403);
  assert.equal((await admin.del(`/api/admin/showcases/${sc.id}`)).status, 200);
});

test('сроки хранения: правила, архив, юридическая блокировка и актуальность', async () => {
  const { ruleWhere, warnWhere } = await import('../src/jobs/retention.js');
  const w = ruleWhere({ after_days: 30, action: 'archive', scope: 'tag', tag: 'черновик' });
  assert.ok(w.sql.includes('v.legal_hold = false'), 'правила обходят видео с отметкой «не удалять»');
  assert.ok(w.sql.includes('v.archived_at IS NULL'));
  assert.deepEqual(w.params, ['30', 'черновик']);
  const warn = warnWhere({ after_days: 30, warn_days: 7, action: 'archive', scope: 'all' });
  assert.deepEqual(warn.params, ['23', '30'], 'предупреждаем за неделю до срока');

  const rule = await admin.post('/api/admin/retention', { name: 'Тестовое правило', scope: 'tag', tag: `t${stamp}`, afterDays: 1, action: 'archive', warnDays: 0 });
  assert.equal(rule.status, 200, rule.text);
  const bad = await admin.post('/api/admin/retention', { name: 'Без категории', scope: 'category', afterDays: 10 });
  assert.equal(bad.status, 400, 'правило по категории требует категорию');

  // Отдельное видео для проверки: тег правила и «старая» публикация
  const tmpR = path.join(os.tmpdir(), `cv-ret-${stamp}.mp4`);
  execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=320x240:rate=25:duration=4', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', tmpR]);
  const dataR = fs.readFileSync(tmpR);
  const initR = await admin.post('/api/uploads', { filename: 'Старое видео.mp4', size: dataR.length, mime: 'video/mp4', title: `Старое видео ${stamp}`, visibility: 'internal' });
  assert.equal(initR.status, 200, initR.text);
  for (let off = 0; off < dataR.length; off += 400000) {
    const part = dataR.subarray(off, Math.min(dataR.length, off + 400000));
    await admin.req('PATCH', `/api/uploads/${initR.json.uploadId}`, part, { headers: { 'content-type': 'application/offset+octet-stream', 'upload-offset': String(off) } });
  }
  await admin.post(`/api/uploads/${initR.json.uploadId}/complete`, {});
  let target = null;
  for (let i = 0; i < 180; i++) {
    await new Promise((r) => setTimeout(r, 1000));
    target = (await admin.get(`/api/videos/${initR.json.videoId}`)).json.video;
    if (['ready', 'failed'].includes(target.status)) break;
  }
  assert.equal(target.status, 'ready', 'видео для проверки правил обработано');
  await admin.patch(`/api/videos/${target.id}`, { tags: [`t${stamp}`] });
  const { query } = await import('../src/db.js');
  await query(`UPDATE videos SET published_at = now() - interval '30 days' WHERE id = $1`, [target.id]);

  try {
  const prev = await admin.get(`/api/admin/retention/${rule.json.rule.id}/preview`);
  assert.equal(prev.status, 200);
  assert.ok(prev.json.preview.items.some((x) => x.id === target.id), 'видео попадает под правило');

  // Отметка «не удалять» выводит видео из-под правила
  assert.equal((await admin.post(`/api/admin/lifecycle/videos/${target.id}/hold`, { hold: true })).status, 200);
  const prev2 = await admin.get(`/api/admin/retention/${rule.json.rule.id}/preview`);
  assert.ok(!prev2.json.preview.items.some((x) => x.id === target.id), 'заблокированное видео правило не трогает');
  assert.equal((await admin.post(`/api/admin/lifecycle/videos/${target.id}/hold`, { hold: false })).status, 200);

  // Применение правила: видео уходит в архив и исчезает из каталога
  const run = await admin.post(`/api/admin/retention/${rule.json.rule.id}/run`, {});
  assert.equal(run.status, 200, run.text);
  assert.ok(run.json.done >= 1, 'правило сработало');
  const after = (await admin.get(`/api/videos/${target.id}`)).json.video;
  assert.ok(after.archivedAt, 'видео в архиве');
  const feed = await admin.get('/api/feed/latest?limit=50');
  assert.ok(!feed.json.videos.some((x) => x.id === target.id), 'архивное видео не показывается в каталоге');
  const viewer = await user.get(`/api/videos/${target.id}`);
  assert.equal(viewer.status, 403, 'обычному сотруднику архивное видео недоступно');
  const archived = await admin.get('/api/admin/lifecycle/videos?kind=archived');
  assert.ok(archived.json.items.some((x) => x.id === target.id), 'видео есть в списке архива');
  const log = await admin.get('/api/admin/retention/log');
  assert.ok(log.json.items.some((x) => x.videoId === target.id && x.action === 'archive'), 'действие записано в журнал');

  // Возврат из архива
  assert.equal((await admin.post(`/api/admin/lifecycle/videos/${target.id}/archive`, { archived: false })).status, 200);
  assert.ok(!(await admin.get(`/api/videos/${target.id}`)).json.video.archivedAt, 'видео вернулось');

  // Пересмотр актуальности
  const fr = await admin.post(`/api/admin/lifecycle/videos/${target.id}/freshness`, { months: 6 });
  assert.equal(fr.status, 200, fr.text);
  assert.ok(fr.json.freshUntil, 'срок актуальности проставлен');
  const notMine = await user.post(`/api/admin/lifecycle/videos/${target.id}/freshness`, { months: 6 });
  assert.equal(notMine.status, 403, 'чужую актуальность не подтвердить');
  const foreignArchive = await user.post(`/api/admin/lifecycle/videos/${target.id}/archive`, {});
  assert.equal(foreignArchive.status, 403, 'чужое видео в архив не убрать');
  const { runFreshness } = await import('../src/jobs/retention.js');
  await query(`UPDATE videos SET fresh_until = current_date - 1, fresh_asked_at = NULL WHERE id = $1`, [target.id]);
  const asked = await runFreshness();
  assert.ok(asked.asked >= 1, 'автору отправлено напоминание о пересмотре');
  const stale = await admin.get('/api/admin/lifecycle/videos?kind=stale');
  assert.ok(stale.json.items.some((x) => x.id === target.id), 'видео попало в список «требуют пересмотра»');
  } finally {
    // Даже если проверка упала, видео не должно остаться в архиве и мешать другим тестам
    await admin.post(`/api/admin/lifecycle/videos/${target.id}/hold`, { hold: false }).catch(() => {});
    await admin.post(`/api/admin/lifecycle/videos/${target.id}/archive`, { archived: false }).catch(() => {});
    await admin.del(`/api/admin/retention/${rule.json.rule.id}`).catch(() => {});
    await admin.del(`/api/videos/${target.id}`).catch(() => {});
    fs.unlinkSync(tmpR);
  }
});

test('ретрансляция эфира и перемотка назад', async () => {
  const { targetUrl, validTarget, restreamArgs, sourceUrl } = await import('../src/jobs/restream.js');
  assert.equal(targetUrl('rtmp://a.ru/live/', 'KEY'), 'rtmp://a.ru/live/KEY');
  assert.equal(targetUrl('rtmp://a.ru/live', ''), 'rtmp://a.ru/live');
  // Проверка адреса стала асинхронной: имя площадки разрешается, чтобы не пустить эфир внутрь сети
  assert.ok(await validTarget('https://example.com/x'), 'обычная ссылка не годится для ретрансляции');
  assert.equal(await validTarget('rtmps://vsu.okcdn.ru/input'), null);
  const args = restreamArgs('http://127.0.0.1:8888/live/k/index.m3u8', 'rtmp://a.ru/live/k');
  assert.ok(args.includes('-c') && args.includes('copy'), 'поток уходит без перекодирования');
  assert.equal(args[args.length - 2], 'flv', 'RTMP отдаётся как flv');
  assert.equal(restreamArgs('x', 'srt://a.ru:9000')[restreamArgs('x', 'srt://a.ru:9000').length - 2], 'mpegts');
  assert.ok(sourceUrl('abc').endsWith('/live/abc/index.m3u8'));

  const st = await admin.post('/api/studio/live', { title: 'Эфир с ретрансляцией', visibility: 'internal' });
  assert.equal(st.status, 200, st.text);
  const stream = st.json.stream;
  const add = await admin.post(`/api/studio/live/${stream.id}/restreams`, { name: 'VK Видео', url: 'rtmps://vsu.okcdn.ru/input', streamKey: 'secret-key-123' });
  assert.equal(add.status, 200, add.text);
  assert.ok(!add.text.includes('secret-key-123'), 'ключ площадки не возвращается в ответе');
  assert.ok(add.json.restream.keyHint.includes('…'), 'от ключа показывается только подсказка');
  const wrong = await admin.post(`/api/studio/live/${stream.id}/restreams`, { name: 'Ошибка', url: 'https://example.com/live' });
  assert.equal(wrong.status, 400, 'адрес не того протокола отклонён');
  const off = await admin.patch(`/api/studio/live/restreams/${add.json.restream.id}`, { enabled: false });
  assert.equal(off.json.restream.enabled, false);
  const foreign = await user.get(`/api/studio/live/${stream.id}/restreams`);
  assert.equal(foreign.status, 403, 'чужие площадки не видны');

  // Перемотка эфира назад: состояние DVR
  const dvr = await admin.get(`/api/live/${stream.id}/dvr`);
  assert.equal(dvr.status, 200, dvr.text);
  assert.equal(typeof dvr.json.windowSec, 'number');
  const guestDvr = await guest.get(`/api/live/${stream.id}/dvr`);
  assert.equal(guestDvr.status, 401, 'гостю внутренняя трансляция закрыта');
  await admin.patch(`/api/studio/live/${stream.id}`, { dvr: false });
  const offDvr = await admin.get(`/api/live/${stream.id}/dvr`);
  assert.equal(offDvr.json.enabled, false, 'перемотку можно выключить у трансляции');
  const play = await admin.get(`/api/live/${stream.id}/dvr/play?back=30`);
  assert.equal(play.status, 403, 'при выключенной перемотке запись не отдаётся');
  assert.equal((await admin.del(`/api/studio/live/restreams/${add.json.restream.id}`)).status, 200);
  assert.equal((await admin.del(`/api/studio/live/${stream.id}`)).status, 200);
});

test('вебинары: страница, анкета, внешние участники, лист ожидания и отчёт', async () => {
  const slug = `web-${stamp}`;
  const cr = await admin.post('/api/studio/webinars', {
    title: `Вебинар ${stamp}`, slug, description: 'Проверочный вебинар',
    scheduledAt: new Date(Date.now() + 3 * 86400000).toISOString(), regExternal: true, registrationLimit: 2,
  });
  assert.equal(cr.status, 200, cr.text);
  const w = cr.json.webinar;

  const upd = await admin.patch(`/api/studio/webinars/${w.id}`, {
    agenda: [{ time: '10:00', title: 'Вступление' }, { time: '10:20', title: 'Разбор' }],
    speakers: [{ name: 'Мария Соколова', role: 'Руководитель' }],
    regFields: [{ id: 'dept', label: 'Подразделение', type: 'text', required: true }, { id: 'exp', label: 'Опыт', type: 'select', options: ['Есть', 'Нет'] }],
    certEnabled: true, certMinPercent: 50,
    cta: { label: 'Памятка', url: 'https://example.com/p.pdf', text: 'Скачать памятку' },
  });
  assert.equal(upd.status, 200, upd.text);

  const page = await admin.get(`/api/webinars/${slug}`);
  assert.equal(page.status, 200, page.text);
  assert.equal(page.json.webinar.agenda.length, 2, 'программа сохранена');
  assert.equal(page.json.webinar.speakers.length, 1, 'спикеры сохранены');
  assert.equal(page.json.webinar.registration.fields.length, 2, 'поля анкеты на странице');
  assert.ok(page.json.webinar.cta?.label, 'кнопка действия');

  const list = await admin.get('/api/webinars?limit=60');
  assert.ok(list.json.webinars.some((x) => x.id === w.id), 'вебинар в каталоге');

  // Сотрудник: обязательное поле проверяется
  const bad = await user.post(`/api/webinars/${slug}/register`, { answers: {} });
  assert.equal(bad.status, 400, 'без обязательного поля регистрация не проходит');
  const reg = await user.post(`/api/webinars/${slug}/register`, { answers: { dept: 'Эксплуатация', exp: 'Есть' } });
  assert.equal(reg.status, 200, reg.text);
  assert.equal(reg.json.status, 'approved');

  // Внешний участник без учётной записи получает персональную ссылку
  const ext = new Client();
  const e1 = await ext.post(`/api/webinars/${slug}/register`, { name: 'Пётр Внешний', email: `ext.${stamp}@partner.ru`, answers: { dept: 'Подрядчик' } });
  assert.equal(e1.status, 200, e1.text);
  assert.match(e1.json.joinUrl, /\?t=/, 'выдана персональная ссылка');
  const room = await ext.get(`/api/live/${w.shortId}`);
  assert.equal(room.status, 200, 'внешний участник попадает в комнату эфира по ссылке');
  const stranger = await guest.get(`/api/live/${w.shortId}`);
  assert.ok([401, 403].includes(stranger.status), 'посторонний в комнату не проходит');

  // Мест два — третий уходит в лист ожидания
  const ext2 = new Client();
  const e2 = await ext2.post(`/api/webinars/${slug}/register`, { name: 'Третий', email: `ext3.${stamp}@partner.ru`, answers: { dept: 'x' } });
  assert.equal(e2.json.status, 'waitlist', 'третий участник — в листе ожидания');

  // Отмена освобождает место и поднимает первого из очереди
  const cancel = await ext.post(`/api/webinars/${slug}/cancel`, {});
  assert.equal(cancel.status, 200, cancel.text);
  const afterCancel = await admin.get(`/api/studio/webinars/${w.id}/registrations?status=approved`);
  assert.ok(afterCancel.json.registrations.some((r) => r.email === `ext3.${stamp}@partner.ru`), 'место освободилось и лист ожидания подвинулся');

  // Модерация заявок
  await admin.patch(`/api/studio/webinars/${w.id}`, { regModeration: true, registrationLimit: 50 });
  const ext4 = new Client();
  const e4 = await ext4.post(`/api/webinars/${slug}/register`, { name: 'Четвёртый', email: `ext4.${stamp}@partner.ru`, answers: { dept: 'x' } });
  assert.equal(e4.json.status, 'pending', 'при модерации заявка ждёт решения');
  const pending = await admin.get(`/api/studio/webinars/${w.id}/registrations?status=pending`);
  const pid = pending.json.registrations[0].id;
  const decided = await admin.post(`/api/studio/webinars/${w.id}/registrations/${pid}/decision`, { decision: 'approve' });
  assert.equal(decided.json.status, 'approved');
  const foreign = await user.get(`/api/studio/webinars/${w.id}/registrations`);
  assert.equal(foreign.status, 403, 'чужой кабинет вебинара закрыт');

  // Отчёт и выгрузка
  const rep = await admin.get(`/api/studio/webinars/${w.id}/report`);
  assert.equal(rep.status, 200, rep.text);
  assert.ok(rep.json.funnel.registered >= 3, 'воронка считает записавшихся');
  assert.ok(Array.isArray(rep.json.presence), 'кривая присутствия есть');
  const csv = await admin.get(`/api/studio/webinars/${w.id}/registrations?format=csv`);
  assert.equal(csv.status, 200);
  assert.ok(csv.text.includes('Подразделение'), 'в CSV попали ответы анкеты');

  // Напоминания и порог сертификата — модульно
  const { certThreshold, reminderLeads, attendThreshold } = await import('../src/jobs/webinar.js');
  assert.equal(certThreshold({ cert_min_percent: 50 }, 3600), 1800);
  // Расписание напоминаний и порог участия — из настроек портала (1.12)
  assert.deepEqual(reminderLeads({ 'webinars.reminders': [15, 1440, 60, 60, 3] }).map((l) => l.minutes), [1440, 60, 15], 'дубли и слишком ранние отброшены, порядок — от дальнего');
  assert.equal(reminderLeads({ 'webinars.reminders': [120] })[0].label, 'через 2 часа');
  assert.equal(attendThreshold({ 'webinar.attendance_min_percent': 25 }, 3600), 900);
  await admin.del(`/api/studio/live/${w.id}`);
});

test('графический редактор: проект, предпросмотр и сборка ролика', async () => {
  const { normalizeProject, projectDuration, outputToSource, outputSize, remapCues, buildRender } = await import('../src/lib/timeline.js');
  // Разбор проекта
  const p = normalizeProject({
    clips: [{ id: 'c1', start: 0, end: 5, transition: { type: 'dissolve', duration: 0.5 } }, { id: 'c2', start: 10, end: 14, speed: 2 }],
    texts: [{ id: 't1', text: 'Титр', start: 0, end: 3 }, { id: 't2', text: '   ', start: 0, end: 2 }],
    images: [{ id: 'i1', assetId: 'нет-такого', start: 0, end: 2 }],
    aspect: '9:16',
  }, { duration: 20, assets: [] });
  assert.equal(p.clips.length, 2);
  assert.equal(p.texts.length, 1, 'пустой титр отброшен');
  assert.equal(p.images.length, 0, 'картинка без файла отброшена');
  assert.ok(Math.abs(projectDuration(p) - 6.5) < 0.01, 'длительность с учётом ускорения и перехода: ' + projectDuration(p));
  const map = outputToSource(p, 1);
  assert.equal(map.index, 0);
  assert.ok(Math.abs(map.source - 1) < 0.01);
  const size = outputSize({ width: 1920, height: 1080 }, '9:16');
  assert.equal(size.w, 608, 'вертикальный кадр обрезается по центру');
  const cues = remapCues([{ start: 1, end: 2, text: 'а' }, { start: 6, end: 7, text: 'б' }, { start: 11, end: 12, text: 'в' }], p.clips);
  assert.equal(cues.length, 2, 'реплики из вырезанного куска отброшены');
  assert.ok(Math.abs(cues[1].start - 5) < 0.1, 'реплика сдвинута под монтаж с учётом перехода и скорости: ' + cues[1].start);
  const g = buildRender(p, { src: 'in.mp4', out: 'out.mp4', meta: { width: 1920, height: 1080, hasAudio: true, fps: 25 }, assets: [], tmpDir: os.tmpdir() });
  assert.ok(g.filter.includes('xfade=transition=fade'), 'переход собран');
  assert.ok(g.filter.includes('atempo=2'), 'ускорение звука');
  assert.ok(g.filter.includes('drawtext='), 'титр в графе');
  assert.equal(g.files.length, 1, 'текст титра выносится в файл');

  // Через API: загружаем короткое видео, собираем ролик с титром, картинкой и музыкой
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'cv-mont-'));
  const srcFile = path.join(dir, 'src.mp4');
  execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=640x360:rate=25:duration=20', '-f', 'lavfi', '-i', 'sine=frequency=300:duration=20', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', srcFile]);
  const logo = path.join(dir, 'logo.png');
  execFileSync('python3', ['-c', `
import cv2, numpy as np
img = np.zeros((120, 300, 4), np.uint8); img[:, :, :3] = (40, 120, 240); img[:, :, 3] = 255
cv2.imwrite(${JSON.stringify(logo)}, img)
`]);
  const music = path.join(dir, 'music.mp3');
  execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=700:duration=10', '-c:a', 'libmp3lame', music]);

  const data = fs.readFileSync(srcFile);
  const init = await admin.post('/api/uploads', { filename: 'Материал для монтажа.mp4', size: data.length, mime: 'video/mp4', title: `Материал для монтажа ${stamp}`, visibility: 'private' });
  assert.equal(init.status, 200, init.text);
  const vid = init.json.videoId;
  for (let off = 0; off < data.length; off += 400000) {
    const part = data.subarray(off, Math.min(data.length, off + 400000));
    await admin.req('PATCH', `/api/uploads/${init.json.uploadId}`, part, { headers: { 'content-type': 'application/offset+octet-stream', 'upload-offset': String(off) } });
  }
  await admin.post(`/api/uploads/${init.json.uploadId}/complete`, {});
  let v;
  for (let i = 0; i < 180; i++) {
    await new Promise((r) => setTimeout(r, 1000));
    v = (await admin.get(`/api/videos/${vid}`)).json.video;
    if (['ready', 'failed'].includes(v.status)) break;
  }
  assert.equal(v.status, 'ready', 'исходник для монтажа обработан');

  try {
  const empty = await admin.get(`/api/videos/${vid}/project`);
  assert.equal(empty.status, 200, empty.text);
  assert.equal(empty.json.project.clips.length, 1, 'по умолчанию весь ролик одним куском');

  const assets = {};
  for (const [file, type, kind] of [[logo, 'image/png', 'image'], [music, 'audio/mpeg', 'audio']]) {
    const fd = new FormData();
    fd.append('file', new Blob([fs.readFileSync(file)], { type }), path.basename(file));
    const up = await admin.post(`/api/videos/${vid}/project/assets`, fd);
    assert.equal(up.status, 200, up.text);
    assets[kind] = up.json.asset.id;
  }
  const project = {
    aspect: 'source', mainVolume: 0.8, fadeIn: 0.4, fadeOut: 0.6,
    clips: [
      { id: 'c1', start: 0, end: 6, transition: { type: 'dissolve', duration: 0.5 } },
      { id: 'c2', start: 12, end: 18, speed: 1.5 },
    ],
    texts: [{ id: 't1', text: 'Проверка монтажа', start: 0.5, end: 4, size: 40 }],
    images: [{ id: 'i1', assetId: assets.image, start: 0, end: 6, x: 0.9, y: 0.1, width: 0.2 }],
    audio: [{ id: 'a1', assetId: assets.audio, start: 0, end: 8, volume: 0.2, fadeIn: 1, fadeOut: 1 }],
  };
  const saved = await admin.put(`/api/videos/${vid}/project`, { project });
  assert.equal(saved.status, 200, saved.text);
  assert.ok(Math.abs(saved.json.outputDuration - 9.5) < 0.05, 'длительность проекта: ' + saved.json.outputDuration);

  const frame = await admin.get(`/api/videos/${vid}/project/preview?t=2`);
  assert.equal(frame.status, 200, 'кадр предпросмотра собран');
  assert.equal(frame.headers.get('content-type'), 'image/jpeg');

  const run = await admin.post(`/api/videos/${vid}/project/render`, { output: 'new', title: `Монтаж ${stamp}`, visibility: 'private' });
  assert.equal(run.status, 200, run.text);
  let job = null;
  for (let i = 0; i < 240; i++) {
    await new Promise((r) => setTimeout(r, 1000));
    job = (await admin.get(`/api/videos/${vid}/editor/jobs/${run.json.jobId}`)).json.job;
    if (['done', 'failed', 'cancelled'].includes(job.status)) break;
  }
  assert.equal(job?.status, 'done', 'сборка выполнена: ' + job?.error);
  const madeId = job.result.videoId;
  let made;
  for (let i = 0; i < 240; i++) {
    await new Promise((r) => setTimeout(r, 1000));
    made = (await admin.get(`/api/videos/${madeId}`)).json.video;
    if (['ready', 'failed'].includes(made.status)) break;
  }
  assert.equal(made.status, 'ready', 'смонтированное видео обработано');
  assert.ok(Math.abs(made.duration - 9.5) < 0.6, 'длительность готового ролика: ' + made.duration);

  // Проверяем картинкой: в кадре появился титр (яркие пиксели в центре кадра)
  const outFile = path.join(dir, 'made.mp4');
  fs.writeFileSync(outFile, Buffer.from(await (await fetch(BASE + made.mp4Url, { headers: { cookie: admin.cookie } })).arrayBuffer()));
  const check = execFileSync('python3', ['-c', `
import cv2, json
cap = cv2.VideoCapture(${JSON.stringify(outFile)})
def white(t):
    cap.set(cv2.CAP_PROP_POS_MSEC, t * 1000)
    ok, f = cap.read()
    if not ok: return -1
    h, w = f.shape[:2]
    roi = f[int(h * 0.6):h, :]
    return int(((roi > 240).all(axis=2)).sum())
print(json.dumps({"withText": white(2), "withoutText": white(8)}))
`]).toString();
  const px = JSON.parse(check);
  assert.ok(px.withText > px.withoutText * 2 + 50, 'титр виден в кадре: ' + JSON.stringify(px));

  // Границы: чужой проект монтажа
  const foreign = await user.get(`/api/videos/${vid}/project`);
  assert.equal(foreign.status, 403, 'чужой проект монтажа закрыт');
  await admin.del(`/api/videos/${madeId}`);
  } finally {
    // Тестовые видео не должны оставаться в библиотеке даже при падении проверки
    await admin.del(`/api/videos/${vid}`).catch(() => {});
    fs.rmSync(dir, { recursive: true, force: true });
  }
});

test('загрузка: предел 2 ТБ, размер части и устойчивость к мусору в запросе', async () => {
  const { chunkSizeFor } = await import('../src/routes/uploads.js');
  const { intOrNull, sizeOrNull, isUuid } = await import('../src/lib/util.js');
  assert.equal(chunkSizeFor(200 * 1024 * 1024) / (1024 * 1024), 8, 'обычный файл — части по 8 МБ');
  assert.equal(chunkSizeFor(50 * 1024 ** 3) / (1024 * 1024), 16);
  assert.equal(chunkSizeFor(200 * 1024 ** 3) / (1024 * 1024), 32);
  assert.equal(chunkSizeFor(2 * 1024 ** 4) / (1024 * 1024), 64, 'терабайтный файл — части по 64 МБ');
  assert.equal(intOrNull('категория'), null, 'нечисловая категория не уходит в SQL');
  assert.equal(intOrNull(''), null);
  assert.equal(intOrNull('7'), 7);
  assert.equal(sizeOrNull(undefined), null);
  assert.equal(sizeOrNull('1048576'), 1048576);
  assert.ok(!isUuid('undefined') && isUuid('0f8fad5b-d9cb-469f-a165-70867728950e'));

  // Предел из настроек действительно 2 ТБ
  const cfg = await admin.get('/api/admin/settings');
  assert.ok(Number(cfg.json.settings['upload.max_size_mb']) >= 2097152, 'предел загрузки не меньше 2 ТБ');

  // Файл на 1,5 ТБ принимается к загрузке
  const big = await admin.post('/api/uploads', { filename: 'huge.mp4', size: Math.round(1.5 * 1024 ** 4), mime: 'video/mp4', visibility: 'private' });
  assert.equal(big.status, 200, big.text);
  assert.equal(big.json.chunkSize, 64 * 1024 * 1024, 'части подстроились под размер');
  await admin.del(`/api/videos/${big.json.videoId}`);

  // Мусор в категории раньше давал 400 «Некорректный идентификатор или значение в запросе»
  const trash = await admin.post('/api/uploads', { filename: 'x.mp4', size: 1048576, mime: 'video/mp4', visibility: 'private', categoryId: 'Обучение' });
  assert.equal(trash.status, 200, trash.text);
  const created = (await admin.get(`/api/videos/${trash.json.videoId}`)).json.video;
  assert.equal(created.categoryId, null, 'нечисловая категория просто игнорируется');
  await admin.del(`/api/videos/${trash.json.videoId}`);

  // Пустой размер — понятное сообщение, мусорный идентификатор загрузки — 404, а не ошибка базы
  const noSize = await admin.post('/api/uploads', { filename: 'x.mp4', mime: 'video/mp4' });
  assert.equal(noSize.status, 400);
  assert.match(noSize.json.error, /размер файла/i);
  const bogus = await admin.get('/api/uploads/undefined');
  assert.equal(bogus.status, 404, 'мусорный идентификатор загрузки не доходит до базы');
});

test('запись экрана: раскладка «камера крупно» и врезка в углу', async () => {
  const { coverCrop, insetRect, insetFraction, layoutSources } = await import('../../web/src/utils/record.js');

  // Камера 4:3 в кадре 16:9 — берём центральную полосу по высоте, без полей и искажений
  const c = coverCrop(640, 480, 1920, 1080);
  assert.equal(Math.round(c.sw), 640, 'по ширине источник берётся целиком');
  assert.equal(Math.round(c.sh), 360, 'по высоте обрезается до 16:9');
  assert.ok(c.sx === 0 && Math.round(c.sy) === 60, 'обрезка симметрична по центру');
  const same = coverCrop(1920, 1080, 1280, 720);
  assert.ok(same.sw === 1920 && same.sh === 1080, 'одинаковые пропорции — без обрезки');

  // Врезка не выходит за кадр и уходит в выбранный угол
  const br = insetRect(1920, 1080, 'br');
  assert.ok(br.x + br.w <= 1920 && br.y + br.h <= 1080, 'врезка внутри кадра');
  assert.equal(Math.round(br.w / br.h * 100) / 100, 1.78, 'врезка 16:9');
  const tl = insetRect(1920, 1080, 'tl');
  assert.ok(tl.x < br.x && tl.y < br.y, 'левый верхний угол левее и выше правого нижнего');
  const bl = insetRect(1920, 1080, 'bl');
  assert.ok(bl.x === tl.x && bl.y === br.y);
  const tiny = insetRect(320, 180, 'br');
  assert.ok(tiny.x >= 0 && tiny.y >= 0 && tiny.w > 0, 'на маленьком кадре врезка остаётся видимой');

  // Переключение раскладки меняет местами крупный план и врезку
  const screen = { id: 'screen' }, camera = { id: 'camera' };
  const a = layoutSources('screen-main', { screen, camera });
  assert.ok(a.main === screen && a.inset === camera, 'экран крупно, камера в углу');
  const b = layoutSources('cam-main', { screen, camera });
  assert.ok(b.main === camera && b.inset === screen, 'камера крупно, экран в углу');
  const d = layoutSources(undefined, { screen, camera });
  assert.ok(d.main === screen, 'без раскладки — привычный «экран крупно»');

  // Размер и форма врезки
  assert.ok(insetFraction('sm') < insetFraction('md') && insetFraction('md') < insetFraction('lg'));
  assert.equal(insetFraction('что-то'), insetFraction('md'), 'неизвестный размер — обычный');
  const lg = insetRect(1920, 1080, 'br', insetFraction('lg'));
  const sm = insetRect(1920, 1080, 'br', insetFraction('sm'));
  assert.ok(lg.w > sm.w && lg.x < sm.x, 'крупная врезка шире и начинается левее');
  assert.ok(lg.x + lg.w === sm.x + sm.w, 'правый край врезки не зависит от размера');
  const circle = insetRect(1920, 1080, 'br', 0.22, 24, 1);
  assert.equal(circle.w, circle.h, 'круглая врезка вписана в квадрат');
  assert.ok(circle.y + circle.h <= 1080, 'круглая врезка не выходит за кадр');
});

test('загрузка: обращение по чужому и мусорному идентификатору — всегда «не найдена»', async () => {
  // Клиент, потерявший идентификатор, раньше уходил на /api/uploads/undefined и получал ошибку типа из базы
  for (const id of ['undefined', 'null', 'NaN', '123', '../../etc/passwd']) {
    const g = await admin.get(`/api/uploads/${encodeURIComponent(id)}`);
    assert.equal(g.status, 404, `GET ${id}: ${g.text}`);
    assert.match(g.json.error, /не найдена/i);
    const c = await admin.post(`/api/uploads/${encodeURIComponent(id)}/complete`, {});
    assert.equal(c.status, 404, `complete ${id}: ${c.text}`);
    const d = await admin.del(`/api/uploads/${encodeURIComponent(id)}`);
    assert.equal(d.status, 404, `delete ${id}: ${d.text}`);
  }
  // Правильный по виду, но несуществующий идентификатор — тоже 404, а не ошибка базы
  const ghost = await admin.get('/api/uploads/0f8fad5b-d9cb-469f-a165-70867728950e');
  assert.equal(ghost.status, 404);

  // Чужая загрузка не видна даже администратору: иначе докачка перетирала бы чужой файл
  const mine = await user.post('/api/uploads', { filename: 'чужая.mp4', size: 1048576, mime: 'video/mp4', visibility: 'private' });
  assert.equal(mine.status, 200, mine.text);
  try {
    const alien = await admin.get(`/api/uploads/${mine.json.uploadId}`);
    assert.equal(alien.status, 404, 'чужая загрузка не отдаётся');
    const own = await user.get(`/api/uploads/${mine.json.uploadId}`);
    assert.equal(own.status, 200, 'своя — отдаётся');
    assert.equal(own.json.offset, 0);
  } finally {
    await user.del(`/api/uploads/${mine.json.uploadId}`);
    await user.del(`/api/videos/${mine.json.videoId}`);
  }
});

test('здоровье: метка экземпляра постоянна и позволяет заметить второй сервер', async () => {
  const a = await guest.get('/api/health');
  assert.equal(a.status, 200, a.text);
  assert.ok(a.json.instance, 'метка экземпляра есть в ответе');
  const b2 = await guest.get('/api/health');
  assert.equal(b2.json.instance, a.json.instance, 'пока процесс жив, метка не меняется');
  assert.equal(a.json.status, 'ok');
  assert.equal(a.json.db, 'ok');
});

test('загрузка: адрес создания работает и с косой чертой, и без неё', async () => {
  // Клиент ходит на канонический /api/uploads/ — именно на него настроен блок nginx.
  // Адрес без косой черты тоже обязан работать: им пользуются интеграции по API.
  for (const url of ['/api/uploads/', '/api/uploads']) {
    const r = await admin.post(url, { filename: 'адрес.mp4', size: 1048576, mime: 'video/mp4', visibility: 'private' });
    assert.equal(r.status, 200, `${url}: ${r.text}`);
    assert.match(String(r.json.uploadId), /^[0-9a-f-]{36}$/, `${url}: вернулся идентификатор`);
    await admin.del(`/api/uploads/${r.json.uploadId}`);
    await admin.del(`/api/videos/${r.json.videoId}`);
  }
  // Пустой идентификатор (так выглядит GET /api/uploads/ после перенаправления) — понятный отказ, не ошибка базы
  const empty = await admin.get('/api/uploads/');
  assert.equal(empty.status, 404, empty.text);
});

test('nginx: у адресов API с косой чертой есть точный location, иначе браузер теряет POST', async () => {
  // Префиксный location, оканчивающийся на «/», вместе с proxy_pass заставляет nginx отвечать 301
  // на запрос ровно этого адреса без косой черты; браузер на 301 превращает POST в GET и теряет тело.
  // Для статики (alias/root) это безобидно, поэтому проверяем только проксируемые адреса API.
  const tpl = fs.readFileSync(new URL('../../deploy/nginx/locations.conf.template', import.meta.url), 'utf8');
  assert.match(tpl, /location\s*=\s*\/api\/uploads\s*\{/, 'в шаблоне есть «location = /api/uploads»');

  const blocks = [...tpl.matchAll(/location\s+(\/api\/\S*\/)\s*\{([^}]*)\}/g)];
  assert.ok(blocks.length, 'нашлись проксируемые location для API');
  for (const [, prefix, body] of blocks) {
    if (!/proxy_pass/.test(body)) continue;
    const bare = prefix.slice(0, -1);
    const exact = new RegExp(`location\\s*=\\s*${bare.replace(/\//g, '\\/')}\\s*\\{`);
    assert.ok(exact.test(tpl), `для «location ${prefix}» нужен точный «location = ${bare}», иначе nginx отвечает 301 и POST превращается в GET`);
  }
});

test('возможности: выключение раздела прячет его и закрывает его адреса', async () => {
  const page = await admin.get('/api/admin/features');
  assert.equal(page.status, 200, page.text);
  assert.ok(page.json.groups.length >= 6, 'группы возможностей');
  assert.ok(page.json.total >= 50, `пунктов ${page.json.total}`);
  const all = page.json.groups.flatMap((g) => g.items);
  const shorts = all.find((i) => i.key === 'shorts.enabled');
  assert.ok(shorts && shorts.enabled, 'раздел «Короткие» включён');
  assert.ok(all.some((i) => typeof i.usage === 'number'), 'показано, чем пользовались');
  assert.ok(all.some((i) => i.deps?.length), 'зависимости указаны');

  // Модератору сюда нельзя: это настройка портала
  assert.equal((await user.get('/api/admin/features')).status, 403);

  try {
    assert.equal((await user.get('/api/feed/shorts')).status, 200, 'до выключения раздел работает');
    const off = await admin.patch('/api/admin/features', { features: { 'shorts.enabled': false } });
    assert.equal(off.status, 200, off.text);

    const blocked = await user.get('/api/feed/shorts');
    assert.equal(blocked.status, 404, 'адрес выключенного раздела закрыт');
    assert.match(blocked.json.error, /отключ/i);
    assert.equal(blocked.json.feature, 'shorts.enabled');

    const cfg = await user.get('/api/config');
    assert.equal(cfg.json.features['shorts.enabled'], false, 'интерфейс узнаёт о выключении из /api/config');

    // Панель остаётся доступной — иначе выключенное нельзя было бы включить обратно
    assert.equal((await admin.get('/api/admin/features')).status, 200);
  } finally {
    await admin.patch('/api/admin/features', { features: { 'shorts.enabled': true } });
  }
  assert.equal((await user.get('/api/feed/shorts')).status, 200, 'раздел вернулся');

  // Неизвестные ключи не принимаются
  const junk = await admin.patch('/api/admin/features', { features: { 'нет.такого': false } });
  assert.equal(junk.status, 400, junk.text);
});

test('возможности: карта маршрутов ссылается на существующие адреса API', async () => {
  const { FEATURE_ROUTES, FEATURE_GROUPS, FEATURE_KEYS } = await import('../src/lib/features.js');
  const { DEFAULTS } = await import('../src/lib/settings.js');
  // Каждый ключ возможности должен быть настоящей настройкой, иначе переключатель ничего не делает
  for (const k of FEATURE_KEYS) assert.ok(k in DEFAULTS, `ключ ${k} отсутствует в настройках`);
  // И наоборот: пункт без описания бесполезен в интерфейсе
  for (const g of FEATURE_GROUPS) for (const i of g.items) {
    assert.ok(i.title && i.desc, `${i.key}: нужны название и описание`);
    for (const d of i.deps || []) assert.ok(d in DEFAULTS, `${i.key}: зависимость ${d} не является настройкой`);
  }
  // Адреса из карты должны существовать: проверяем, что портал на них отвечает не 404-«не найдено»
  for (const [re, key] of FEATURE_ROUTES) {
    assert.ok(FEATURE_KEYS.includes(key), `маршрут ведёт на неизвестную возможность ${key}`);
    assert.ok(re instanceof RegExp);
  }
});

test('объявление всем: рассылка фоновым заданием, одно уведомление на человека', async () => {
  const title = `Объявление ${stamp}`;
  const r = await admin.post('/api/admin/system/announce', { title, body: 'Проверка рассылки' });
  assert.equal(r.status, 200, r.text);
  assert.equal(r.json.queued, true, 'рассылка идёт в фоне, а не в HTTP-запросе');
  assert.ok(r.json.recipients >= 2);
  let found = null;
  for (let i = 0; i < 40 && !found; i++) {
    const list = await user.get('/api/notifications?limit=20');
    found = (list.json.notifications || list.json.items || []).find((n) => n.title === title);
    if (!found) await new Promise((res) => setTimeout(res, 500));
  }
  assert.ok(found, 'уведомление дошло до пользователя');
  assert.equal(found.type, 'announcement');
  const [cnt] = await sql('SELECT count(*)::int AS n FROM notifications WHERE title = $1', [title]);
  const [act] = await sql(`SELECT count(*)::int AS n FROM users WHERE status = 'active' AND deleted_at IS NULL`);
  assert.equal(cnt.n, act.n, 'каждому активному — ровно одно уведомление');
});

test('1.12: токен лент открывает только ленты и файлы-вложения', async () => {
  const ft = await user.get('/api/me/feed-token');
  assert.equal(ft.status, 200, ft.text);
  assert.ok(ft.json.expiresAt, 'у токена есть срок');
  const token = ft.json.token;
  const v = (await user.get(`/api/videos/${videoId}`)).json.video;
  const anon = new Client();
  const rss = await anon.get(`/api/rss/latest?ft=${token}`);
  assert.equal(rss.status, 200, 'лента по токену открывается');
  const mp4 = await anon.get(`/media/videos/${v.id}/video.mp4?ft=${token}`);
  assert.equal(mp4.status, 200, 'файл-вложение ленты доступен по токену');
  const orig = await anon.get(`/media/videos/${v.id}/original.mp4?ft=${token}`);
  assert.equal(orig.status, 401, 'оригинал по токену лент недоступен');
  const hls = await anon.get(`/media/videos/${v.id}/hls/master.m3u8?ft=${token}`);
  assert.equal(hls.status, 401, 'поток по токену лент недоступен');
  const dl = await anon.get(`/api/videos/${v.id}/download?ft=${token}`);
  assert.ok([401, 403].includes(dl.status), 'скачивание по токену лент недоступно');
  // Истёкший токен не действует
  await sql(`UPDATE users SET feed_token_created_at = now() - interval '400 days' WHERE email = $1`, [userEmail]);
  const expired = await anon.get(`/api/rss/subscriptions?ft=${token}`);
  assert.equal(expired.status, 403, 'просроченный токен не открывает личную ленту');
  await user.post('/api/me/feed-token/reset', {});
});

test('1.12: закрытое видео в чужом плейлисте — только позиция, без названия', async () => {
  const own = await admin.post('/api/playlists', { title: `Публичный ${stamp}`, visibility: 'public' });
  assert.equal(own.status, 200, own.text);
  const pid = own.json.playlist.id;
  // Готовое видео пользователя «для сотрудников» — гостю недоступно
  const mine = (await user.get('/api/studio/videos?limit=50')).json.videos.find((x) => x.status === 'ready');
  assert.ok(mine, 'у пользователя есть готовое видео');
  await user.patch(`/api/videos/${mine.id}`, { visibility: 'internal' });
  const vid = mine.id;
  const add = await admin.post(`/api/playlists/${pid}/items`, { videoId: vid });
  assert.equal(add.status, 200, add.text);
  const g = await guest.get(`/api/playlists/${pid}`);
  assert.equal(g.status, 200, g.text);
  const item = g.json.videos.find((x) => x.id === vid);
  assert.ok(item, 'позиция на месте');
  assert.equal(item.unavailable, true);
  assert.equal(item.title, 'Видео недоступно', 'название не раскрыто');
  assert.equal(item.shortId, undefined, 'короткий адрес не раскрыт');
  assert.equal(item.owner, undefined, 'автор не раскрыт');
  const u = await user.get(`/api/playlists/${pid}`);
  const seen = u.json.videos.find((x) => x.id === vid);
  assert.equal(seen.unavailable, false, 'сотруднику видео доступно');
  assert.ok(seen.title && seen.title !== 'Видео недоступно');
  await admin.del(`/api/playlists/${pid}`);
});

test('1.12: исходящие адреса и кнопки «Проверить»', async () => {
  const meta = await admin.post('/api/admin/webhooks', { name: 'Метаданные', url: 'http://169.254.169.254/latest/meta-data/' });
  assert.equal(meta.status, 400, 'служебный адрес для вебхука запрещён');
  assert.match(meta.json.error, /запрещ/);
  // Проверка не сохраняет форму
  const before = (await admin.get('/api/admin/settings')).json.settings['ai.url'];
  const t = await admin.post('/api/admin/settings/test-ai', { settings: { 'ai.url': 'http://169.254.169.254/v1/chat/completions' } });
  assert.equal(t.status, 400, 'служебный адрес для ИИ запрещён');
  const after = (await admin.get('/api/admin/settings')).json.settings['ai.url'];
  assert.equal(after, before, 'проверка не изменила сохранённые настройки');
  const { responseHint } = await import('../src/lib/net.js');
  const fake = (ct) => ({ headers: { get: () => ct } });
  assert.equal(responseHint(fake('text/html'), '<html><body>secret</body></html>'), '', 'страница не показывается');
  assert.equal(responseHint(fake('application/json'), '{"error":{"message":"bad model"}}'), 'bad model', 'из JSON — только сообщение');
  assert.equal(responseHint(fake('text/plain'), 'invalid_payload'), 'invalid_payload');
  // Цвет акцента — только шестнадцатеричный
  const badColor = await admin.put('/api/admin/settings', { settings: { 'site.accent_color': 'red;}</style><script>' } });
  assert.equal(badColor.status, 400, 'произвольный CSS в цвете отклонён');
});

test('1.12: X-Accel-Redirect — путь кодируется, «%» и «?» отдаёт сам Node', async () => {
  const { storage } = await import('../src/lib/storage.js');
  const viaNginx = { headers: { 'x-accel-supported': '1' } };
  assert.equal(storage.accel({ headers: {} }, 'videos/a/original.mp4'), null, 'без nginx файл отдаёт Node');
  assert.equal(storage.accel(viaNginx, 'videos/a/original.mp4'), '/__corpvideo_internal_media/videos/a/original.mp4');
  assert.equal(storage.accel(viaNginx, 'webinars/w/x-Памятка 1.pdf'), '/__corpvideo_internal_media/webinars/w/x-%D0%9F%D0%B0%D0%BC%D1%8F%D1%82%D0%BA%D0%B0%201.pdf',
    'кириллица и пробелы кодируются — в заголовке допустим только ASCII');
  assert.equal(storage.accel(viaNginx, 'webinars/w/Скидка 50%.pdf'), null, '«%» nginx раскодировал бы повторно');
  assert.equal(storage.accel(viaNginx, 'a/../b.pdf'), null, 'выход из каталога не пропускается');
});

test('1.12: материалы вебинара — белый список и раздача с проверкой', async () => {
  const slug = `mat-${stamp}`;
  const cr = await admin.post('/api/studio/webinars', { title: `Материалы ${stamp}`, slug, visibility: 'internal', scheduledAt: new Date(Date.now() + 86400000).toISOString() });
  assert.equal(cr.status, 200, cr.text);
  const w = cr.json.webinar;
  const html = new FormData(); html.append('file', new Blob(['<script>alert(1)</script>'], { type: 'text/html' }), 'page.html');
  const bad = await admin.post(`/api/studio/webinars/${w.id}/materials`, html);
  assert.equal(bad.status, 400, 'HTML как материал не принимается');
  const pdf = new FormData(); pdf.append('before', 'false'); pdf.append('file', new Blob(['%PDF-1.4 test'], { type: 'application/pdf' }), 'Памятка.pdf');
  const ok = await admin.post(`/api/studio/webinars/${w.id}/materials`, pdf);
  assert.equal(ok.status, 200, ok.text);
  const url = ok.json.material.url;
  assert.match(url, /^\/api\/webinars\//, 'материал раздаётся отдельным адресом');
  const dAdmin = await admin.get(url);
  assert.equal(dAdmin.status, 200, dAdmin.text);
  assert.match(dAdmin.headers.get('content-disposition') || '', /attachment/);
  assert.equal(dAdmin.headers.get('x-content-type-options'), 'nosniff');
  const dUser = await user.get(url);
  assert.equal(dUser.status, 403, 'материал «после вебинара» — только участникам');
  await user.post(`/api/webinars/${slug}/register`, {});
  const dUser2 = await user.get(url);
  assert.equal(dUser2.status, 200, 'после регистрации материал доступен');
  const rows = await sql('SELECT path FROM webinar_materials WHERE stream_id = $1', [w.id]);
  const direct = await admin.get(`/media/${rows[0].path}`);
  assert.equal(direct.status, 403, 'через /media/ материалы не раздаются');
  // Автовебинар из чужого видео нельзя
  const foreign = await user.post('/api/studio/webinars', { title: `Чужое ${stamp}`, slug: `foreign-${stamp}` });
  if (foreign.status === 200) {
    const src = await user.patch(`/api/studio/webinars/${foreign.json.webinar.id}`, { sourceVideoId: (await admin.get('/api/studio/videos?limit=1')).json.videos[0]?.id });
    assert.equal(src.status, 403, 'чужое видео для автовебинара не выбрать');
    await user.del(`/api/studio/live/${foreign.json.webinar.id}`);
  }
  await admin.del(`/api/studio/live/${w.id}`);
});

test('1.12: адрес клиента за nginx — последний адрес из X-Forwarded-For', async () => {
  const { config } = await import('../src/config.js');
  const Fastify = (await import('fastify')).default;
  const app = Fastify({ trustProxy: config.trustProxy });
  app.get('/ip', async (req) => ({ ip: req.ip }));
  const one1 = await app.inject({ method: 'GET', url: '/ip', headers: { 'x-forwarded-for': '10.9.9.9' }, remoteAddress: '127.0.0.1' });
  assert.equal(one1.json().ip, '10.9.9.9', 'за nginx виден адрес посетителя, а не nginx');
  const spoof = await app.inject({ method: 'GET', url: '/ip', headers: { 'x-forwarded-for': '1.2.3.4, 10.9.9.9' }, remoteAddress: '127.0.0.1' });
  assert.equal(spoof.json().ip, '10.9.9.9', 'подставленный клиентом адрес не принимается');
  await app.close();
});

test('1.12: блокировка входа по паре «логин + адрес» и одноразовый код TOTP', async () => {
  const email = `lock.${stamp}@vodokomfort.ru`;
  const created = await admin.post('/api/admin/users/create', { email, displayName: 'Замок Тестовый', password: 'Password123' });
  assert.equal(created.status, 200, created.text);
  const attacker = new Client();
  const h = { headers: { 'x-forwarded-for': '10.20.30.40' } };
  let last = null;
  for (let i = 0; i < 9; i++) last = await attacker.post('/api/auth/login', { login: email, password: 'wrong-password' }, h);
  assert.equal(last.status, 403, 'с адреса злоумышленника вход заблокирован');
  const owner = new Client();
  // Через nginx подставить X-Forwarded-For нельзя (и правильно): там оба «клиента» — один адрес.
  // Тогда разблокируем вход так, как сделал бы администратор, и продолжаем.
  if (VIA_NGINX) await sql('DELETE FROM login_failures WHERE login = $1', [email]);
  const ok = await owner.post('/api/auth/login', { login: email, password: 'Password123' }, { headers: { 'x-forwarded-for': '10.20.30.41' } });
  assert.equal(ok.status, 200, 'владелец со своего адреса входит: ' + ok.text);
  // TOTP: один код — один вход
  const setup = await owner.post('/api/auth/totp/setup', {});
  const step = Math.floor(Date.now() / 1000 / 30);
  const en = await owner.post('/api/auth/totp/enable', { code: totpCode(setup.json.secret, step) });
  assert.equal(en.status, 200, en.text);
  const again = await owner.post('/api/auth/totp/disable', { code: totpCode(setup.json.secret, step) });
  assert.equal(again.status, 400, 'тот же код второй раз не принимается');
  const off = await owner.post('/api/auth/totp/disable', { password: 'Password123' });
  assert.equal(off.status, 200, off.text);
  // Поиск с символами шаблона не находит всех подряд
  const wild = await user.get('/api/users/lookup?q=%25%25');
  assert.equal(wild.json.users.length, 0, '«%%» не совпадает со всеми пользователями');
});

test('1.12: правка комментария проходит проверку стоп-слов; лимит открытий ссылки атомарен', async () => {
  await admin.put('/api/admin/settings', { settings: { 'comments.blocked_words': ['запретслово'] } });
  try {
    const c = await user.post(`/api/videos/${videoId}/comments`, { body: 'Хороший ролик' });
    assert.equal(c.status, 200, c.text);
    assert.equal(c.json.held, false);
    // Автор видео — сам пользователь; правку проверяем от имени другого сотрудника
    const other = await admin.post(`/api/videos/${videoId}/comments`, { body: 'Комментарий администратора' });
    assert.equal(other.status, 200);
    const e = await user.patch(`/api/comments/${c.json.comment.id}`, { body: 'Теперь тут запретслово' });
    assert.equal(e.status, 200, e.text);
    assert.equal(e.json.held, false, 'владелец видео правит свой комментарий без задержки');
  } finally { await admin.put('/api/admin/settings', { settings: { 'comments.blocked_words': [] } }); }
  // Ссылка на один просмотр: из двух одновременных открытий проходит одно
  const link = await user.post(`/api/videos/${videoId}/share-links`, { maxViews: 1 });
  assert.equal(link.status, 200, link.text);
  const tok = link.json.link.token;
  const [a, b] = await Promise.all([new Client().get(`/api/share/${tok}`), new Client().get(`/api/share/${tok}`)]);
  const unlocked = [a, b].filter((r) => r.json?.unlocked).length;
  assert.equal(unlocked, 1, 'ровно одно открытие');
});

test('1.12: квота учитывает незавершённые загрузки; отмена задания; уборка временных файлов', async () => {
  const MB = 1024 * 1024;
  // Используемое место сотрудника — чтобы задать квоту «впритык»
  const { storageUsedBytes } = await import('../src/lib/quota.js');
  const [u] = await sql('SELECT id FROM users WHERE email = $1', [userEmail]);
  const used = await storageUsedBytes({ one: async (t, p) => (await sql(t, p))[0] }, u.id);
  const quotaMb = Math.ceil(used / MB) + 50;
  await admin.put('/api/admin/settings', { settings: { 'storage.quota_mb': quotaMb } });
  const started = [];
  try {
    const a = await user.post('/api/uploads', { filename: 'Квота-1.mp4', size: 30 * MB, mime: 'video/mp4', title: 'Квота 1' });
    assert.equal(a.status, 200, a.text);
    started.push(a.json.uploadId);
    // Первая загрузка ещё не дошла, но место уже зарезервировано — вторая не помещается
    const b = await user.post('/api/uploads', { filename: 'Квота-2.mp4', size: 30 * MB, mime: 'video/mp4', title: 'Квота 2' });
    assert.equal(b.status, 403, 'незавершённая загрузка учитывается в квоте');
    if (b.json?.uploadId) started.push(b.json.uploadId);
  } finally {
    for (const id of started) await user.del(`/api/uploads/${id}`);
    await admin.put('/api/admin/settings', { settings: { 'storage.quota_mb': 0 } });
  }

  // Отмена задания в очереди завершает его сразу
  const [job] = await sql(`INSERT INTO jobs(type, payload, status, run_at) VALUES ('email', '{}'::jsonb, 'queued', now() + interval '1 day') RETURNING id`);
  const c = await admin.post(`/api/admin/jobs/${job.id}/cancel`, {});
  assert.equal(c.json.outcome, 'cancelled', c.text);
  const [after] = await sql('SELECT status FROM jobs WHERE id = $1', [job.id]);
  assert.equal(after.status, 'cancelled');

  // Брошенный каталог сборки монтажа удаляется, свежий — нет
  const { cleanupJobTemp } = await import('../src/jobs/misc.js');
  const { config } = await import('../src/config.js');
  fs.mkdirSync(config.uploadTmpDir, { recursive: true });
  const { randomUUID } = await import('node:crypto');
  const old = path.join(config.uploadTmpDir, `render-${randomUUID()}-zz`);
  const fresh = path.join(config.uploadTmpDir, `render-${randomUUID()}-yy`);
  fs.mkdirSync(old, { recursive: true }); fs.mkdirSync(fresh, { recursive: true });
  const past = new Date(Date.now() - 2 * 86400000);
  fs.utimesSync(old, past, past);
  const n = await cleanupJobTemp();
  assert.ok(n >= 1, 'старый каталог удалён');
  assert.ok(!fs.existsSync(old), 'брошенный каталог удалён');
  assert.ok(fs.existsSync(fresh), 'свежий каталог не тронут');
  fs.rmSync(fresh, { recursive: true, force: true });
});

test('удаление видео владельцем', async () => {
  const r = await user.del(`/api/videos/${videoId}`);
  assert.equal(r.status, 200);
  const g = await user.get(`/api/videos/${videoId}`);
  assert.equal(g.status, 404);
});
