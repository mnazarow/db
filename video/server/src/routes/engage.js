// Вовлечение и доступность: реакции по таймкоду, тепловая карта «часто пересматривают»,
// дополнительные звуковые дорожки (дубляж, тифлокомментарий).
import path from 'node:path';
import fs from 'node:fs';
import { pipeline } from 'node:stream/promises';
import fsp from 'node:fs/promises';
import { one, many, query } from '../db.js';
import { badRequest, notFound, forbidden, extOf, safeFilename, vttToSegments, likeEscape } from '../lib/util.js';
import { storage, ensureDir, removeFile } from '../lib/storage.js';
import { requireViewable, requireEditable } from './videos.js';
import { audit } from '../lib/audit.js';
import { canEditVideo } from '../lib/access.js';
import { mediaUrl } from '../lib/urls.js';

export const REACTIONS = ['like', 'love', 'wow', 'question'];
const REACTION_LABEL = { like: 'Полезно', love: 'Нравится', wow: 'Впечатляет', question: 'Непонятно' };
const AUDIO_EXT = new Set(['m4a', 'mp3', 'aac', 'opus', 'ogg', 'wav']);
const AUDIO_MIME = { m4a: 'audio/mp4', mp3: 'audio/mpeg', aac: 'audio/aac', opus: 'audio/ogg', ogg: 'audio/ogg', wav: 'audio/wav' };

function trackOut(t) {
  return {
    id: t.id, language: t.language, label: t.label, kind: t.kind, status: t.status,
    size: Number(t.size_bytes) || 0, error: t.error || null, createdAt: t.created_at,
    url: mediaUrl(t.path),
  };
}

