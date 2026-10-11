// Плейлисты и «Смотреть позже».
import { one, query, many, tx } from '../db.js';
import { canViewPlaylist, isStaff, listVisibilitySql, canViewVideo } from '../lib/access.js';
import { playlistOut, videoCard } from '../lib/serialize.js';
import { badRequest, forbidden, notFound, paging } from '../lib/util.js';
import { VIDEO_SELECT, VIDEO_FROM, requireViewable } from './videos.js';

const PL_SELECT = `p.*, u.display_name AS owner_name, u.handle AS owner_handle, u.avatar_path AS owner_avatar,
  (SELECT v.thumbnail_path FROM playlist_items pi JOIN videos v ON v.id = pi.video_id WHERE pi.playlist_id = p.id AND v.deleted_at IS NULL ORDER BY pi.position LIMIT 1) AS first_thumbnail`;
const PL_FROM = `playlists p JOIN users u ON u.id = p.owner_id`;

export async function ensureWatchLater(userId) {
  const existing = await one(`SELECT * FROM playlists WHERE owner_id = $1 AND kind = 'watch_later'`, [userId]);
  if (existing) return existing;
  return one(`INSERT INTO playlists(owner_id, title, visibility, kind) VALUES ($1, 'Смотреть позже', 'private', 'watch_later') ON CONFLICT DO NOTHING RETURNING *`, [userId])
    || one(`SELECT * FROM playlists WHERE owner_id = $1 AND kind = 'watch_later'`, [userId]);
}

async function loadPlaylist(id) {
  return one(`SELECT ${PL_SELECT} FROM ${PL_FROM} WHERE p.id = cv_uuid($1)`, [String(id)]);
}

async function recount(playlistId) {
  await query(`UPDATE playlists SET item_count = (SELECT count(*) FROM playlist_items pi JOIN videos v ON v.id = pi.video_id WHERE pi.playlist_id = $1 AND v.deleted_at IS NULL), updated_at = now() WHERE id = $1`, [playlistId]);
}

