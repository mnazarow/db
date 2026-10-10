// Прямые трансляции: MediaMTX (RTMP/SRT/WHIP → HLS), ключи, чат, зрители, запись эфира.
import path from 'node:path';
import fsp from 'node:fs/promises';
import { one, many, query, publish, bus } from '../db.js';
import { config } from '../config.js';
import { canStream, canViewLive, canViewLiveOrGuest, isStaff, isActive } from '../lib/access.js';
import { liveOut } from '../lib/serialize.js';
import { badRequest, forbidden, notFound, unauthorized, paging, escapeHtml } from '../lib/util.js';
import { randomToken, shortId } from '../lib/crypto.js';
import { mediamtx } from '../lib/mediamtx.js';
import { toChannel, channelUsers, channelSize } from '../lib/realtime.js';
import { enqueue } from '../lib/jobs.js';
import { notify } from '../lib/notify.js';
import { storage, ensureDir } from '../lib/storage.js';
import { runFfmpeg } from '../lib/ffmpeg.js';
import { audit } from '../lib/audit.js';
import { emitEvent } from '../lib/events.js';
import { loadSettings } from '../lib/settings.js';
import { startRestreams, targetUrl, validTarget } from '../jobs/restream.js';
import { browserPath, mediaUrl } from '../lib/urls.js';

const LIVE_SELECT = `s.*, u.display_name AS owner_name, u.handle AS owner_handle, u.avatar_path AS owner_avatar, u.subscriber_count AS owner_subscribers, rv.short_id AS recording_short_id`;
const LIVE_FROM = `live_streams s JOIN users u ON u.id = s.owner_id LEFT JOIN videos rv ON rv.id = s.recording_video_id`;

export async function loadStream(idOrShort) {
  return one(`SELECT ${LIVE_SELECT} FROM ${LIVE_FROM} WHERE s.id = cv_uuid($1) OR s.short_id = $1`, [String(idOrShort)]);
}
async function loadStreamByKey(key) {
  return one(`SELECT ${LIVE_SELECT} FROM ${LIVE_FROM} WHERE s.stream_key = $1`, [key]);
}

function visSql(user) {
  return isActive(user) ? "s.visibility IN ('public','internal')" : "s.visibility = 'public'";
}

const viewerCounts = new Map(); // streamId → n (локально)

export function liveViewerCount(streamId) {
  return Math.max(channelSize(`live:${streamId}`), viewerCounts.get(streamId) || 0);
}

