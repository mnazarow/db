// Экраны-витрины: телевизор в холле показывает плейлист по кругу без интерфейса и без входа.
// Доступ — по токену экрана (как у цифровых вывесок): ссылку один раз открывают на устройстве.
import { one, many, query } from '../db.js';
import { badRequest, forbidden, notFound } from '../lib/util.js';
import { isStaff } from '../lib/access.js';
import { shortId } from '../lib/crypto.js';
import { videoCard } from '../lib/serialize.js';
import { audit } from '../lib/audit.js';
import { config } from '../config.js';
import { mediaUrl } from '../lib/urls.js';

const MAX_ITEMS = 50;

function screenOut(s, { withToken = false } = {}) {
  return {
    id: s.id,
    name: s.name,
    source: s.source,
    playlistId: s.playlist_id,
    playlistTitle: s.playlist_title || null,
    categoryId: s.category_id,
    categoryName: s.category_name || null,
    channelId: s.channel_id,
    channelName: s.channel_name || null,
    shuffle: !!s.shuffle,
    subtitles: !!s.subtitles,
    showTitle: !!s.show_title,
    muted: !!s.muted,
    isActive: !!s.is_active,
    lastSeenAt: s.last_seen_at,
    lastIp: s.last_ip,
    createdAt: s.created_at,
    token: withToken ? s.token : undefined,
    url: withToken ? `${config.baseUrl}/screen/${s.token}` : undefined,
  };
}

/** Что показывать на экране: только готовые видео, доступные всем сотрудникам. */
async function screenItems(s) {
  const base = `v.deleted_at IS NULL AND v.status = 'ready' AND v.is_blocked = false AND v.moderation_status = 'approved'
                AND (v.scheduled_at IS NULL OR v.scheduled_at <= now()) AND v.premiere = false
                AND v.visibility IN ('public','internal') AND v.is_short = false`;
  const order = s.shuffle ? 'random()' : 'v.published_at DESC NULLS LAST';
  if (s.source === 'playlist' && s.playlist_id) {
    return many(
      `SELECT v.* FROM playlist_items pi JOIN videos v ON v.id = pi.video_id
       WHERE pi.playlist_id = $1 AND ${base} ORDER BY ${s.shuffle ? 'random()' : 'pi.position'} LIMIT ${MAX_ITEMS}`, [s.playlist_id]);
  }
  if (s.source === 'category' && s.category_id) {
    return many(`SELECT v.* FROM videos v WHERE v.category_id = $1 AND ${base} ORDER BY ${order} LIMIT ${MAX_ITEMS}`, [s.category_id]);
  }
  if (s.source === 'channel' && s.channel_id) {
    return many(`SELECT v.* FROM videos v WHERE v.owner_id = $1 AND ${base} ORDER BY ${order} LIMIT ${MAX_ITEMS}`, [s.channel_id]);
  }
  return many(`SELECT v.* FROM videos v WHERE ${base} ORDER BY ${order} LIMIT ${MAX_ITEMS}`);
}

