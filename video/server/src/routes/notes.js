// Личные заметки зрителя с таймкодами (видны только автору заметки).
import { one, many, query } from '../db.js';
import { badRequest, notFound } from '../lib/util.js';
import { requireViewable } from './videos.js';
import { mediaUrl } from '../lib/urls.js';

function noteOut(n) {
  return { id: n.id, videoId: n.video_id, position: Number(n.position), body: n.body, createdAt: n.created_at, updatedAt: n.updated_at };
}

export default async function noteRoutes(app) {
  app.get('/videos/:id/notes', { preHandler: app.requireActive }, async (req) => {
    const v = await requireViewable(req, req.params.id);
    const rows = await many('SELECT * FROM user_notes WHERE user_id = $1 AND video_id = $2 ORDER BY position, created_at', [req.user.id, v.id]);
    return { notes: rows.map(noteOut) };
  });

  app.post('/videos/:id/notes', { preHandler: app.requireActive }, async (req) => {
    const v = await requireViewable(req, req.params.id);
    const body = String(req.body?.body || '').trim().slice(0, 5000);
    if (!body) throw badRequest('Пустая заметка');
    const position = Math.max(0, Number(req.body?.position) || 0);
    const count = await one('SELECT count(*)::int AS n FROM user_notes WHERE user_id = $1 AND video_id = $2', [req.user.id, v.id]);
    if (count.n >= 500) throw badRequest('Слишком много заметок к одному видео');
    const n = await one('INSERT INTO user_notes(user_id, video_id, position, body) VALUES ($1,$2,$3,$4) RETURNING *', [req.user.id, v.id, position, body]);
    return { note: noteOut(n) };
  });

  app.patch('/videos/:id/notes/:noteId', { preHandler: app.requireActive }, async (req) => {
    const n0 = await one('SELECT * FROM user_notes WHERE id = $1 AND user_id = $2', [req.params.noteId, req.user.id]);
    if (!n0) throw notFound('Заметка не найдена');
    const body = req.body?.body !== undefined ? String(req.body.body).trim().slice(0, 5000) : n0.body;
    if (!body) throw badRequest('Пустая заметка');
    const position = req.body?.position !== undefined ? Math.max(0, Number(req.body.position) || 0) : Number(n0.position);
    const n = await one('UPDATE user_notes SET body = $2, position = $3, updated_at = now() WHERE id = $1 RETURNING *', [n0.id, body, position]);
    return { note: noteOut(n) };
  });

  app.delete('/videos/:id/notes/:noteId', { preHandler: app.requireActive }, async (req) => {
    await query('DELETE FROM user_notes WHERE id = $1 AND user_id = $2', [req.params.noteId, req.user.id]);
    return { ok: true };
  });

  // Все заметки пользователя (страница «Мои заметки»)
  app.get('/me/notes', { preHandler: app.requireActive }, async (req) => {
    const rows = await many(
      `SELECT n.*, v.title, v.short_id, v.thumbnail_path FROM user_notes n JOIN videos v ON v.id = n.video_id
       WHERE n.user_id = $1 AND v.deleted_at IS NULL ORDER BY n.updated_at DESC LIMIT 500`, [req.user.id]);
    const byVideo = new Map();
    for (const r of rows) {
      const g = byVideo.get(r.video_id) || { videoId: r.video_id, title: r.title, shortId: r.short_id, thumbnailUrl: mediaUrl(r.thumbnail_path), notes: [] };
      g.notes.push(noteOut(r));
      byVideo.set(r.video_id, g);
    }
    for (const g of byVideo.values()) g.notes.sort((a, b) => a.position - b.position);
    return { videos: [...byVideo.values()] };
  });

  // Экспорт заметок к видео текстом (Markdown)
  app.get('/videos/:id/notes/export', { preHandler: app.requireActive }, async (req, reply) => {
    const v = await requireViewable(req, req.params.id);
    const rows = await many('SELECT * FROM user_notes WHERE user_id = $1 AND video_id = $2 ORDER BY position, created_at', [req.user.id, v.id]);
    const fmt = (s) => { s = Math.round(s); const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60; return (h ? `${h}:${String(m).padStart(2, '0')}` : `${m}`) + ':' + String(x).padStart(2, '0'); };
    const md = `# ${v.title}\n\n` + rows.map((n) => `- **${fmt(Number(n.position))}** — ${n.body.replace(/\n/g, '\n  ')}`).join('\n') + '\n';
    reply.header('Content-Type', 'text/markdown; charset=utf-8');
    reply.header('Content-Disposition', `attachment; filename*=UTF-8''${encodeURIComponent('notes-' + v.short_id + '.md')}`);
    return md;
  });
}