export default async function liveRoutes(app) {
  // --- Публичные списки ---------------------------------------------------------
  app.get('/live', async (req) => {
    if (!req.user && !req.settings['site.public_home']) throw forbidden();
    const live = await many(`SELECT ${LIVE_SELECT} FROM ${LIVE_FROM} WHERE s.status = 'live' AND ${visSql(req.user)} ORDER BY s.viewer_count DESC, s.started_at DESC LIMIT 50`);
    const upcoming = await many(`SELECT ${LIVE_SELECT} FROM ${LIVE_FROM} WHERE s.status = 'idle' AND s.scheduled_at IS NOT NULL AND s.scheduled_at > now() - interval '6 hours' AND ${visSql(req.user)} ORDER BY s.scheduled_at ASC LIMIT 50`);
    const recent = await many(`SELECT ${LIVE_SELECT} FROM ${LIVE_FROM} WHERE s.status = 'ended' AND s.recording_video_id IS NOT NULL AND ${visSql(req.user)} ORDER BY s.ended_at DESC LIMIT 12`);
    return {
      live: live.map((s) => liveOut({ ...s, viewer_count: liveViewerCount(s.id) }, { viewer: req.user })),
      upcoming: upcoming.map((s) => liveOut(s, { viewer: req.user })),
      recent: recent.map((s) => liveOut(s, { viewer: req.user })),
    };
  });

  app.get('/live/:id', async (req) => {
    const s = await loadStream(req.params.id);
    if (!s) throw notFound('Трансляция не найдена');
    if (!(await canViewLiveOrGuest(s, req.user, req.webinarTokens))) throw req.user ? forbidden('Нет доступа к трансляции') : unauthorized('Войдите, чтобы смотреть трансляцию');
    const isOwner = req.user && (req.user.id === s.owner_id || req.user.role === 'admin');
    const out = liveOut({ ...s, viewer_count: liveViewerCount(s.id) }, { viewer: req.user, withKey: !!isOwner });
    if (isOwner) {
      out.ingest = {
        rtmpUrl: config.mediamtx.rtmpPublicUrl ? `${config.mediamtx.rtmpPublicUrl.replace(/\/$/, '')}/live` : null,
        rtmpFull: config.mediamtx.rtmpPublicUrl ? `${config.mediamtx.rtmpPublicUrl.replace(/\/$/, '')}/live/${s.stream_key}` : null,
        srtUrl: config.mediamtx.srtPublicUrl ? `${config.mediamtx.srtPublicUrl}?streamid=publish:live/${s.stream_key}` : null,
        whipUrl: config.mediamtx.whipPublicUrl ? `${config.mediamtx.whipPublicUrl.replace(/\/$/, '')}/live/${s.stream_key}/whip` : `${config.baseUrl}/whip/live/${s.stream_key}/whip`,
      };
    }
    out.hlsUrl = browserPath(`/api/live/${s.short_id}/hls/index.m3u8`);
    out.subscribed = req.user ? !!(await one('SELECT 1 FROM subscriptions WHERE subscriber_id = $1 AND channel_id = $2', [req.user.id, s.owner_id])) : false;
    if (s.scheduled_at && s.status !== 'live') {
      out.reminder = req.user ? !!(await one('SELECT 1 FROM live_reminders WHERE stream_id = $1 AND user_id = $2', [s.id, req.user.id])) : false;
      out.reminderCount = (await one('SELECT count(*)::int AS n FROM live_reminders WHERE stream_id = $1', [s.id]))?.n || 0;
    }
    return { stream: out };
  });

  // --- HLS-прокси к MediaMTX (ключ трансляции не раскрывается зрителям) -------------------
  app.get('/live/:id/hls/*', async (req, reply) => {
    const s = await loadStream(req.params.id);
    if (!s) throw notFound();
    if (!(await canViewLiveOrGuest(s, req.user, req.webinarTokens))) throw req.user ? forbidden() : unauthorized();
    // Только имена файлов плейлиста/сегментов: закодированные «..» (%252e) раньше проходили фильтр
    const file = String(req.params['*'] || 'index.m3u8');
    if (file.includes('..') || !/^[A-Za-z0-9][A-Za-z0-9._-]{0,120}(\/[A-Za-z0-9][A-Za-z0-9._-]{0,120}){0,2}$/.test(file)) throw notFound();
    const qs = req.url.includes('?') ? req.url.slice(req.url.indexOf('?')) : '';
    const target = `${config.mediamtx.hlsUrl}/live/${encodeURIComponent(s.stream_key)}/${file}${qs}`;
    let res;
    try {
      res = await fetch(target, { headers: { 'user-agent': 'corpvideo-proxy' }, signal: AbortSignal.timeout(10000) });
    } catch (e) {
      reply.code(503); return { error: 'Сервер трансляций недоступен' };
    }
    reply.code(res.status);
    const ct = res.headers.get('content-type');
    if (ct) reply.header('content-type', ct);
    reply.header('cache-control', 'no-cache');
    if (!res.ok) return '';
    // Потоковая передача тела
    return reply.send(res.body);
  });

  // --- Чат ---------------------------------------------------------------------
  app.get('/live/:id/chat', async (req) => {
    const s = await loadStream(req.params.id);
    if (!s) throw notFound();
    if (!(await canViewLiveOrGuest(s, req.user, req.webinarTokens))) throw req.user ? forbidden() : unauthorized();
    const { limit } = paging(req.query, 50, 200);
    const before = Number(req.query.before) || null;
    const rows = await many(
      `SELECT m.*, u.display_name, u.handle, u.avatar_path, u.role FROM live_chat_messages m JOIN users u ON u.id = m.user_id
       WHERE m.stream_id = $1 AND m.is_deleted = false AND ($2::bigint IS NULL OR m.id < $2) ORDER BY m.id DESC LIMIT $3`,
      [s.id, before, limit],
    );
    return { messages: rows.reverse().map(chatOut) };
  });

  app.post('/live/:id/chat', { preHandler: app.requireActive, config: { rateLimit: { max: 60, timeWindow: '1 minute' } } }, async (req) => {
    const s = await loadStream(req.params.id);
    if (!s) throw notFound();
    if (!canViewLive(s, req.user)) throw forbidden();
    if (!s.chat_enabled || !req.settings['live.chat_enabled']) throw forbidden('Чат отключён');
    const banned = await one('SELECT 1 FROM live_bans WHERE stream_id = $1 AND user_id = $2', [s.id, req.user.id]);
    if (banned) throw forbidden('Вы не можете писать в этот чат');
    const body = String(req.body?.body || '').trim().slice(0, 500);
    if (!body) throw badRequest('Пустое сообщение');
    const slow = Number(req.settings['live.chat_slow_mode_sec']) || 0;
    if (slow > 0 && !isStaff(req.user) && req.user.id !== s.owner_id) {
      const last = await one('SELECT created_at FROM live_chat_messages WHERE stream_id = $1 AND user_id = $2 ORDER BY id DESC LIMIT 1', [s.id, req.user.id]);
      if (last && Date.now() - new Date(last.created_at).getTime() < slow * 1000) throw badRequest(`Медленный режим: одно сообщение раз в ${slow} с`);
    }
    const m = await one('INSERT INTO live_chat_messages(stream_id, user_id, body) VALUES ($1,$2,$3) RETURNING *', [s.id, req.user.id, body]);
    const msg = chatOut({ ...m, display_name: req.user.display_name, handle: req.user.handle, avatar_path: req.user.avatar_path, role: req.user.role });
    await toChannel(`live:${s.id}`, { type: 'chat', streamId: s.id, message: { ...msg, isOwner: req.user.id === s.owner_id } });
    return { message: msg };
  });

  app.delete('/live/:id/chat/:msgId', { preHandler: app.requireActive }, async (req) => {
    const s = await loadStream(req.params.id);
    if (!s) throw notFound();
    const m = await one('SELECT * FROM live_chat_messages WHERE id = $1 AND stream_id = $2', [Number(req.params.msgId), s.id]);
    if (!m) throw notFound();
    if (m.user_id !== req.user.id && s.owner_id !== req.user.id && !isStaff(req.user)) throw forbidden();
    await query('UPDATE live_chat_messages SET is_deleted = true WHERE id = $1', [m.id]);
    await toChannel(`live:${s.id}`, { type: 'chat_delete', streamId: s.id, messageId: m.id });
    return { ok: true };
  });

  app.post('/live/:id/ban', { preHandler: app.requireActive }, async (req) => {
    const s = await loadStream(req.params.id);
    if (!s) throw notFound();
    if (s.owner_id !== req.user.id && !isStaff(req.user)) throw forbidden();
    const userId = String(req.body?.userId || '');
    if (!userId || userId === s.owner_id) throw badRequest();
    await query('INSERT INTO live_bans(stream_id, user_id) VALUES ($1,$2) ON CONFLICT DO NOTHING', [s.id, userId]);
    await query('UPDATE live_chat_messages SET is_deleted = true WHERE stream_id = $1 AND user_id = $2', [s.id, userId]);
    await toChannel(`live:${s.id}`, { type: 'chat_ban', streamId: s.id, userId });
    return { ok: true };
  });

  // --- Студия: управление трансляциями ----------------------------------------------
  app.get('/studio/live', { preHandler: app.requireActive }, async (req) => {
    const rows = await many(`SELECT ${LIVE_SELECT} FROM ${LIVE_FROM} WHERE s.owner_id = $1 ORDER BY s.status = 'live' DESC, s.created_at DESC LIMIT 100`, [req.user.id]);
    return { streams: rows.map((s) => liveOut({ ...s, viewer_count: liveViewerCount(s.id) }, { viewer: req.user, withKey: false })), canStream: canStream(req.user, req.settings) };
  });

  app.post('/studio/live', { preHandler: app.requireActive }, async (req) => {
    if (!canStream(req.user, req.settings)) throw forbidden('Проведение трансляций вам не разрешено');
    const b = req.body || {};
    const title = String(b.title || '').trim().slice(0, 150);
    if (!title) throw badRequest('Укажите название трансляции');
    const visibility = ['public', 'internal', 'unlisted', 'private'].includes(b.visibility) ? b.visibility : req.settings['live.default_visibility'];
    const scheduled = b.scheduledAt ? new Date(b.scheduledAt) : null;
    const s = await one(
      `INSERT INTO live_streams(short_id, owner_id, title, description, category_id, visibility, stream_key, chat_enabled, record, scheduled_at)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10) RETURNING *`,
      [shortId(), req.user.id, title, String(b.description || '').slice(0, 5000), b.categoryId ? Number(b.categoryId) : null, visibility, `${randomToken(18)}`,
        b.chatEnabled !== false, b.record === undefined ? !!req.settings['live.record_default'] : !!b.record, scheduled && !Number.isNaN(scheduled.getTime()) ? scheduled : null],
    );
    await audit(req, 'live.create', { targetType: 'live', targetId: s.id });
    const full = await loadStream(s.id);
    return { stream: liveOut(full, { viewer: req.user, withKey: true }) };
  });

  app.patch('/studio/live/:id', { preHandler: app.requireActive }, async (req) => {
    const s = await loadStream(req.params.id);
    if (!s) throw notFound();
    if (s.owner_id !== req.user.id && !isStaff(req.user)) throw forbidden();
    const b = req.body || {};
    const sets = []; const params = [s.id];
    const add = (c, v) => { params.push(v); sets.push(`${c} = $${params.length}`); };
    if (b.title !== undefined) { const t = String(b.title).trim().slice(0, 150); if (!t) throw badRequest('Пустое название'); add('title', t); }
    if (b.description !== undefined) add('description', String(b.description).slice(0, 5000));
    if (b.visibility !== undefined && ['public', 'internal', 'unlisted', 'private'].includes(b.visibility)) add('visibility', b.visibility);
    if (b.categoryId !== undefined) add('category_id', b.categoryId ? Number(b.categoryId) : null);
    if (b.chatEnabled !== undefined) add('chat_enabled', !!b.chatEnabled);
    if (b.record !== undefined) add('record', !!b.record);
    if (b.dvr !== undefined) add('dvr', !!b.dvr);   // перемотка эфира назад (1.9)
    if (b.scheduledAt !== undefined) { const d = b.scheduledAt ? new Date(b.scheduledAt) : null; add('scheduled_at', d && !Number.isNaN(d.getTime()) ? d : null); }
    // Вебинар: регистрация участников (1.5)
    if (b.registration !== undefined) add('registration', !!b.registration);
    if (b.registrationLimit !== undefined) add('registration_limit', b.registrationLimit ? Math.max(1, Math.min(100000, Number(b.registrationLimit))) : null);
    if (b.registrationNote !== undefined) add('registration_note', String(b.registrationNote).slice(0, 1000));
    // Живые субтитры эфира (1.6)
    if (b.captions !== undefined) {
      add('captions', !!b.captions);
      // Включили посреди эфира — сразу запускаем распознавание
      if (b.captions && s.status === 'live') {
        try {
          const st = await loadSettings();
          if (st['asr.enabled'] && st['live.captions']) await enqueue('live_captions', { streamId: s.id }, { dedupe: true, dedupeKey: `stream:${s.id}`, maxAttempts: 2 });
        } catch { /* ignore */ }
      }
    }
    if (b.status === 'idle' && s.status === 'ended') { add('status', 'idle'); add('started_at', null); add('ended_at', null); add('recording_video_id', null); }
    if (sets.length) { sets.push('updated_at = now()'); await query(`UPDATE live_streams SET ${sets.join(', ')} WHERE id = $1`, params); }
    const full = await loadStream(s.id);
    await toChannel(`live:${s.id}`, { type: 'stream_update', stream: liveOut(full) });
    return { stream: liveOut(full, { viewer: req.user, withKey: true }) };
  });

  app.post('/studio/live/:id/reset-key', { preHandler: app.requireActive }, async (req) => {
    const s = await loadStream(req.params.id);
    if (!s) throw notFound();
    if (s.owner_id !== req.user.id && req.user.role !== 'admin') throw forbidden('Сменить ключ может владелец трансляции или администратор');
    if (s.status === 'live') throw badRequest('Нельзя сменить ключ во время эфира');
    const upd = await one('UPDATE live_streams SET stream_key = $2, updated_at = now() WHERE id = $1 RETURNING *', [s.id, randomToken(18)]);
    return { streamKey: upd.stream_key };
  });

  app.post('/studio/live/:id/end', { preHandler: app.requireActive }, async (req) => {
    const s = await loadStream(req.params.id);
    if (!s) throw notFound();
    if (s.owner_id !== req.user.id && !isStaff(req.user)) throw forbidden();
    try { await mediamtx.kick(`live/${s.stream_key}`); } catch { /* ignore */ }
    if (s.status === 'live') await endStream(s, 'manual');
    return { ok: true };
  });

  app.delete('/studio/live/:id', { preHandler: app.requireActive }, async (req) => {
    const s = await loadStream(req.params.id);
    if (!s) throw notFound();
    if (s.owner_id !== req.user.id && !isStaff(req.user)) throw forbidden();
    if (s.status === 'live') throw badRequest('Сначала завершите эфир');
    await query('DELETE FROM live_streams WHERE id = $1', [s.id]);
    return { ok: true };
  });

  // --- Перемотка эфира назад и просмотр с начала (DVR, 1.9) -----------------------------
  // Глубина перемотки ограничена настройкой; запись отдаёт сервер воспроизведения MediaMTX.
  app.get('/live/:id/dvr', async (req) => {
    const s = await loadStream(req.params.id);
    if (!s) throw notFound('Трансляция не найдена');
    if (!(await canViewLiveOrGuest(s, req.user, req.webinarTokens))) throw req.user ? forbidden() : unauthorized();
    const settings = req.settings || await loadSettings();
    const windowSec = Math.max(60, Number(settings['live.dvr_minutes']) || 120) * 60;
    if (!settings['live.dvr'] || s.dvr === false || !s.record) return { enabled: false, available: 0, windowSec };
    const items = await mediamtx.playbackList(`live/${s.stream_key}`);
    const startedAt = s.started_at ? new Date(s.started_at).getTime() : null;
    let from = null, until = null;
    for (const it of items) {
      const st = new Date(it.start).getTime();
      if (Number.isNaN(st)) continue;
      const en = st + (it.duration || 0) * 1000;
      if (startedAt && en < startedAt - 60000) continue;      // запись прошлого эфира
      from = from == null ? st : Math.min(from, st);
      until = until == null ? en : Math.max(until, en);
    }
    if (from == null) return { enabled: true, available: 0, windowSec, startedAt: s.started_at };
    const begin = Math.max(from, startedAt ? startedAt - 5000 : from, Date.now() - windowSec * 1000);
    const available = Math.max(0, Math.round((Math.min(until, Date.now()) - begin) / 1000));
    return { enabled: true, available, windowSec, startedAt: s.started_at, beginAt: new Date(begin).toISOString(), offsetSec: Math.max(0, Math.round((begin - (startedAt || begin)) / 1000)) };
  });

  // Воспроизведение записи идущего эфира: from — секунды от начала эфира, back — на сколько отмотать от «сейчас»
  app.get('/live/:id/dvr/play', async (req, reply) => {
    const s = await loadStream(req.params.id);
    if (!s) throw notFound();
    if (!(await canViewLiveOrGuest(s, req.user, req.webinarTokens))) throw req.user ? forbidden() : unauthorized();
    const settings = req.settings || await loadSettings();
    if (!settings['live.dvr'] || s.dvr === false) throw forbidden('Перемотка эфира отключена');
    const windowSec = Math.max(60, Number(settings['live.dvr_minutes']) || 120) * 60;
    const startedAt = s.started_at ? new Date(s.started_at).getTime() : Date.now();
    const back = Number(req.query.back);
    let at = Number.isFinite(back) && back > 0 ? Date.now() - Math.min(back, windowSec) * 1000 : startedAt + Math.max(0, Number(req.query.from) || 0) * 1000;
    at = Math.max(at, Date.now() - windowSec * 1000, startedAt - 5000);
    const duration = Math.max(10, Math.min(4 * 3600, Number(req.query.duration) || Math.round((Date.now() - at) / 1000) + 30));
    const target = mediamtx.playbackUrl(`live/${s.stream_key}`, new Date(at).toISOString(), duration, 'mp4');
    let res;
    try { res = await fetch(target, { headers: { 'user-agent': 'corpvideo-proxy' } }); }
    catch { reply.code(503); return { error: 'Запись эфира недоступна' }; }
    reply.code(res.status === 200 ? 200 : res.status);
    reply.header('content-type', res.headers.get('content-type') || 'video/mp4');
    reply.header('cache-control', 'no-store');
    if (!res.ok) return '';
    return reply.send(res.body);
  });

  // --- Ретрансляция на внешние площадки (1.9) --------------------------------------------
  async function ownStream(req) {
    const s = await loadStream(req.params.id);
    if (!s) throw notFound('Трансляция не найдена');
    if (s.owner_id !== req.user.id && !isStaff(req.user)) throw forbidden();
    return s;
  }
  const restreamOut = (r) => ({
    id: r.id, name: r.name, url: r.url, keyHint: r.stream_key ? `${String(r.stream_key).slice(0, 3)}…${String(r.stream_key).slice(-2)}` : '',
    enabled: r.enabled, status: r.status, lastError: r.last_error, startedAt: r.started_at, stoppedAt: r.stopped_at,
  });

  app.get('/studio/live/:id/restreams', { preHandler: app.requireActive }, async (req) => {
    const s = await ownStream(req);
    const rows = await many('SELECT * FROM live_restreams WHERE stream_id = $1 ORDER BY created_at', [s.id]);
    return { enabled: !!req.settings['live.restream_enabled'], restreams: rows.map(restreamOut) };
  });

  app.post('/studio/live/:id/restreams', { preHandler: app.requireActive }, async (req) => {
    const s = await ownStream(req);
    if (!req.settings['live.restream_enabled']) throw forbidden('Ретрансляция отключена администратором');
    const b = req.body || {};
    const name = String(b.name || '').trim().slice(0, 80);
    const url = String(b.url || '').trim().slice(0, 500);
    if (!name) throw badRequest('Укажите название площадки');
    const bad = await validTarget(url);
    if (bad) throw badRequest(bad);
    const n = await one('SELECT count(*)::int AS n FROM live_restreams WHERE stream_id = $1', [s.id]);
    if ((n?.n || 0) >= 8) throw badRequest('Больше восьми площадок на одну трансляцию не поддерживается');
    const row = await one('INSERT INTO live_restreams(stream_id, name, url, stream_key) VALUES ($1,$2,$3,$4) RETURNING *',
      [s.id, name, url, String(b.streamKey || '').trim().slice(0, 300)]);
    await audit(req, 'live.restream.add', { targetType: 'live', targetId: s.id, details: { name, url } });
    if (s.status === 'live' && row.enabled) await enqueue('restream', { restreamId: row.id }, { dedupe: true, dedupeKey: `restream:${row.id}`, maxAttempts: 1, priority: 1 });
    return { restream: restreamOut(row) };
  });

  app.patch('/studio/live/restreams/:rid', { preHandler: app.requireActive }, async (req) => {
    const r = await one('SELECT * FROM live_restreams WHERE id = $1', [req.params.rid]);
    if (!r) throw notFound('Площадка не найдена');
    const s = await loadStream(r.stream_id);
    if (s.owner_id !== req.user.id && !isStaff(req.user)) throw forbidden();
    const b = req.body || {};
    const sets = []; const params = [r.id];
    const add = (c, v) => { params.push(v); sets.push(`${c} = $${params.length}`); };
    if (b.name !== undefined) add('name', String(b.name).trim().slice(0, 80) || r.name);
    if (b.url !== undefined) { const bad = await validTarget(String(b.url).trim()); if (bad) throw badRequest(bad); add('url', String(b.url).trim().slice(0, 500)); }
    if (b.streamKey !== undefined) add('stream_key', String(b.streamKey).trim().slice(0, 300));
    if (b.enabled !== undefined) { add('enabled', !!b.enabled); if (!b.enabled) add('status', 'stopped'); }
    if (sets.length) await query(`UPDATE live_restreams SET ${sets.join(', ')} WHERE id = $1`, params);
    const upd = await one('SELECT * FROM live_restreams WHERE id = $1', [r.id]);
    if (upd.enabled && s.status === 'live' && req.settings['live.restream_enabled']) await enqueue('restream', { restreamId: upd.id }, { dedupe: true, dedupeKey: `restream:${upd.id}`, maxAttempts: 1, priority: 1 });
    return { restream: restreamOut(upd) };
  });

  app.delete('/studio/live/restreams/:rid', { preHandler: app.requireActive }, async (req) => {
    const r = await one('SELECT * FROM live_restreams WHERE id = $1', [req.params.rid]);
    if (!r) throw notFound('Площадка не найдена');
    const s = await loadStream(r.stream_id);
    if (s.owner_id !== req.user.id && !isStaff(req.user)) throw forbidden();
    await query('DELETE FROM live_restreams WHERE id = $1', [r.id]);
    return { ok: true };
  });

  // --- Хук авторизации MediaMTX (authMethod: http) -------------------------------------
  app.post('/live/hooks/auth', { config: { rateLimit: false } }, async (req, reply) => {
    const secret = config.mediamtx.hookSecret;
    // Пустой секрет раньше означал «пускать всех»: хук становился бесплатным оракулом
    // «существует ли такой ключ трансляции» и точкой нагрузки на базу.
    if (!secret) { req.log.error('MEDIAMTX_HOOK_SECRET не задан — хук авторизации отклоняет запросы'); reply.code(401); return { error: 'hook secret not configured' }; }
    if (req.query.secret !== secret) { reply.code(401); return { error: 'bad secret' }; }
    const b = req.body || {};
    const action = b.action;
    if (['api', 'metrics', 'pprof', 'playback'].includes(action)) { reply.code(200); return { ok: true }; }
    const m = String(b.path || '').match(/^live\/([A-Za-z0-9_-]+)$/);
    if (!m) { reply.code(404); return { error: 'unknown path' }; }
    const s = await loadStreamByKey(m[1]);
    if (!s) { reply.code(403); return { error: 'unknown stream key' }; }
    if (action === 'publish') {
      const owner = await one('SELECT * FROM users WHERE id = $1', [s.owner_id]);
      if (!owner || !canStream(owner, req.settings)) { reply.code(403); return { error: 'streaming not allowed' }; }
      if (s.status === 'live' && s.source_protocol && s.source_protocol !== b.protocol) { /* повторное подключение допускаем */ }
      reply.code(200);
      return { ok: true };
    }
    // read: только наш прокси / внутренние адреса
    reply.code(200);
    return { ok: true };
  });
}

