// Каналы (профили пользователей), подписки, личные разделы: профиль, история, понравившиеся, токены API.
import path from 'node:path';
import fs from 'node:fs';
import { pipeline } from 'node:stream/promises';
import { one, query, many, tx } from '../db.js';
import { isStaff, listVisibilitySql, canViewPlaylist } from '../lib/access.js';
import { userPublic, userSelf, videoCard, playlistOut, liveOut } from '../lib/serialize.js';
import { badRequest, forbidden, notFound, conflict, paging, extOf, slugify, likeEscape } from '../lib/util.js';
import { storage, ensureDir, removeFile } from '../lib/storage.js';
import { resizeImage } from '../lib/ffmpeg.js';
import { VIDEO_SELECT, VIDEO_FROM } from './videos.js';
import { sha256, randomToken } from '../lib/crypto.js';
import { invalidateSessionCache } from '../plugins/auth.js';
import { audit } from '../lib/audit.js';
import { notify } from '../lib/notify.js';
import { ensureFeedToken } from './feeds.js';
import { config } from '../config.js';
import { mediaUrl } from '../lib/urls.js';
import { portalEnabled } from '../lib/portal.js';

async function loadChannel(handleOrId) {
  const key = String(handleOrId).replace(/^@/, '');
  return one('SELECT * FROM users WHERE (handle = $1 OR id = cv_uuid($1)) AND deleted_at IS NULL', [key]);
}

async function saveImage(req, kind, size) {
  const part = await req.file({ limits: { fileSize: 15 * 1024 * 1024 } });
  if (!part) throw badRequest('Ожидается файл изображения');
  const ext = extOf(part.filename);
  if (!['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'].includes(ext)) throw badRequest('Поддерживаются JPG, PNG, WEBP');
  const dir = kind === 'avatar' ? storage.avatarsDir() : storage.bannersDir();
  await ensureDir(dir);
  const tmp = path.join(dir, `${req.user.id}_tmp.${ext}`);
  await pipeline(part.file, fs.createWriteStream(tmp));
  if (part.file.truncated) { await removeFile(tmp); throw badRequest('Файл слишком большой'); }
  const out = path.join(dir, `${req.user.id}_${Date.now()}.jpg`);
  try { await resizeImage(tmp, out, size); } catch { throw badRequest('Не удалось обработать изображение'); } finally { await removeFile(tmp); }
  return storage.rel(out);
}