export default async function screenRoutes(app) {
  // --- Плейлист экрана (по токену, без входа) ----------------------------------------------------
  app.get('/screens/:token/playlist', async (req) => {
    const s = await one('SELECT * FROM screens WHERE token = $1', [String(req.params.token)]);
    if (!s) throw notFound('Экран не найден');
    if (!s.is_active) throw forbidden('Экран выключен администратором');
    // Адрес — тот, что определил сервер с учётом доверенного nginx (1.12): первый адрес из X-Forwarded-For
    // присылает сам клиент, его можно подделать
    const ip = String(req.ip || '').slice(0, 60);
    await query('UPDATE screens SET last_seen_at = now(), last_ip = $2 WHERE id = $1', [s.id, ip]);
    const rows = await screenItems(s);
    const subs = rows.length
      ? await many(`SELECT video_id, path FROM subtitles WHERE video_id = ANY($1::uuid[]) AND is_default = true`, [rows.map((r) => r.id)])
      : [];
    const subByVideo = new Map(subs.map((x) => [x.video_id, mediaUrl(x.path)]));
    return {
      screen: { name: s.name, subtitles: !!s.subtitles, showTitle: !!s.show_title, muted: !!s.muted, shuffle: !!s.shuffle },
      items: rows.map((v) => ({
        ...videoCard(v),
        hlsUrl: mediaUrl(v.hls_path),
        mp4Url: mediaUrl(v.mp4_path),
        subtitlesUrl: s.subtitles ? subByVideo.get(v.id) || null : null,
      })),
    };
  });

  // --- Управление экранами (администратор) -------------------------------------------------------
  app.get('/admin/screens', { preHandler: app.requireStaff }, async () => {
    const rows = await many(
      `SELECT s.*, p.title AS playlist_title, c.name AS category_name, u.display_name AS channel_name
       FROM screens s
       LEFT JOIN playlists p ON p.id = s.playlist_id
       LEFT JOIN categories c ON c.id = s.category_id
       LEFT JOIN users u ON u.id = s.channel_id
       ORDER BY s.created_at DESC`);
    return { screens: rows.map((s) => screenOut(s, { withToken: true })) };
  });

  app.post('/admin/screens', { preHandler: app.requireStaff }, async (req) => {
    const b = req.body || {};
    const name = String(b.name || '').trim().slice(0, 120);
    if (!name) throw badRequest('Укажите название экрана');
    const source = ['playlist', 'category', 'channel', 'latest'].includes(b.source) ? b.source : 'latest';
    const row = await one(
      `INSERT INTO screens(name, token, source, playlist_id, category_id, channel_id, shuffle, subtitles, show_title, muted, created_by)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11) RETURNING *`,
      [name, shortId(14), source, b.playlistId || null, b.categoryId || null, b.channelId || null,
        !!b.shuffle, b.subtitles !== false, b.showTitle !== false, b.muted !== false, req.user.id]);
    await audit(req, 'screen.created', { targetType: 'screen', targetId: row.id, meta: { name } });
    return { screen: screenOut(row, { withToken: true }) };
  });

  app.patch('/admin/screens/:id', { preHandler: app.requireStaff }, async (req) => {
    const s = await one('SELECT * FROM screens WHERE id = $1', [req.params.id]);
    if (!s) throw notFound('Экран не найден');
    const b = req.body || {};
    const sets = []; const vals = [s.id];
    const add = (col, val) => { vals.push(val); sets.push(`${col} = $${vals.length}`); };
    if (b.name !== undefined) add('name', String(b.name).trim().slice(0, 120) || s.name);
    if (b.source !== undefined && ['playlist', 'category', 'channel', 'latest'].includes(b.source)) add('source', b.source);
    if (b.playlistId !== undefined) add('playlist_id', b.playlistId || null);
    if (b.categoryId !== undefined) add('category_id', b.categoryId || null);
    if (b.channelId !== undefined) add('channel_id', b.channelId || null);
    if (b.shuffle !== undefined) add('shuffle', !!b.shuffle);
    if (b.subtitles !== undefined) add('subtitles', !!b.subtitles);
    if (b.showTitle !== undefined) add('show_title', !!b.showTitle);
    if (b.muted !== undefined) add('muted', !!b.muted);
    if (b.isActive !== undefined) add('is_active', !!b.isActive);
    if (b.rotateToken) add('token', shortId(14));
    if (!sets.length) return { screen: screenOut(s, { withToken: true }) };
    const row = await one(`UPDATE screens SET ${sets.join(', ')}, updated_at = now() WHERE id = $1 RETURNING *`, vals);
    await audit(req, 'screen.updated', { targetType: 'screen', targetId: s.id, meta: { fields: Object.keys(b) } });
    return { screen: screenOut(row, { withToken: true }) };
  });

  app.delete('/admin/screens/:id', { preHandler: app.requireStaff }, async (req, reply) => {
    const s = await one('SELECT * FROM screens WHERE id = $1', [req.params.id]);
    if (!s) throw notFound('Экран не найден');
    if (!isStaff(req.user)) throw forbidden();
    await query('DELETE FROM screens WHERE id = $1', [s.id]);
    await audit(req, 'screen.deleted', { targetType: 'screen', targetId: s.id, meta: { name: s.name } });
    reply.code(204);
  });

  /** Предпросмотр содержимого экрана в админке. */
  app.get('/admin/screens/:id/preview', { preHandler: app.requireStaff }, async (req) => {
    const s = await one('SELECT * FROM screens WHERE id = $1', [req.params.id]);
    if (!s) throw notFound('Экран не найден');
    const rows = await screenItems(s);
    return { count: rows.length, items: rows.slice(0, 12).map((v) => videoCard(v)) };
  });
}