function chatOut(m) {
  return {
    id: m.id, body: m.body, createdAt: m.created_at,
    user: { id: m.user_id, displayName: m.display_name, handle: m.handle, avatarUrl: mediaUrl(m.avatar_path), role: m.role },
  };
}

// --- Отслеживание состояния трансляций (опрос MediaMTX) ------------------------------
async function startStream(s, pathInfo) {
  const protocol = pathInfo?.source?.type ? String(pathInfo.source.type).replace(/Conn|Session/g, '').toLowerCase() : null;
  await query(`UPDATE live_streams SET status = 'live', started_at = now(), ended_at = NULL, source_protocol = $2, viewer_count = 0, updated_at = now() WHERE id = $1`, [s.id, protocol]);
  const full = await loadStream(s.id);
  // Живые субтитры: отдельное задание снимает звук порциями и распознаёт его, пока идёт эфир
  if (s.captions) {
    try {
      const st = await loadSettings();
      if (st['asr.enabled'] && st['live.captions']) await enqueue('live_captions', { streamId: s.id }, { dedupe: true, dedupeKey: `stream:${s.id}`, maxAttempts: 2 });
    } catch { /* субтитры не должны мешать эфиру */ }
  }
  // Ретрансляция на внешние площадки (1.9): по заданию на каждую включённую площадку
  try { await startRestreams(s.id, enqueue); } catch (e) { console.error('restream start failed:', e.message); }
  await publish({ type: 'live.started', streamId: s.id, shortId: s.short_id, ownerId: s.owner_id, visibility: s.visibility });
  await emitEvent('live.started', { stream: { id: s.id, shortId: s.short_id, title: s.title, ownerId: s.owner_id, visibility: s.visibility, protocol, url: `/live/${s.short_id}` } });
  await toChannel(`live:${s.id}`, { type: 'stream_status', streamId: s.id, status: 'live', stream: liveOut(full) });
  // уведомляем тех, кто просил напомнить (если напоминание ещё не отправлялось)
  const reminders = await many('SELECT r.user_id FROM live_reminders r WHERE r.stream_id = $1', [s.id]);
  if (!s.reminded_at) for (const r of reminders) await notify(r.user_id, { type: 'live_started', title: `Эфир начался: ${full.title}`, body: full.owner_name, link: `/live/${full.short_id}`, actorId: s.owner_id, data: { streamId: s.id } });
  const remindedIds = new Set(reminders.map((r) => r.user_id));
  // уведомляем подписчиков
  if (['public', 'internal'].includes(s.visibility)) {
    const subs = await many(`SELECT u.id, u.prefs FROM subscriptions sub JOIN users u ON u.id = sub.subscriber_id WHERE sub.channel_id = $1 AND sub.notify = 'all' AND u.status = 'active'`, [s.owner_id]);
    for (const u of subs) {
      if (remindedIds.has(u.id)) continue;
      await notify(u.id, { type: 'live_started', title: `${full.owner_name} в эфире`, body: full.title, link: `/live/${full.short_id}`, actorId: s.owner_id, data: { streamId: s.id } });
    }
  }
}