export default async function userRoutes(app) {
  // --- Каналы -----------------------------------------------------------------
  app.get('/channels/:handle', async (req) => {
    const u = await loadChannel(req.params.handle);
    if (!u) throw notFound('Канал не найден');
    const out = userPublic(u);
    let subscribed = false, notify = 'all';
    if (req.user) {
      const s = await one('SELECT notify FROM subscriptions WHERE subscriber_id = $1 AND channel_id = $2', [req.user.id, u.id]);
      subscribed = !!s; notify = s?.notify || 'all';
    }
    const counts = await one(`SELECT count(*)::int AS videos, coalesce(sum(view_count),0)::bigint AS views FROM videos v WHERE v.owner_id = $1 AND ${listVisibilitySql(req.user)}`, [u.id]);
    const live = await one(`SELECT s.*, u.display_name AS owner_name, u.handle AS owner_handle, u.avatar_path AS owner_avatar FROM live_streams s JOIN users u ON u.id = s.owner_id WHERE s.owner_id = $1 AND s.status = 'live' AND s.visibility IN ('public','internal') ORDER BY started_at DESC LIMIT 1`, [u.id]);
    return { channel: { ...out, videoCount: counts.videos, totalViews: Number(counts.views), subscribed, notify, live: live ? liveOut(live, { viewer: req.user }) : null } };
  });

  app.get('/channels/:handle/videos', async (req) => {
    const u = await loadChannel(req.params.handle);
    if (!u) throw notFound('Канал не найден');
    const { limit, offset, page } = paging(req.query, 24, 100);
    const isOwner = req.user && (req.user.id === u.id || isStaff(req.user));
    const sort = { popular: 'v.view_count DESC', oldest: 'v.published_at ASC', newest: 'v.published_at DESC' }[req.query.sort] || 'v.published_at DESC';
    let where = `v.owner_id = $1 AND ${listVisibilitySql(req.user)}`;
    if (isOwner && req.query.all === '1') where = `v.owner_id = $1 AND v.deleted_at IS NULL`;
    const params = [u.id, limit, offset];
    if (req.query.shorts === '1') where += ' AND v.is_short = true';
    if (req.query.live === '1') where += ' AND v.is_live_recording = true';
    const rows = await many(`SELECT ${VIDEO_SELECT} FROM ${VIDEO_FROM} WHERE ${where} ORDER BY ${sort} NULLS LAST LIMIT $2 OFFSET $3`, params);
    const total = await one(`SELECT count(*)::int AS n FROM ${VIDEO_FROM} WHERE ${where}`, [u.id]);
    return { videos: rows.map((r) => videoCard(r)), total: total.n, page, limit };
  });

  app.get('/channels/:handle/playlists', async (req) => {
    const u = await loadChannel(req.params.handle);
    if (!u) throw notFound('Канал не найден');
    const rows = await many(
      `SELECT p.*, u.display_name AS owner_name, u.handle AS owner_handle, u.avatar_path AS owner_avatar,
        (SELECT v.thumbnail_path FROM playlist_items pi JOIN videos v ON v.id = pi.video_id WHERE pi.playlist_id = p.id AND v.deleted_at IS NULL ORDER BY pi.position LIMIT 1) AS first_thumbnail
       FROM playlists p JOIN users u ON u.id = p.owner_id WHERE p.owner_id = $1 AND p.kind = 'normal' ORDER BY p.updated_at DESC`,
      [u.id],
    );
    return { playlists: rows.filter((p) => canViewPlaylist(p, req.user)).map(playlistOut) };
  });

  app.get('/channels/:handle/live', async (req) => {
    const u = await loadChannel(req.params.handle);
    if (!u) throw notFound('Канал не найден');
    const rows = await many(`SELECT s.*, u.display_name AS owner_name, u.handle AS owner_handle, u.avatar_path AS owner_avatar FROM live_streams s JOIN users u ON u.id = s.owner_id WHERE s.owner_id = $1 AND s.status IN ('live','idle') AND s.visibility IN ('public','internal') ORDER BY s.status = 'live' DESC, s.scheduled_at NULLS LAST, s.created_at DESC LIMIT 20`, [u.id]);
    return { streams: rows.filter((s) => s.visibility === 'public' || (req.user && req.user.status === 'active')).map((s) => liveOut(s, { viewer: req.user })) };
  });

  // --- Подписки -----------------------------------------------------------------
  app.post('/channels/:handle/subscribe', { preHandler: app.requireActive }, async (req) => {
    const u = await loadChannel(req.params.handle);
    if (!u) throw notFound('Канал не найден');
    if (u.id === req.user.id) throw badRequest('Нельзя подписаться на себя');
    const ins = await one('INSERT INTO subscriptions(subscriber_id, channel_id) VALUES ($1,$2) ON CONFLICT DO NOTHING RETURNING *', [req.user.id, u.id]);
    if (ins) {
      await query('UPDATE users SET subscriber_count = (SELECT count(*) FROM subscriptions WHERE channel_id = $1) WHERE id = $1', [u.id]);
      await query(`INSERT INTO channel_stats_daily(user_id, day, subscribers_gained) VALUES ($1, current_date, 1) ON CONFLICT (user_id, day) DO UPDATE SET subscribers_gained = channel_stats_daily.subscribers_gained + 1`, [u.id]);
      await notify(u.id, { type: 'subscribe', title: `${req.user.display_name} подписался(ась) на ваш канал`, link: `/@${req.user.handle}`, actorId: req.user.id });
    }
    const fresh = await one('SELECT subscriber_count FROM users WHERE id = $1', [u.id]);
    return { ok: true, subscribed: true, subscriberCount: fresh.subscriber_count };
  });

  app.delete('/channels/:handle/subscribe', { preHandler: app.requireActive }, async (req) => {
    const u = await loadChannel(req.params.handle);
    if (!u) throw notFound('Канал не найден');
    const del = await one('DELETE FROM subscriptions WHERE subscriber_id = $1 AND channel_id = $2 RETURNING *', [req.user.id, u.id]);
    if (del) {
      await query('UPDATE users SET subscriber_count = (SELECT count(*) FROM subscriptions WHERE channel_id = $1) WHERE id = $1', [u.id]);
      await query(`INSERT INTO channel_stats_daily(user_id, day, subscribers_lost) VALUES ($1, current_date, 1) ON CONFLICT (user_id, day) DO UPDATE SET subscribers_lost = channel_stats_daily.subscribers_lost + 1`, [u.id]);
    }
    const fresh = await one('SELECT subscriber_count FROM users WHERE id = $1', [u.id]);
    return { ok: true, subscribed: false, subscriberCount: fresh.subscriber_count };
  });

  app.patch('/channels/:handle/subscribe', { preHandler: app.requireActive }, async (req) => {
    const u = await loadChannel(req.params.handle);
    if (!u) throw notFound('Канал не найден');
    const notifyMode = req.body?.notify === 'none' ? 'none' : 'all';
    await query('UPDATE subscriptions SET notify = $3 WHERE subscriber_id = $1 AND channel_id = $2', [req.user.id, u.id, notifyMode]);
    return { ok: true, notify: notifyMode };
  });

  app.get('/me/subscriptions', { preHandler: app.requireActive }, async (req) => {
    const rows = await many(
      `SELECT u.*, s.notify, s.created_at AS subscribed_at,
        (SELECT count(*)::int FROM videos v WHERE v.owner_id = u.id AND v.published_at > now() - interval '7 days' AND ${listVisibilitySql(req.user)}) AS new_videos,
        EXISTS(SELECT 1 FROM live_streams l WHERE l.owner_id = u.id AND l.status = 'live') AS is_live
       FROM subscriptions s JOIN users u ON u.id = s.channel_id WHERE s.subscriber_id = $1 AND u.deleted_at IS NULL ORDER BY u.display_name`,
      [req.user.id],
    );
    return { channels: rows.map((r) => ({ ...userPublic(r), notify: r.notify, subscribedAt: r.subscribed_at, newVideos: r.new_videos, isLive: r.is_live })) };
  });

  // --- Профиль ------------------------------------------------------------------
  app.patch('/me', { preHandler: app.requireAuth }, async (req) => {
    const b = req.body || {};
    const sets = [];
    const params = [req.user.id];
    const add = (col, val) => { params.push(val); sets.push(`${col} = $${params.length}`); };
    // Сотрудник портала документации: имя приходит из портала (иначе следующая сверка вернула бы прежнее)
    if (b.displayName !== undefined && !(portalEnabled() && req.user.auth_provider === 'portal')) { const n = String(b.displayName).trim().slice(0, 80); if (n.length < 2) throw badRequest('Имя слишком короткое'); add('display_name', n); }
    if (b.bio !== undefined) add('bio', String(b.bio).slice(0, 2000));
    if (b.links !== undefined) {
      const links = (Array.isArray(b.links) ? b.links : []).map((l) => ({ title: String(l.title || '').slice(0, 40), url: String(l.url || '').slice(0, 300) })).filter((l) => /^https?:\/\//.test(l.url)).slice(0, 10);
      add('links', JSON.stringify(links));
    }
    if (b.handle !== undefined) {
      const h = String(b.handle).trim().toLowerCase().replace(/^@/, '');
      if (!/^[a-z0-9][a-z0-9._-]{1,29}$/.test(h)) throw badRequest('Имя канала: 2–30 символов, латиница, цифры, точки, дефисы');
      const taken = await one('SELECT 1 FROM users WHERE handle = $1 AND id <> $2', [h, req.user.id]);
      if (taken) throw conflict('Это имя канала уже занято');
      add('handle', h);
    }
    if (b.prefs !== undefined && typeof b.prefs === 'object') {
      const merged = { ...(req.user.prefs || {}), ...b.prefs };
      add('prefs', JSON.stringify(merged));
    }
    if (!sets.length) return { user: userSelf(req.user) };
    sets.push('updated_at = now()');
    await query(`UPDATE users SET ${sets.join(', ')} WHERE id = $1`, params);
    invalidateSessionCache(null, req.user.id);
    const fresh = await one('SELECT * FROM users WHERE id = $1', [req.user.id]);
    return { user: userSelf(fresh) };
  });

  app.post('/me/avatar', { preHandler: app.requireAuth }, async (req) => {
    const rel = await saveImage(req, 'avatar', { width: 400, height: 400, mode: 'cover' });
    const old = await one('SELECT avatar_path FROM users WHERE id = $1', [req.user.id]);
    await query('UPDATE users SET avatar_path = $2, updated_at = now() WHERE id = $1', [req.user.id, rel]);
    if (old?.avatar_path) await removeFile(storage.abs(old.avatar_path));
    invalidateSessionCache(null, req.user.id);
    return { avatarUrl: mediaUrl(rel) };
  });
  app.delete('/me/avatar', { preHandler: app.requireAuth }, async (req) => {
    const old = await one('SELECT avatar_path FROM users WHERE id = $1', [req.user.id]);
    await query('UPDATE users SET avatar_path = NULL WHERE id = $1', [req.user.id]);
    if (old?.avatar_path) await removeFile(storage.abs(old.avatar_path));
    invalidateSessionCache(null, req.user.id);
    return { ok: true };
  });
  app.post('/me/banner', { preHandler: app.requireAuth }, async (req) => {
    const rel = await saveImage(req, 'banner', { width: 2048, height: 512, mode: 'cover' });
    const old = await one('SELECT banner_path FROM users WHERE id = $1', [req.user.id]);
    await query('UPDATE users SET banner_path = $2, updated_at = now() WHERE id = $1', [req.user.id, rel]);
    if (old?.banner_path) await removeFile(storage.abs(old.banner_path));
    invalidateSessionCache(null, req.user.id);
    return { bannerUrl: mediaUrl(rel) };
  });
  app.delete('/me/banner', { preHandler: app.requireAuth }, async (req) => {
    const old = await one('SELECT banner_path FROM users WHERE id = $1', [req.user.id]);
    await query('UPDATE users SET banner_path = NULL WHERE id = $1', [req.user.id]);
    if (old?.banner_path) await removeFile(storage.abs(old.banner_path));
    invalidateSessionCache(null, req.user.id);
    return { ok: true };
  });

  // --- История ------------------------------------------------------------------
  app.get('/me/history', { preHandler: app.requireActive }, async (req) => {
    const { limit, offset, page } = paging(req.query, 24, 100);
    const q = String(req.query.q || '').trim();
    const params = [req.user.id];
    let extra = '';
    if (q) { params.push(`%${likeEscape(q)}%`); extra = ` AND v.title ILIKE $${params.length}`; }
    params.push(limit, offset);
    const rows = await many(
      `SELECT * FROM (
         SELECT DISTINCT ON (v.id) ${VIDEO_SELECT}, vv.position AS progress_position, vv.last_at, vv.completed
         FROM video_views vv JOIN ${VIDEO_FROM} ON v.id = vv.video_id
         WHERE vv.user_id = $1 AND v.deleted_at IS NULL${extra} ORDER BY v.id, vv.last_at DESC
       ) t ORDER BY last_at DESC LIMIT $${params.length - 1} OFFSET $${params.length}`,
      params,
    );
    return { videos: rows.map((r) => ({ ...videoCard(r), lastWatchedAt: r.last_at, completed: r.completed })), page, limit };
  });
  app.delete('/me/history', { preHandler: app.requireActive }, async (req) => {
    await query('DELETE FROM video_views WHERE user_id = $1', [req.user.id]);
    return { ok: true };
  });
  app.delete('/me/history/:videoId', { preHandler: app.requireActive }, async (req) => {
    await query('DELETE FROM video_views WHERE user_id = $1 AND video_id = cv_uuid($2)', [req.user.id, req.params.videoId]);
    return { ok: true };
  });

  app.get('/me/liked', { preHandler: app.requireActive }, async (req) => {
    const { limit, offset, page } = paging(req.query, 24, 100);
    const rows = await many(`SELECT ${VIDEO_SELECT}, l.created_at AS liked_at FROM video_likes l JOIN ${VIDEO_FROM} ON v.id = l.video_id WHERE l.user_id = $1 AND l.value = 1 AND v.deleted_at IS NULL ORDER BY l.created_at DESC LIMIT $2 OFFSET $3`, [req.user.id, limit, offset]);
    const total = await one('SELECT count(*)::int AS n FROM video_likes l JOIN videos v ON v.id = l.video_id WHERE l.user_id = $1 AND l.value = 1 AND v.deleted_at IS NULL', [req.user.id]);
    return { videos: rows.map((r) => ({ ...videoCard(r), likedAt: r.liked_at })), total: total.n, page, limit };
  });

  // «Продолжить просмотр»
  app.get('/me/continue', { preHandler: app.requireActive }, async (req) => {
    const rows = await many(
      `SELECT * FROM (
         SELECT DISTINCT ON (v.id) ${VIDEO_SELECT}, vv.position AS progress_position, vv.last_at
         FROM video_views vv JOIN ${VIDEO_FROM} ON v.id = vv.video_id
         WHERE vv.user_id = $1 AND vv.completed = false AND vv.position > 15 AND v.duration > 60 AND vv.position < v.duration * 0.95 AND ${listVisibilitySql(req.user)}
         ORDER BY v.id, vv.last_at DESC
       ) t ORDER BY last_at DESC LIMIT 12`,
      [req.user.id],
    );
    return { videos: rows.map((r) => videoCard(r)) };
  });

  // --- Токены API ------------------------------------------------------------------
  // --- Telegram: привязка бота ---------------------------------------------------------------
  app.post('/me/telegram/link', { preHandler: app.requireActive }, async (req) => {
    if (!req.settings['telegram.enabled'] || !req.settings['telegram.bot_token']) throw badRequest('Уведомления в Telegram не настроены администратором');
    const { createLinkCode } = await import('../lib/telegram.js');
    return createLinkCode(req.user.id, req.settings);
  });
  app.delete('/me/telegram', { preHandler: app.requireAuth }, async (req) => {
    await query('UPDATE users SET telegram_chat_id = NULL, telegram_username = NULL, telegram_link_code = NULL WHERE id = $1', [req.user.id]);
    return { ok: true };
  });
  app.get('/me/telegram', { preHandler: app.requireAuth }, async (req) => {
    const u = await one('SELECT telegram_chat_id, telegram_username, telegram_linked_at, telegram_link_code FROM users WHERE id = $1', [req.user.id]);
    return { linked: !!u?.telegram_chat_id, username: u?.telegram_username || null, linkedAt: u?.telegram_linked_at || null, pendingCode: u?.telegram_link_code || null, botUsername: req.settings['telegram.bot_username'] || null };
  });

  // Личный токен RSS-лент (1.3): ссылки на ленты подписок и «для сотрудников»
  app.get('/me/feed-token', { preHandler: app.requireActive }, async (req) => {
    const token = await ensureFeedToken(req.user.id);
    const base = `${config.baseUrl}/api/rss`;
    const row = await one('SELECT feed_token_created_at FROM users WHERE id = $1', [req.user.id]);
    const days = Math.max(0, Number(req.settings['feeds.token_days']) || 0);
    const expiresAt = days && row?.feed_token_created_at ? new Date(new Date(row.feed_token_created_at).getTime() + days * 86400000) : null;
    return {
      token, createdAt: row?.feed_token_created_at || null, expiresAt,
      feeds: { subscriptions: `${base}/subscriptions?ft=${token}`, latest: `${base}/latest?ft=${token}`, podcast: `${base}/subscriptions?ft=${token}&audio=1` },
      enabled: !!req.settings['feeds.enabled'],
    };
  });
  app.post('/me/feed-token/reset', { preHandler: app.requireActive }, async (req) => {
    const token = await ensureFeedToken(req.user.id, { reset: true });
    await audit(req, 'user.feed_token_reset', { targetType: 'user', targetId: req.user.id });
    return { token };
  });

  app.get('/me/tokens', { preHandler: app.requireActive }, async (req) => {
    const rows = await many('SELECT id, name, token_prefix, scopes, last_used_at, expires_at, created_at FROM api_tokens WHERE user_id = $1 ORDER BY created_at DESC', [req.user.id]);
    return { tokens: rows.map((r) => ({ id: r.id, name: r.name, prefix: r.token_prefix, scopes: r.scopes, lastUsedAt: r.last_used_at, expiresAt: r.expires_at, createdAt: r.created_at })) };
  });
  app.post('/me/tokens', { preHandler: app.requireActive }, async (req) => {
    const name = String(req.body?.name || '').trim().slice(0, 60) || 'Токен';
    const raw = `cv_${randomToken(30)}`;
    const days = Number(req.body?.expiresDays) || 0;
    const row = await one('INSERT INTO api_tokens(user_id, name, token_hash, token_prefix, scopes, expires_at) VALUES ($1,$2,$3,$4,$5,$6) RETURNING *',
      [req.user.id, name, sha256(raw), raw.slice(0, 10), ['upload', 'read'], days > 0 ? new Date(Date.now() + days * 86400000) : null]);
    await audit(req, 'user.token_create', { targetType: 'token', targetId: row.id });
    return { token: raw, id: row.id, name };
  });
  app.delete('/me/tokens/:id', { preHandler: app.requireActive }, async (req) => {
    await query('DELETE FROM api_tokens WHERE id = $1 AND user_id = $2', [req.params.id, req.user.id]);
    return { ok: true };
  });

  // Поиск пользователей (для предоставления доступа к приватным видео, упоминаний)
  app.get('/users/lookup', { preHandler: app.requireActive }, async (req) => {
    const q = String(req.query.q || '').trim().toLowerCase();
    if (q.length < 2) return { users: [] };
    const rows = await many(`SELECT id, handle, display_name, avatar_path, email FROM users WHERE status = 'active' AND deleted_at IS NULL AND (handle ILIKE $1 OR display_name ILIKE $1 OR email ILIKE $1) ORDER BY display_name LIMIT 10`, [`%${likeEscape(q)}%`]);
    return { users: rows.map((r) => ({ ...userPublic(r), email: r.email })) };
  });
}
