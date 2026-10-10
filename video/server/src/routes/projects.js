// Графический видеоредактор: проект монтажа, загруженные картинки и музыка, предпросмотр кадра, сборка.
import path from 'node:path';
import fs from 'node:fs';
import fsp from 'node:fs/promises';
import { pipeline } from 'node:stream/promises';
import { one, many, query, withLock, tx } from '../db.js';
import { config } from '../config.js';
import { badRequest, forbidden, notFound, extOf, safeFilename } from '../lib/util.js';
import { storage, ensureDir, removeFile } from '../lib/storage.js';
import { enqueue } from '../lib/jobs.js';
import { audit } from '../lib/audit.js';
import { probe, ffmpegToBuffer, withPreviewSlot } from '../lib/ffmpeg.js';
import { requireEditable } from './videos.js';
import { videoSource } from '../lib/ocr.js';
import { normalizeProject, buildPreview, findFont, projectDuration, ASPECTS } from '../lib/timeline.js';
import { loadProject } from '../jobs/render.js';
import { assertQuota, lockQuota } from '../lib/quota.js';
import { mediaUrl } from '../lib/urls.js';

async function ensureEditor(req, id) {
  if (!req.settings['editor.enabled'] || !req.settings['editor.timeline']) throw forbidden('Графический редактор отключён администратором');
  const v = await requireEditable(req, id);
  if (!['ready', 'failed'].includes(v.status)) throw badRequest('Дождитесь окончания обработки видео');
  return v;
}