/**
 * Завершить эфир: единый путь для кнопки в студии, панели администратора и пропажи источника.
 * Возвращает false, если эфир уже был завершён другим путём (тогда события и импорт не повторяются).
 */
export async function endStream(s, reason = 'source_gone', { importRecording = true } = {}) {
  const r = await query(`UPDATE live_streams SET status = 'ended', ended_at = now(), viewer_count = 0, updated_at = now() WHERE id = $1 AND status = 'live'`, [s.id]);
  if (!r.rowCount) return false;
  await query(`UPDATE live_restreams SET status = 'stopped', stopped_at = now() WHERE stream_id = $1 AND status IN ('starting','live')`, [s.id]).catch(() => {});
  await publish({ type: 'live.ended', streamId: s.id, shortId: s.short_id, ownerId: s.owner_id, visibility: s.visibility });
  await emitEvent('live.ended', { stream: { id: s.id, shortId: s.short_id, title: s.title, ownerId: s.owner_id, visibility: s.visibility, reason, url: `/live/${s.short_id}` } });
  await toChannel(`live:${s.id}`, { type: 'stream_status', streamId: s.id, status: 'ended', reason });
  if (s.record && importRecording) {
    // Запись забирает воркер: MediaMTX дописывает последний сегмент несколько секунд, а задание
    // с отложенным стартом переживёт и перезапуск API (раньше здесь был setTimeout в процессе API).
    await enqueue('live_import', { streamId: s.id, discover: true, streamKey: s.stream_key, startedAt: s.started_at },
      { priority: 1, dedupe: false, maxAttempts: 5, runAt: new Date(Date.now() + 10000) });
  }
  return true;
}