export default async function engageRoutes(app) {
  // --- Реакции по таймкоду -------------------------------------------------------------------
  app.get('/videos/:id/reactions', async (req) => {
    if (!req.settings['engage.reactions']) return { totals: {}, marks: [], mine: [], kinds: [], canReact: false, disabled: true };
    const v = await requireViewable(req, req.params.id);
    const duration = Number(v.duration) || 0;
    const rows = await many('SELECT kind, t, user_id FROM video_reactions WHERE video_id = $1 ORDER BY t LIMIT 5000', [v.id]);
    const totals = {};
    for (const k of REACTIONS) totals[k] = 0;
    for (const r of rows) totals[r.kind] = (totals[r.kind] || 0) + 1;
    // Метки для полосы перемотки: группируем по 5 секунд, чтобы не рисовать тысячи точек
    const step = Math.max(2, Math.round(duration / 120) || 2);
    const buckets = new Map();
    for (const r of rows) {
      const key = Math.floor(Number(r.t) / step) * step;
      const b = buckets.get(key) || { t: key, total: 0, kinds: {} };
      b.total++; b.kinds[r.kind] = (b.kinds[r.kind] || 0) + 1;
      buckets.set(key, b);
    }
    const marks = [...buckets.values()].sort((a, b) => b.total - a.total).slice(0, 40).sort((a, b) => a.t - b.t);
    const mine = req.user ? rows.filter((r) => r.user_id === req.user.id).map((r) => ({ t: Number(r.t), kind: r.kind })) : [];
    return { totals, marks, mine, kinds: REACTIONS.map((k) => ({ id: k, label: REACTION_LABEL[k] })), canReact: !!req.user };
  });

  app.post('/videos/:id/reactions', { preHandler: app.requireActive }, async (req) => {
    if (!req.settings['engage.reactions']) throw forbidden('Реакции отключены администратором');
    const v = await requireViewable(req, req.params.id);
    const kind = REACTIONS.includes(req.body?.kind) ? req.body.kind : null;
    if (!kind) throw badRequest('Неизвестная реакция');
    const duration = Number(v.duration) || 0;
    const t = Math.max(0, Math.min(duration || 1e6, Number(req.body?.t) || 0));
    const recent = await one(`SELECT id FROM video_reactions WHERE video_id = $1 AND user_id = $2 AND kind = $3 AND abs(t - $4) < 3`, [v.id, req.user.id, kind, t]);
    if (recent) { await query('DELETE FROM video_reactions WHERE id = $1', [recent.id]); return { ok: true, removed: true }; }
    const dayCount = await one(`SELECT count(*)::int AS n FROM video_reactions WHERE video_id = $1 AND user_id = $2 AND created_at > now() - interval '1 day'`, [v.id, req.user.id]);
    if (dayCount.n >= 100) throw badRequest('Слишком много реакций за сегодня');
    await query('INSERT INTO video_reactions(video_id, user_id, t, kind) VALUES ($1,$2,$3,$4)', [v.id, req.user.id, t, kind]);
    return { ok: true, removed: false };
  });

  // --- Тепловая карта «часто пересматривают» ----------------------------------------------------
  app.get('/videos/:id/heatmap', async (req) => {
    if (!req.settings['engage.heatmap']) return { available: false, points: [] };
    const v = await requireViewable(req, req.params.id);
    const rows = await many('SELECT bucket, count FROM video_retention WHERE video_id = $1 ORDER BY bucket', [v.id]);
    const total = Number(v.view_count) || 0;
    // Обезличивание: кривую показываем, только когда просмотров достаточно, чтобы по ней нельзя было
    // узнать поведение конкретного сотрудника
    const minViews = Math.max(3, Number(req.settings['engage.heatmap_min_views']) || 20);
    const canEdit = canEditVideo(v, req.user);
    if (!rows.length || (total < minViews && !canEdit)) return { available: false, minViews, points: [] };
    const max = rows.reduce((m, r) => Math.max(m, Number(r.count)), 0) || 1;
    const points = Array.from({ length: 100 }, (_, i) => {
      const r = rows.find((x) => Number(x.bucket) === i);
      return Math.round(((Number(r?.count) || 0) / max) * 100) / 100;
    });
    return { available: true, points, max, duration: Number(v.duration) || 0, views: total, minViews };
  });

  // --- Дополнительные звуковые дорожки ----------------------------------------------------------
  app.get('/videos/:id/audio-tracks', async (req) => {
    const v = await requireViewable(req, req.params.id);
    const rows = await many(`SELECT * FROM video_audio_tracks WHERE video_id = $1 AND status = 'ready' ORDER BY kind, created_at`, [v.id]);
    return { tracks: rows.map(trackOut) };
  });

  app.post('/videos/:id/audio-tracks', { preHandler: app.requireActive, bodyLimit: 512 * 1024 * 1024 }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    const count = await one('SELECT count(*)::int AS n FROM video_audio_tracks WHERE video_id = $1', [v.id]);
    if (count.n >= 10) throw badRequest('Слишком много дорожек (максимум 10)');
    const maxMb = Number(req.settings['upload.attachment_max_mb']) || 200;
    const part = await req.file({ limits: { fileSize: maxMb * 1024 * 1024 } });
    if (!part) throw badRequest('Ожидается файл со звуковой дорожкой');
    const ext = extOf(part.filename);
    if (!AUDIO_EXT.has(ext)) throw badRequest(`Формат .${ext || '?'} не поддерживается (нужен m4a, mp3, aac, opus, ogg или wav)`);
    const id = crypto.randomUUID();
    const dir = path.join(storage.videoDir(v.id), 'audio');
    await ensureDir(dir);
    const file = path.join(dir, `${id}.${ext}`);
    let size = 0;
    try {
      await pipeline(part.file, new (await import('node:stream')).Transform({ transform(c, e, cb) { size += c.length; cb(null, c); } }), fs.createWriteStream(file));
      if (part.file.truncated) throw badRequest(`Файл больше допустимого размера (${maxMb} МБ)`);
    } catch (e) { await removeFile(file); throw e; }
    const fields = {};
    for (const [k, val] of Object.entries(part.fields || {})) { const item = Array.isArray(val) ? val[0] : val; if (item && item.value !== undefined) fields[k] = item.value; }
    const kind = ['dub', 'description'].includes(fields.kind) ? fields.kind : 'dub';
    const language = String(fields.language || 'ru').toLowerCase().slice(0, 8);
    const label = String(fields.label || '').trim().slice(0, 100) || (kind === 'description' ? 'Тифлокомментарий' : `Дубляж (${language})`);
    const t = await one(
      `INSERT INTO video_audio_tracks(id, video_id, language, label, kind, path, size_bytes, status, created_by)
       VALUES ($1,$2,$3,$4,$5,$6,$7,'ready',$8) RETURNING *`,
      [id, v.id, language, label, kind, storage.rel(file), size, req.user.id],
    );
    await query('UPDATE videos SET storage_bytes = storage_bytes + $2 WHERE id = $1', [v.id, size]);
    await audit(req, 'video.audio_track_add', { targetType: 'video', targetId: v.id, details: { label, kind, size } });
    return { track: trackOut(t) };
  });

  app.delete('/videos/:id/audio-tracks/:tid', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    const t = await one('DELETE FROM video_audio_tracks WHERE id = $1 AND video_id = $2 RETURNING *', [req.params.tid, v.id]);
    if (!t) throw notFound('Дорожка не найдена');
    if (t.path) await removeFile(storage.abs(t.path)).catch(() => {});
    await query('UPDATE videos SET storage_bytes = greatest(0, storage_bytes - $2) WHERE id = $1', [v.id, Number(t.size_bytes) || 0]);
    return { ok: true };
  });

  // --- Переход к найденному моменту (из результатов поиска) -------------------------------------
  app.get('/videos/:id/moment', async (req) => {
    const v = await requireViewable(req, req.params.id);
    const q = String(req.query.q || '').trim().slice(0, 200);
    if (q.length < 2) throw badRequest('Слишком короткий запрос');
    const words = q.toLowerCase().replace(/[«»"'(),.!?]/g, ' ').split(/\s+/).filter((w) => w.length >= 3);
    const hits = [];
    // Текст с экрана: время известно точно
    const ocr = await many(`SELECT t, text FROM video_screen_text WHERE video_id = $1 AND text ILIKE $2 ORDER BY t LIMIT 5`, [v.id, `%${likeEscape(q)}%`]);
    for (const r of ocr) hits.push({ at: Number(r.t), text: String(r.text).slice(0, 200), source: 'screen' });
    // Расшифровка речи: ищем по репликам субтитров
    if (!hits.length) {
      const sub = await one(`SELECT path FROM subtitles WHERE video_id = $1 AND status = 'ready' AND path IS NOT NULL ORDER BY (kind = 'manual') DESC, created_at LIMIT 1`, [v.id]);
      if (sub?.path) {
        try {
          const vtt = await fsp.readFile(storage.abs(sub.path), 'utf8');
          const cues = vttToSegments(vtt);
          const lower = q.toLowerCase();
          let found = cues.filter((c) => c.text.toLowerCase().includes(lower));
          if (!found.length && words.length) found = cues.filter((c) => { const t = c.text.toLowerCase(); return words.every((w) => t.includes(w)); });
          if (!found.length && words.length) found = cues.filter((c) => { const t = c.text.toLowerCase(); return words.some((w) => t.includes(w)); });
          for (const c of found.slice(0, 5)) hits.push({ at: Math.max(0, c.start), text: c.text.slice(0, 200), source: 'transcript' });
        } catch { /* файл субтитров мог быть удалён */ }
      }
    }
    return { hits, at: hits[0]?.at ?? null };
  });

  // --- Сводка реакций для автора (аналитика) ----------------------------------------------------
  app.get('/videos/:id/reactions/report', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    const rows = await many(
      `SELECT kind, count(*)::int AS n, round(avg(t))::int AS avg_t FROM video_reactions WHERE video_id = $1 GROUP BY kind`, [v.id]);
    const hot = await many(
      `SELECT floor(t / 10) * 10 AS t, count(*)::int AS n,
              count(*) FILTER (WHERE kind = 'question')::int AS questions
       FROM video_reactions WHERE video_id = $1 GROUP BY 1 ORDER BY n DESC, t LIMIT 10`, [v.id]);
    return {
      totals: Object.fromEntries(rows.map((r) => [r.kind, r.n])),
      moments: hot.map((h) => ({ t: Number(h.t), count: h.n, questions: h.questions })),
      kinds: REACTIONS.map((k) => ({ id: k, label: REACTION_LABEL[k] })),
    };
  });
}
