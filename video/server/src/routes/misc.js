// Уведомления, публичная конфигурация, студия автора (список видео, аналитика канала), здоровье.
import { one, many, query } from '../db.js';
import { publicSettings } from '../lib/settings.js';
import { notificationOut, videoCard } from '../lib/serialize.js';
import { markRead } from '../lib/notify.js';
import { paging, badRequest, likeEscape } from '../lib/util.js';
import { VIDEO_SELECT, VIDEO_FROM, rangeDays } from './videos.js';
import { canUpload, canStream } from '../lib/access.js';
import { config } from '../config.js';
import { pool } from '../db.js';

export default async function miscRoutes(app) {
  app.get('/config', async (req) => {
    const s = await publicSettings();
    return {
      ...s,
      version: config.version,
      baseUrl: config.baseUrl,
      // Префикс пути портала в браузере ('' или '/video'); у собранного интерфейса свой — VITE_BASE
      basePath: config.basePath,
      permissions: req.user ? { canUpload: canUpload(req.user, req.settings), canStream: canStream(req.user, req.settings) } : { canUpload: false, canStream: false },
      live: { rtmpUrl: config.mediamtx.rtmpPublicUrl, srtUrl: config.mediamtx.srtPublicUrl, whipUrl: config.mediamtx.whipPublicUrl },
    };
  });

  // Метка экземпляра: постоянна, пока процесс жив. Если на один адрес отвечают несколько
  // экземпляров портала (осталась служба от прежней установки, балансировщик на два сервера),
  // это сразу видно по разным меткам — а загрузка по частям при этом работать не может.
  const INSTANCE = `${process.pid.toString(36)}-${Math.random().toString(36).slice(2, 8)}`;

  app.get('/health', async (req, reply) => {
    let db = 'ok';
    try { await pool.query('SELECT 1'); } catch (e) { db = 'error: ' + e.message; }
    if (db !== 'ok') reply.code(503); // для систем мониторинга
    return { status: db === 'ok' ? 'ok' : 'degraded', db, version: config.version, uptime: Math.round(process.uptime()), instance: INSTANCE };
  });

  // --- Уведомления --------------------------------------------------------------
  app.get('/notifications', { preHandler: app.requireAuth }, async (req) => {
    const { limit, offset, page } = paging(req.query, 20, 100);
    // Картинки удалённых видео не показываем (файлы уже недоступны)
    const rows = await many(
      `SELECT n.*, CASE WHEN v.id IS NOT NULL AND v.deleted_at IS NOT NULL THEN NULL ELSE n.image END AS image
       FROM notifications n LEFT JOIN videos v ON v.id = cv_uuid(n.data->>'videoId')
       WHERE n.user_id = $1 ORDER BY n.created_at DESC LIMIT $2 OFFSET $3`, [req.user.id, limit, offset]);
    const unread = await one('SELECT count(*)::int AS n FROM notifications WHERE user_id = $1 AND read_at IS NULL', [req.user.id]);
    return { notifications: rows.map(notificationOut), unread: unread.n, page, limit };
  });
  app.post('/notifications/read', { preHandler: app.requireAuth }, async (req) => {
    const ids = Array.isArray(req.body?.ids) ? req.body.ids.map(Number).filter(Number.isFinite) : null;
    await markRead(req.user.id, ids && ids.length ? ids : null);
    return { ok: true };
  });
  app.delete('/notifications/:id', { preHandler: app.requireAuth }, async (req) => {
    await query('DELETE FROM notifications WHERE id = $1 AND user_id = $2', [Number(req.params.id), req.user.id]);
    return { ok: true };
  });

  // --- Студия: мои видео -----------------------------------------------------------
  app.get('/studio/videos', { preHandler: app.requireActive }, async (req) => {
    const { limit, offset, page } = paging(req.query, 20, 100);
    const params = [req.user.id];
    const where = ['v.owner_id = $1', 'v.deleted_at IS NULL'];
    if (req.query.status && ['uploading', 'queued', 'processing', 'ready', 'failed'].includes(req.query.status)) { params.push(req.query.status); where.push(`v.status = $${params.length}`); }
    if (req.query.visibility && ['public', 'internal', 'unlisted', 'private'].includes(req.query.visibility)) { params.push(req.query.visibility); where.push(`v.visibility = $${params.length}`); }
    if (req.query.moderation && ['pending', 'approved', 'rejected'].includes(req.query.moderation)) { params.push(req.query.moderation); where.push(`v.moderation_status = $${params.length}`); }
    if (req.query.q) { params.push(`%${likeEscape(String(req.query.q).trim())}%`); where.push(`v.title ILIKE $${params.length}`); }
    const sort = { views: 'v.view_count DESC', title: 'v.title ASC', oldest: 'v.created_at ASC', likes: 'v.like_count DESC', comments: 'v.comment_count DESC' }[req.query.sort] || 'v.created_at DESC';
    const total = await one(`SELECT count(*)::int AS n FROM ${VIDEO_FROM} WHERE ${where.join(' AND ')}`, params);
    params.push(limit, offset);
    const rows = await many(`SELECT ${VIDEO_SELECT} FROM ${VIDEO_FROM} WHERE ${where.join(' AND ')} ORDER BY ${sort} LIMIT $${params.length - 1} OFFSET $${params.length}`, params);
    const summary = await one(
      `SELECT count(*)::int AS videos, coalesce(sum(view_count),0)::bigint AS views, coalesce(sum(storage_bytes),0)::bigint AS bytes, coalesce(sum(like_count),0)::int AS likes, coalesce(sum(comment_count),0)::int AS comments,
        count(*) FILTER (WHERE status IN ('queued','processing'))::int AS processing, count(*) FILTER (WHERE moderation_status = 'pending')::int AS pending
       FROM videos WHERE owner_id = $1 AND deleted_at IS NULL`,
      [req.user.id],
    );
    return {
      videos: rows.map((r) => ({ ...videoCard(r), dislikeCount: r.dislike_count, storageBytes: r.storage_bytes, allowDownload: r.allow_download, tags: r.tags, updatedAt: r.updated_at, processingError: r.processing_error, moderationNote: r.moderation_note, originalKept: r.original_kept })),
      total: total.n, page, limit,
      summary: { videos: summary.videos, views: Number(summary.views), bytes: Number(summary.bytes), likes: summary.likes, comments: summary.comments, processing: summary.processing, pending: summary.pending },
    };
  });

  // --- Аналитика канала ---------------------------------------------------------------
  app.get('/studio/analytics', { preHandler: app.requireActive }, async (req) => {
    const days = rangeDays(req.query.range);
    const uid = req.user.id;
    const daily = await many(
      `SELECT d.day, sum(d.views)::int AS views, sum(d.watch_seconds)::bigint AS watch_seconds, sum(d.likes)::int AS likes, sum(d.comments)::int AS comments
       FROM video_stats_daily d JOIN videos v ON v.id = d.video_id WHERE v.owner_id = $1 AND v.deleted_at IS NULL AND d.day >= current_date - ($2 || ' days')::interval GROUP BY d.day ORDER BY d.day`,
      [uid, String(days)],
    );
    const subs = await many(`SELECT day, subscribers_gained, subscribers_lost FROM channel_stats_daily WHERE user_id = $1 AND day >= current_date - ($2 || ' days')::interval ORDER BY day`, [uid, String(days)]);
    const top = await many(
      `SELECT ${VIDEO_SELECT}, coalesce((SELECT sum(views) FROM video_stats_daily d WHERE d.video_id = v.id AND d.day >= current_date - ($2 || ' days')::interval), 0)::int AS range_views,
         coalesce((SELECT sum(watch_seconds) FROM video_stats_daily d WHERE d.video_id = v.id AND d.day >= current_date - ($2 || ' days')::interval), 0)::bigint AS range_watch
       FROM ${VIDEO_FROM} WHERE v.owner_id = $1 AND v.deleted_at IS NULL ORDER BY range_views DESC, v.view_count DESC LIMIT 10`,
      [uid, String(days)],
    );
    const sources = {}, devices = {};
    const agg = await many(`SELECT d.sources, d.devices FROM video_stats_daily d JOIN videos v ON v.id = d.video_id WHERE v.owner_id = $1 AND d.day >= current_date - ($2 || ' days')::interval`, [uid, String(days)]);
    for (const a of agg) {
      for (const [k, n] of Object.entries(a.sources || {})) sources[k] = (sources[k] || 0) + Number(n);
      for (const [k, n] of Object.entries(a.devices || {})) devices[k] = (devices[k] || 0) + Number(n);
    }
    const lifetime = await one(`SELECT coalesce(sum(view_count),0)::bigint AS views, coalesce(sum(watch_seconds),0)::bigint AS watch, coalesce(sum(like_count),0)::int AS likes, count(*)::int AS videos FROM videos WHERE owner_id = $1 AND deleted_at IS NULL`, [uid]);
    const totals = daily.reduce((a, d) => ({ views: a.views + d.views, watch: a.watch + Number(d.watch_seconds), likes: a.likes + d.likes, comments: a.comments + d.comments }), { views: 0, watch: 0, likes: 0, comments: 0 });
    const subTotals = subs.reduce((a, d) => ({ gained: a.gained + d.subscribers_gained, lost: a.lost + d.subscribers_lost }), { gained: 0, lost: 0 });
    return {
      range: days,
      totals: { ...totals, subscribersGained: subTotals.gained, subscribersLost: subTotals.lost, subscribers: req.user.subscriber_count },
      lifetime: { views: Number(lifetime.views), watchSeconds: Number(lifetime.watch), likes: lifetime.likes, videos: lifetime.videos },
      daily: daily.map((d) => ({ day: d.day, views: d.views, watchSeconds: Number(d.watch_seconds), likes: d.likes, comments: d.comments })),
      subscribersDaily: subs.map((d) => ({ day: d.day, gained: d.subscribers_gained, lost: d.subscribers_lost })),
      top: top.map((r) => ({ ...videoCard(r), rangeViews: r.range_views, rangeWatchSeconds: Number(r.range_watch) })),
      sources, devices,
    };
  });

  // --- Жалобы на пользователей ----------------------------------------------------------
  app.post('/reports', { preHandler: app.requireActive }, async (req) => {
    const { targetType, targetId, reason, details } = req.body || {};
    if (!['user', 'live'].includes(targetType)) throw badRequest('Недопустимый тип объекта');
    if (!targetId || !reason) throw badRequest('Укажите объект и причину');
    await query(`INSERT INTO reports(reporter_id, target_type, target_id, reason, details) VALUES ($1,$2,$3,$4,$5)`, [req.user.id, targetType, targetId, String(reason).slice(0, 100), String(details || '').slice(0, 2000)]);
    return { ok: true };
  });
}
