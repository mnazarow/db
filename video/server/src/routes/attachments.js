// Вложения к видео (презентации, документы, ссылки на материалы) и скачивание транскрипта.
import path from 'node:path';
import fs from 'node:fs';
import fsp from 'node:fs/promises';
import { pipeline } from 'node:stream/promises';
import { one, many, query } from '../db.js';
import { badRequest, notFound, forbidden, unauthorized, extOf, safeFilename, vttToSegments } from '../lib/util.js';
import { storage, ensureDir, removeFile } from '../lib/storage.js';
import { requireViewable, requireEditable } from './videos.js';
import { audit } from '../lib/audit.js';
import { config } from '../config.js';
import { browserPath } from '../lib/urls.js';

export const ALLOWED = new Set(['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'txt', 'md', 'rtf', 'csv', 'zip', '7z', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'dwg', 'dxf', 'json', 'xml']);
export const MIME = { pdf: 'application/pdf', doc: 'application/msword', docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', xls: 'application/vnd.ms-excel', xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ppt: 'application/vnd.ms-powerpoint', pptx: 'application/vnd.openxmlformats-officedocument.presentationml.presentation', txt: 'text/plain; charset=utf-8', md: 'text/markdown; charset=utf-8', csv: 'text/csv; charset=utf-8', zip: 'application/zip', png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', svg: 'image/svg+xml', json: 'application/json' };

export function attachmentOut(a) {
  return { id: a.id, title: a.title, filename: a.filename, size: Number(a.size), mime: a.mime, ext: extOf(a.filename), downloads: a.downloads, createdAt: a.created_at, url: browserPath(`/api/videos/${a.video_id}/attachments/${a.id}/download`) };
}

export default async function attachmentRoutes(app) {
  app.get('/videos/:id/attachments', async (req) => {
    const v = await requireViewable(req, req.params.id);
    const rows = await many('SELECT * FROM video_attachments WHERE video_id = $1 ORDER BY created_at', [v.id]);
    return { attachments: rows.map(attachmentOut) };
  });

  app.post('/videos/:id/attachments', { preHandler: app.requireActive, bodyLimit: 512 * 1024 * 1024 }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    const count = await one('SELECT count(*)::int AS n FROM video_attachments WHERE video_id = $1', [v.id]);
    if (count.n >= 30) throw badRequest('Слишком много вложений (максимум 30)');
    const maxMb = Number(req.settings['upload.attachment_max_mb']) || 200;
    const part = await req.file({ limits: { fileSize: maxMb * 1024 * 1024 } });
    if (!part) throw badRequest('Ожидается файл');
    const ext = extOf(part.filename);
    if (!ALLOWED.has(ext)) throw badRequest(`Формат .${ext || '?'} не поддерживается для вложений`);
    const id = crypto.randomUUID();
    const dir = path.join(storage.videoDir(v.id), 'files');
    await ensureDir(dir);
    const file = path.join(dir, `${id}.${ext}`);
    let size = 0;
    try {
      await pipeline(part.file, new (await import('node:stream')).Transform({ transform(c, e, cb) { size += c.length; cb(null, c); } }), fs.createWriteStream(file));
      if (part.file.truncated) throw badRequest(`Файл больше допустимого размера (${maxMb} МБ)`);
    } catch (e) { await removeFile(file); throw e; }
    const fields = {}; for (const [k, val] of Object.entries(part.fields || {})) { const item = Array.isArray(val) ? val[0] : val; if (item && item.value !== undefined) fields[k] = item.value; }
    const title = String(fields.title || '').trim().slice(0, 150) || path.basename(part.filename, path.extname(part.filename)).slice(0, 150);
    const a = await one('INSERT INTO video_attachments(id, video_id, title, filename, path, size, mime, created_by) VALUES ($1,$2,$3,$4,$5,$6,$7,$8) RETURNING *',
      [id, v.id, title, safeFilename(part.filename), storage.rel(file), size, MIME[ext] || part.mimetype || null, req.user.id]);
    await query('UPDATE videos SET storage_bytes = storage_bytes + $2 WHERE id = $1', [v.id, size]);
    await audit(req, 'video.attachment_add', { targetType: 'video', targetId: v.id, details: { filename: a.filename, size } });
    return { attachment: attachmentOut(a) };
  });

  app.patch('/videos/:id/attachments/:aid', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    const title = String(req.body?.title || '').trim().slice(0, 150);
    if (!title) throw badRequest('Пустое название');
    const a = await one('UPDATE video_attachments SET title = $3 WHERE id = $1 AND video_id = $2 RETURNING *', [req.params.aid, v.id, title]);
    if (!a) throw notFound('Вложение не найдено');
    return { attachment: attachmentOut(a) };
  });

  app.delete('/videos/:id/attachments/:aid', { preHandler: app.requireActive }, async (req) => {
    const v = await requireEditable(req, req.params.id);
    const a = await one('DELETE FROM video_attachments WHERE id = $1 AND video_id = $2 RETURNING *', [req.params.aid, v.id]);
    if (!a) throw notFound('Вложение не найдено');
    await removeFile(storage.abs(a.path));
    await query('UPDATE videos SET storage_bytes = greatest(storage_bytes - $2, 0) WHERE id = $1', [v.id, Number(a.size)]);
    await audit(req, 'video.attachment_delete', { targetType: 'video', targetId: v.id, details: { filename: a.filename } });
    return { ok: true };
  });

  // Скачивание с человеческим именем файла и учётом (доступ — как к самому видео, в т. ч. по защищённой ссылке)
  app.get('/videos/:id/attachments/:aid/download', async (req, reply) => {
    const v = await requireViewable(req, req.params.id);
    const a = await one('SELECT * FROM video_attachments WHERE id = $1 AND video_id = $2', [req.params.aid, v.id]);
    if (!a) throw notFound('Вложение не найдено');
    await query('UPDATE video_attachments SET downloads = downloads + 1 WHERE id = $1', [a.id]);
    const abs = storage.abs(a.path);
    const ext = extOf(a.filename);
    const name = `${String(a.title).replace(/[\\/:*?"<>|\r\n]+/g, '_').slice(0, 120) || 'file'}.${ext}`;
    // SVG не показываем в окне браузера: внутри может быть скрипт, а origin — наш
    const inline = req.query.inline !== undefined && /^(pdf|png|jpe?g|gif|txt|md)$/.test(ext);
    reply.header('Content-Disposition', `${inline ? 'inline' : 'attachment'}; filename*=UTF-8''${encodeURIComponent(name)}`);
    reply.header('Content-Type', ext === 'svg' ? 'application/octet-stream' : (a.mime || 'application/octet-stream'));
    reply.header('X-Content-Type-Options', 'nosniff');
    const accel = storage.accel(req, a.path);
    if (accel) { reply.header('X-Accel-Redirect', accel); return ''; }
    let st;
    try { st = await fsp.stat(abs); } catch { throw notFound('Файл не найден'); }
    reply.header('Content-Length', st.size);
    return reply.send(fs.createReadStream(abs));
  });

  // --- Транскрипт: скачать субтитры как txt / srt / vtt ---------------------------------------
  app.get('/videos/:id/transcript', async (req, reply) => {
    const v = await requireViewable(req, req.params.id);
    const format = ['txt', 'srt', 'vtt', 'json'].includes(req.query.format) ? req.query.format : 'txt';
    const sub = req.query.track
      ? await one(`SELECT * FROM subtitles WHERE video_id = $1 AND id = cv_uuid($2) AND status = 'ready'`, [v.id, String(req.query.track)])
      : await one(`SELECT * FROM subtitles WHERE video_id = $1 AND status = 'ready' AND path IS NOT NULL ORDER BY is_default DESC, (kind = 'manual') DESC, created_at LIMIT 1`, [v.id]);
    if (!sub) throw notFound('У видео нет субтитров');
    const vtt = await fsp.readFile(storage.abs(sub.path), 'utf8');
    const segs = vttToSegments(vtt);
    const ts = (sec, sep) => { const s = Math.max(0, sec); const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60; return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${x.toFixed(3).padStart(6, '0').replace('.', sep)}`; };
    const fmtShort = (sec) => { const s = Math.round(sec); const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60; return (h ? `${h}:${String(m).padStart(2, '0')}` : `${m}`) + ':' + String(x).padStart(2, '0'); };
    const safeTitle = String(v.title).replace(/[\\/:*?"<>|\r\n]+/g, '_').slice(0, 100) || 'transcript';
    let body, type;
    if (format === 'vtt') { body = vtt; type = 'text/vtt; charset=utf-8'; }
    else if (format === 'srt') { body = segs.map((c, i) => `${i + 1}\n${ts(c.start, ',')} --> ${ts(c.end, ',')}\n${c.text}\n`).join('\n'); type = 'text/plain; charset=utf-8'; }
    else if (format === 'json') { reply.header('Content-Type', 'application/json; charset=utf-8'); return { title: v.title, language: sub.language, segments: segs }; }
    else {
      // txt: абзацы с таймкодом в начале примерно каждой минуты
      let out = `${v.title}\n${config.baseUrl}/watch/${v.short_id}\n\n`; let lastMark = -61;
      for (const c of segs) { if (c.start - lastMark >= 60) { out += `\n[${fmtShort(c.start)}] `; lastMark = c.start; } out += c.text + ' '; }
      body = out.trim() + '\n'; type = 'text/plain; charset=utf-8';
    }
    reply.header('Content-Type', type);
    if (req.query.download !== undefined || format !== 'txt') reply.header('Content-Disposition', `attachment; filename*=UTF-8''${encodeURIComponent(`${safeTitle}.${format}`)}`);
    return body;
  });
}