let pollerStarted = false;
export function startLivePoller(app) {
  if (pollerStarted) return;
  pollerStarted = true;
  let busy = false;
  let lastThumb = new Map();
  const tick = async () => {
    if (busy) return; busy = true;
    try {
      const paths = await mediamtx.paths().catch(() => null);
      if (paths === null) return; // MediaMTX недоступен
      const readyKeys = new Map();
      for (const p of paths) {
        const m = String(p.name || '').match(/^live\/([A-Za-z0-9_-]+)$/);
        if (m && p.ready) readyKeys.set(m[1], p);
      }
      const liveNow = await many(`SELECT * FROM live_streams WHERE status = 'live'`);
      for (const s of liveNow) {
        if (!readyKeys.has(s.stream_key)) { await endStream(s); continue; }
        const n = liveViewerCount(s.id);
        await query('UPDATE live_streams SET viewer_count = $2, viewer_peak = greatest(viewer_peak, $2), total_views = greatest(total_views, $2) WHERE id = $1', [s.id, n]);
        if (Date.now() - (lastThumb.get(s.id) || 0) > 60000) {
          lastThumb.set(s.id, Date.now());
          captureThumb(s).catch(() => {});
        }
      }
      for (const [key, p] of readyKeys) {
        const s = await loadStreamByKey(key);
        if (s && s.status !== 'live') await startStream(s, p);
      }
      // Число зрителей в чат-канале рассылаем участникам
      for (const s of liveNow) await toChannel(`live:${s.id}`, { type: 'viewers', streamId: s.id, count: liveViewerCount(s.id) });
    } catch (e) {
      app.log.warn({ err: e.message }, 'live poller');
    } finally { busy = false; }
  };
  setInterval(tick, 3000).unref();
  tick();
}

async function captureThumb(s) {
  const dir = storage.liveDir(s.id);
  await ensureDir(dir);
  const out = path.join(dir, 'thumb.jpg');
  // Без ограничения по времени зависший плейлист оставлял по процессу ffmpeg на каждую минуту эфира
  await runFfmpeg(['-i', `${config.mediamtx.hlsUrl}/live/${s.stream_key}/index.m3u8`, '-frames:v', '1', '-vf', 'scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2:black', '-q:v', '4', out], { label: 'livethumb', signal: AbortSignal.timeout(20000) });
  await query('UPDATE live_streams SET thumbnail_path = $2, updated_at = now() WHERE id = $1', [s.id, storage.rel(out)]);
}