export default async function projectRoutes(app) {
  // --- Проект -------------------------------------------------------------------------------
  app.get('/videos/:id/project', { preHandler: app.requireActive }, async (req) => {
    const v = await ensureEditor(req, req.params.id);
    const duration = Number(v.duration) || 0;
    const { row, project, assets } = await loadProject(v.id, { duration });
    const subs = await many(`SELECT id, language, label, kind FROM subtitles WHERE video_id = $1 AND status = 'ready' AND path IS NOT NULL ORDER BY is_default DESC, created_at`, [v.id]);
    const jobs = await many(`SELECT * FROM jobs WHERE video_id = $1 AND type = 'video_render' ORDER BY id DESC LIMIT 5`, [v.id]);
    return {
      enabled: true, duration, width: v.width, height: v.height, fps: v.fps ? Number(v.fps) : null,
      project, outputDuration: projectDuration(project), aspects: ASPECTS,
      assets: assets.map((a) => ({ id: a.id, kind: a.kind, name: a.name, url: a.url, duration: a.duration, width: a.width, height: a.height, bytes: a.bytes })),
      subtitles: subs.map((x) => ({ id: x.id, label: x.label, language: x.language })),
      savedAt: row?.updated_at || null, renderedAt: row?.rendered_at || null,
      jobs: jobs.map((j) => ({ id: Number(j.id), status: j.status, progress: j.progress || 0, error: j.error, result: j.result, createdAt: j.created_at })),
      storyboardUrl: mediaUrl(v.storyboard_path),
      storyboardMeta: v.storyboard_meta || null,
      maxAssetMb: req.settings['editor.max_assets_mb'],
    };
  });

  app.put('/videos/:id/project', { preHandler: app.requireActive, bodyLimit: 2 * 1024 * 1024 }, async (req) => {
    const v = await ensureEditor(req, req.params.id);
    const { assets } = await loadProject(v.id, { duration: Number(v.duration) || 0 });
    const project = normalizeProject(req.body?.project, { duration: Number(v.duration) || 0, assets });
    await query(
      `INSERT INTO video_projects(video_id, data, updated_by, updated_at) VALUES ($1,$2::jsonb,$3, now())
       ON CONFLICT (video_id) DO UPDATE SET data = $2::jsonb, updated_by = $3, updated_at = now()`,
      [v.id, JSON.stringify(project), req.user.id]);
    return { ok: true, project, outputDuration: projectDuration(project) };
  });

  // --- Картинки и музыка проекта ---------------------------------------------------------------
  app.post('/videos/:id/project/assets', { preHandler: app.requireActive }, async (req) => {
    const v = await ensureEditor(req, req.params.id);
    const maxMb = Math.max(1, Number(req.settings['editor.max_assets_mb']) || 200);
    const part = await req.file({ limits: { fileSize: maxMb * 1024 * 1024 } });
    if (!part) throw badRequest('Ожидается файл');
    const ext = extOf(part.filename);
    const isImage = ['png', 'jpg', 'jpeg', 'webp'].includes(ext);
    const isAudio = ['mp3', 'm4a', 'aac', 'wav', 'ogg', 'opus', 'flac'].includes(ext);
    if (!isImage && !isAudio) throw badRequest('Поддерживаются картинки PNG, JPG, WebP и звук MP3, M4A, WAV, OGG');
    const count = await one('SELECT count(*)::int AS n FROM project_assets WHERE video_id = $1', [v.id]);
    if ((count?.n || 0) >= 40) { part.file.resume(); throw badRequest('В проекте уже 40 файлов — удалите лишние'); }
    const dir = path.join(storage.videoDir(v.id), 'project');
    await ensureDir(dir);
    const name = safeFilename(part.filename || (isImage ? 'image' : 'audio'));
    const file = path.join(dir, `${Date.now().toString(36)}-${name}`);
    await pipeline(part.file, fs.createWriteStream(file));
    // Файл больше предела обрезался молча и сохранялся битым
    if (part.file.truncated) { await removeFile(file); throw badRequest(`Файл больше ${maxMb} МБ`); }
    const st = await fsp.stat(file);
    let duration = null, width = null, height = null;
    try {
      const m = await probe(file);
      duration = m.duration || null;
      width = m.width || null; height = m.height || null;
    } catch { /* картинки без потока данных — не беда */ }
    // Файлы проектов тоже занимают место на канале: проверка квоты и запись — под одной блокировкой
    let row;
    try {
      row = await tx(async (c) => {
        await lockQuota(c, req.user.id);
        await assertQuota(c, req, st.size);
        return c.one(
          `INSERT INTO project_assets(video_id, kind, name, path, bytes, duration, width, height, created_by)
           VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9) RETURNING *`,
          [v.id, isImage ? 'image' : 'audio', name, storage.rel(file), st.size, duration, width, height, req.user.id]);
      });
    } catch (e) { await removeFile(file); throw e; }
    return { asset: { id: row.id, kind: row.kind, name: row.name, url: mediaUrl(row.path), duration: row.duration ? Number(row.duration) : null, width: row.width, height: row.height, bytes: Number(row.bytes) } };
  });

  app.delete('/videos/:id/project/assets/:assetId', { preHandler: app.requireActive }, async (req) => {
    const v = await ensureEditor(req, req.params.id);
    const a = await one('SELECT * FROM project_assets WHERE id = $1 AND video_id = $2', [req.params.assetId, v.id]);
    if (!a) throw notFound('Файл не найден');
    await removeFile(storage.abs(a.path)).catch(() => {});
    await query('DELETE FROM project_assets WHERE id = $1', [a.id]);
    return { ok: true };
  });

  // --- Предпросмотр кадра ---------------------------------------------------------------------
  app.get('/videos/:id/project/preview', { preHandler: app.requireActive }, async (req, reply) => {
    const v = await ensureEditor(req, req.params.id);
    const duration = Number(v.duration) || 0;
    const { project, assets } = await loadProject(v.id, { duration });
    const at = Math.max(0, Math.min(projectDuration(project), Number(req.query.t) || 0));
    const src = await videoSource(v);
    const meta = { width: v.width, height: v.height, fps: v.fps ? Number(v.fps) : 25, hasAudio: true };
    const tmpDir = config.uploadTmpDir;
    await ensureDir(tmpDir);
    const built = buildPreview(project, at, { src, meta, assets, fontFile: findFont(), out: 'pipe:1', tmpDir });
    for (const f of built.files) await fsp.writeFile(f.path, f.content, 'utf8');
    const buf = await withPreviewSlot(() => ffmpegToBuffer(built.args, { label: 'кадр', timeoutMs: 30000 }))
      .catch((e) => { if (e.statusCode === 429) throw e; throw badRequest('Не удалось собрать кадр: ' + e.message); })
      .finally(async () => { for (const f of built.files) await removeFile(f.path).catch(() => {}); });
    reply.header('Content-Type', 'image/jpeg').header('Cache-Control', 'no-store');
    return reply.send(buf);
  });

  // --- Сборка --------------------------------------------------------------------------------
  app.post('/videos/:id/project/render', { preHandler: app.requireActive }, async (req) => {
    const v = await ensureEditor(req, req.params.id);
    const { project } = await loadProject(v.id, { duration: Number(v.duration) || 0 });
    if (!project.clips.length) throw badRequest('В проекте нет ни одного фрагмента');
    const output = req.body?.output === 'new' ? 'new' : 'replace';
    const res = await withLock(`corpvideo:video-edit:${v.id}`, async () => {
      const busy = await one(`SELECT id FROM jobs WHERE video_id = $1 AND status IN ('queued','running') AND type IN ('video_render','video_edit','video_blur') ORDER BY id LIMIT 1`, [v.id]);
      if (busy) return { busy: Number(busy.id) };
      return {
        job: await enqueue('video_render', {
          videoId: v.id, output, title: String(req.body?.title || '').slice(0, 150),
          visibility: req.body?.visibility, byUserId: req.user.id, byRole: req.user.role,
        }, { videoId: v.id, priority: 1, maxAttempts: 1, dedupe: false }),
      };
    });
    if (!res.ok) throw badRequest('Над этим видео уже выполняется операция — повторите через несколько секунд');
    if (res.value.busy) throw badRequest('Над этим видео уже выполняется сборка или монтаж (задание №' + res.value.busy + ')');
    await audit(req, 'video.edit', { targetType: 'video', targetId: v.id, details: { op: 'render', output, clips: project.clips.length } });
    return { ok: true, jobId: Number(res.value.job.id), duration: projectDuration(project) };
  });
}