export default async function playlistRoutes(app) {
  // Мои плейлисты
  app.get('/', { preHandler: app.requireActive }, async (req) => {
    await ensureWatchLater(req.user.id);
    const rows = await many(`SELECT ${PL_SELECT} FROM ${PL_FROM} WHERE p.owner_id = $1 ORDER BY p.kind = 'watch_later' DESC, p.updated_at DESC`, [req.user.id]);
    return { playlists: rows.map(playlistOut) };
  });

  // Мои плейлисты с отметкой, содержат ли видео (для меню «Сохранить»)
  app.get('/for-video/:videoId', { preHandler: app.requireActive }, async (req) => {
    const v = await requireViewable(req, req.params.videoId);
    await ensureWatchLater(req.user.id);
    const rows = await many(
      `SELECT ${PL_SELECT}, EXISTS(SELECT 1 FROM playlist_items pi WHERE pi.playlist_id = p.id AND pi.video_id = $2) AS contains_video
       FROM ${PL_FROM} WHERE p.owner_id = $1 ORDER BY p.kind = 'watch_later' DESC, p.updated_at DESC`,
      [req.user.id, v.id],
    );
    return { playlists: rows.map(playlistOut) };
  });

  app.post('/', { preHandler: app.requireActive }, async (req) => {
    const title = String(req.body?.title || '').trim().slice(0, 150);
    if (!title) throw badRequest('Укажите название плейлиста');
    const visibility = ['public', 'internal', 'unlisted', 'private'].includes(req.body?.visibility) ? req.body.visibility : 'private';
    const p = await one(`INSERT INTO playlists(owner_id, title, description, visibility) VALUES ($1,$2,$3,$4) RETURNING *`, [req.user.id, title, String(req.body?.description || '').slice(0, 5000), visibility]);
    if (req.body?.videoId) {
      const v = await requireViewable(req, req.body.videoId);
      await query('INSERT INTO playlist_items(playlist_id, video_id, position, added_by) VALUES ($1,$2,0,$3) ON CONFLICT DO NOTHING', [p.id, v.id, req.user.id]);
      await recount(p.id);
    }
    return { playlist: playlistOut(await loadPlaylist(p.id)) };
  });

  app.get('/:id', async (req) => {
    const p = await loadPlaylist(req.params.id);
    if (!p) throw notFound('Плейлист не найден');
    if (!canViewPlaylist(p, req.user)) throw forbidden('У вас нет доступа к этому плейлисту');
    const { limit, offset, page } = paging(req.query, 50, 200);
    const rows = await many(
      `SELECT ${VIDEO_SELECT}, pi.position, pi.added_at FROM playlist_items pi JOIN ${VIDEO_FROM} ON v.id = pi.video_id
       WHERE pi.playlist_id = $1 AND v.deleted_at IS NULL ORDER BY pi.position, pi.added_at LIMIT $2 OFFSET $3`,
      [p.id, limit, offset],
    );
    const videos = [];
    for (const r of rows) {
      // Видео, закрытое для зрителя (в том числе для владельца плейлиста — чужое видео могли закрыть
      // после добавления), остаётся в списке только позицией: без названия, автора и описания.
      // Раньше они утекали через публичный плейлист. Идентификатор нужен, чтобы убрать позицию.
      const ok = (r.owner_id === req.user?.id || r.status === 'ready') && (await canViewVideo(r, req.user));
      if (!ok) videos.push({ id: r.id, position: r.position, addedAt: r.added_at, unavailable: true, title: 'Видео недоступно' });
      else videos.push({ ...videoCard(r), position: r.position, addedAt: r.added_at, unavailable: r.status !== 'ready' });
    }
    return { playlist: playlistOut(p), videos, page, limit, total: p.item_count };
  });

  app.patch('/:id', { preHandler: app.requireActive }, async (req) => {
    const p = await loadPlaylist(req.params.id);
    if (!p) throw notFound();
    if (p.owner_id !== req.user.id && !isStaff(req.user)) throw forbidden();
    if (p.kind === 'watch_later') throw badRequest('Системный плейлист нельзя изменить');
    const b = req.body || {};
    const title = b.title !== undefined ? String(b.title).trim().slice(0, 150) : p.title;
    if (!title) throw badRequest('Укажите название');
    const visibility = ['public', 'internal', 'unlisted', 'private'].includes(b.visibility) ? b.visibility : p.visibility;
    await query('UPDATE playlists SET title = $2, description = $3, visibility = $4, updated_at = now() WHERE id = $1', [p.id, title, b.description !== undefined ? String(b.description).slice(0, 5000) : p.description, visibility]);
    return { playlist: playlistOut(await loadPlaylist(p.id)) };
  });

  app.delete('/:id', { preHandler: app.requireActive }, async (req) => {
    const p = await loadPlaylist(req.params.id);
    if (!p) throw notFound();
    if (p.owner_id !== req.user.id && !isStaff(req.user)) throw forbidden();
    if (p.kind === 'watch_later') throw badRequest('Системный плейлист нельзя удалить');
    await query('DELETE FROM playlists WHERE id = $1', [p.id]);
    return { ok: true };
  });

  app.post('/:id/items', { preHandler: app.requireActive }, async (req) => {
    const p = await loadPlaylist(req.params.id);
    if (!p) throw notFound();
    if (p.owner_id !== req.user.id && !isStaff(req.user)) throw forbidden();
    const v = await requireViewable(req, req.body?.videoId);
    const max = await one('SELECT coalesce(max(position), -1) + 1 AS pos FROM playlist_items WHERE playlist_id = $1', [p.id]);
    const inserted = await one('INSERT INTO playlist_items(playlist_id, video_id, position, added_by) VALUES ($1,$2,$3,$4) ON CONFLICT DO NOTHING RETURNING *', [p.id, v.id, max.pos, req.user.id]);
    await recount(p.id);
    return { ok: true, added: !!inserted };
  });

  app.delete('/:id/items/:videoId', { preHandler: app.requireActive }, async (req) => {
    const p = await loadPlaylist(req.params.id);
    if (!p) throw notFound();
    if (p.owner_id !== req.user.id && !isStaff(req.user)) throw forbidden();
    await query('DELETE FROM playlist_items WHERE playlist_id = $1 AND video_id = cv_uuid($2)', [p.id, req.params.videoId]);
    await recount(p.id);
    return { ok: true };
  });

  app.put('/:id/order', { preHandler: app.requireActive }, async (req) => {
    const p = await loadPlaylist(req.params.id);
    if (!p) throw notFound();
    if (p.owner_id !== req.user.id && !isStaff(req.user)) throw forbidden();
    const ids = Array.isArray(req.body?.videoIds) ? req.body.videoIds.map(String) : [];
    await tx(async (c) => {
      for (let i = 0; i < ids.length; i++) await c.query('UPDATE playlist_items SET position = $3 WHERE playlist_id = $1 AND video_id = cv_uuid($2)', [p.id, ids[i], i]);
    });
    await recount(p.id);
    return { ok: true };
  });

  // «Смотреть позже»: переключить
  app.post('/watch-later/toggle', { preHandler: app.requireActive }, async (req) => {
    const v = await requireViewable(req, req.body?.videoId);
    const wl = await ensureWatchLater(req.user.id);
    const existing = await one('SELECT 1 FROM playlist_items WHERE playlist_id = $1 AND video_id = $2', [wl.id, v.id]);
    if (existing) await query('DELETE FROM playlist_items WHERE playlist_id = $1 AND video_id = $2', [wl.id, v.id]);
    else {
      const max = await one('SELECT coalesce(max(position), -1) + 1 AS pos FROM playlist_items WHERE playlist_id = $1', [wl.id]);
      await query('INSERT INTO playlist_items(playlist_id, video_id, position, added_by) VALUES ($1,$2,$3,$4)', [wl.id, v.id, max.pos, req.user.id]);
    }
    await recount(wl.id);
    return { ok: true, inWatchLater: !existing, playlistId: wl.id };
  });
}
