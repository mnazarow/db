// Сборка ролика по проекту графического редактора: куски, переходы, титры, картинки, музыка.
import path from 'node:path';
import fsp from 'node:fs/promises';
import fs from 'node:fs';
import { one, many, query, publish } from '../db.js';
import { storage, ensureDir, removeFile } from '../lib/storage.js';
import { probe, runFfmpeg } from '../lib/ffmpeg.js';
import { loadSettings } from '../lib/settings.js';
import { notify } from '../lib/notify.js';
import { finalizeFile } from '../routes/uploads.js';
import { videoSource } from '../lib/ocr.js';
import { shortId } from '../lib/crypto.js';
import { vttToSegments } from '../lib/util.js';
import { config } from '../config.js';
import { normalizeProject, buildRender, findFont, remapCues } from '../lib/timeline.js';
import { applyFreshDefault } from '../lib/lifecycle.js';
import { mediaUrl } from '../lib/urls.js';

/** Реплики субтитров → SRT со сдвигом под смонтированный таймлайн. */
export function cuesToSrt(cues) {
  const ts = (sec) => {
    const s = Math.max(0, sec);
    const h = String(Math.floor(s / 3600)).padStart(2, '0');
    const m = String(Math.floor((s % 3600) / 60)).padStart(2, '0');
    const ss = String(Math.floor(s % 60)).padStart(2, '0');
    const ms = String(Math.round((s - Math.floor(s)) * 1000)).padStart(3, '0');
    return `${h}:${m}:${ss},${ms}`;
  };
  return cues.map((c, i) => `${i + 1}\n${ts(c.start)} --> ${ts(c.end)}\n${c.text}\n`).join('\n');
}

export async function loadProject(videoId, { duration = 0 } = {}) {
  const row = await one('SELECT * FROM video_projects WHERE video_id = $1', [videoId]);
  const assets = await many('SELECT * FROM project_assets WHERE video_id = $1 ORDER BY created_at', [videoId]);
  const list = assets.map((a) => ({ id: a.id, kind: a.kind, name: a.name, file: storage.abs(a.path), url: mediaUrl(a.path), duration: a.duration ? Number(a.duration) : null, width: a.width, height: a.height, bytes: Number(a.bytes) || 0 }));
  return { row, assets: list, project: normalizeProject(row?.data, { duration, assets: list }) };
}

/** Задание: собрать ролик. payload: {videoId, output: 'replace'|'new', title, byUserId} */
export async function runVideoRender(job, ctx) {
  const { signal, heartbeat } = ctx;
  const s = await loadSettings(true);
  const video = await one('SELECT * FROM videos WHERE id = $1 AND deleted_at IS NULL', [job.video_id || job.payload.videoId]);
  if (!video) throw Object.assign(new Error('Видео не найдено'), { noRetry: true });
  const src = await videoSource(video);
  const meta = await probe(src);
  const duration = meta.duration || Number(video.duration) || 0;
  const { project, assets } = await loadProject(video.id, { duration });
  if (!project.clips.length) throw Object.assign(new Error('В проекте нет ни одного фрагмента'), { noRetry: true });
  const replace = job.payload.output !== 'new';
  const tmpDir = path.join(config.uploadTmpDir, `render-${video.id}-${Date.now().toString(36)}`);
  await ensureDir(tmpDir);
  const out = path.join(tmpDir, 'render.mp4');

  // Субтитры: сдвигаем реплики под монтаж и вжигаем
  let subtitlesFile = null;
  if (project.burnSubtitles) {
    const sub = project.subtitleId
      ? await one(`SELECT * FROM subtitles WHERE id = $1 AND video_id = $2 AND status = 'ready'`, [project.subtitleId, video.id])
      : await one(`SELECT * FROM subtitles WHERE video_id = $1 AND status = 'ready' AND path IS NOT NULL ORDER BY is_default DESC, created_at LIMIT 1`, [video.id]);
    if (sub?.path) {
      const cues = remapCues(vttToSegments(await fsp.readFile(storage.abs(sub.path), 'utf8')), project.clips);
      if (cues.length) {
        subtitlesFile = path.join(tmpDir, 'subs.srt');
        await fsp.writeFile(subtitlesFile, cuesToSrt(cues), 'utf8');
      }
    }
  }

  const built = buildRender(project, {
    src, out, meta, assets, subtitlesFile, fontFile: findFont(),
    crf: Math.max(14, Math.min(32, Number(s['editor.render_crf']) || 20)), tmpDir,
  });
  for (const f of built.files) await fsp.writeFile(f.path, f.content, 'utf8');

  await query(`UPDATE videos SET processing_stage = 'render', processing_progress = 0 WHERE id = $1`, [video.id]);
  await publish({ type: 'video.progress', videoId: video.id, shortId: video.short_id, ownerId: video.owner_id, progress: 0, stage: 'render', status: video.status });
  try {
    await runFfmpeg(built.args.slice(1), {
      duration: built.duration, signal, label: 'render',
      onProgress: (p) => { heartbeat(p, 'render').catch(() => {}); publish({ type: 'video.progress', videoId: video.id, shortId: video.short_id, ownerId: video.owner_id, progress: p, stage: 'render', status: 'processing' }).catch(() => {}); },
    });
    const st = await fsp.stat(out);
    if (st.size < 1000) throw new Error('Сборка дала пустой файл');
    await query(`UPDATE video_projects SET rendered_at = now() WHERE video_id = $1`, [video.id]);

    if (replace) {
      const base = path.basename(video.original_filename || 'video', path.extname(video.original_filename || '')) || 'video';
      await query(`UPDATE videos SET edit_history = (COALESCE(edit_history, '[]'::jsonb) || $2::jsonb) WHERE id = $1`,
        [video.id, JSON.stringify([{ at: new Date().toISOString(), op: 'render', clips: project.clips.length, texts: project.texts.length, images: project.images.length, music: project.audio.length, aspect: project.aspect, by: job.payload.byUserId || null }])]);
      const v = await finalizeFile({ video, srcPath: out, filename: `${base}-montage.mp4`, replace: true, priority: 1 });
      if (job.payload.byUserId) await notify(job.payload.byUserId, { type: 'video_ready', title: 'Монтаж собран, видео обрабатывается', body: `${video.title}: ${project.clips.length} фрагм., титров — ${project.texts.length}`, link: `/studio/videos/${video.id}`, data: { videoId: video.id } });
      await fsp.rm(tmpDir, { recursive: true, force: true }).catch(() => {});
      return { output: 'replace', duration: built.duration, version: v.version };
    }
    // Новое видео
    const ownerId = job.payload.byUserId || video.owner_id;
    const title = String(job.payload.title || '').trim().slice(0, 150) || `${video.title} — монтаж`;
    const videoId = crypto.randomUUID();
    const dir = storage.videoDir(videoId);
    await ensureDir(dir);
    const dest = path.join(dir, 'render-source.mp4');
    await fsp.rename(out, dest).catch(async () => { await fsp.copyFile(out, dest); });
    const size = (await fsp.stat(dest)).size;
    const premod = s['upload.premoderation'] && job.payload.byRole === 'user';
    const created = await one(
      `INSERT INTO videos(id, short_id, owner_id, title, description, category_id, tags, visibility, status, moderation_status,
                          original_filename, original_size, comments_mode, clip_of, language)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,'uploading',$9,$10,$11,$12,$13,$14) RETURNING *`,
      [videoId, shortId(), ownerId, title, `Смонтировано из «${video.title}» (${config.baseUrl}/watch/${video.short_id})`,
        video.category_id, video.tags || [], job.payload.visibility && ['public', 'internal', 'unlisted', 'private'].includes(job.payload.visibility) ? job.payload.visibility : 'private',
        premod ? 'pending' : 'approved', `${video.short_id}-montage.mp4`, size, s['comments.default_mode'], video.id, video.language || 'ru'],
    );
    await applyFreshDefault(videoId, s);
    const v = await finalizeFile({ video: created, srcPath: dest, filename: `${video.short_id}-montage.mp4`, priority: 1 });
    await notify(ownerId, { type: 'video_ready', title: 'Смонтированное видео создано', body: title, link: `/studio/videos/${videoId}`, data: { videoId } });
    await query(`UPDATE videos SET processing_stage = NULL WHERE id = $1`, [video.id]);
    await fsp.rm(tmpDir, { recursive: true, force: true }).catch(() => {});
    return { output: 'new', videoId, shortId: created.short_id, duration: built.duration, status: v.status };
  } catch (e) {
    await removeFile(out).catch(() => {});
    await fsp.rm(tmpDir, { recursive: true, force: true }).catch(() => {});
    await query(`UPDATE videos SET processing_stage = NULL WHERE id = $1`, [video.id]);
    await publish({ type: 'video.progress', videoId: video.id, shortId: video.short_id, ownerId: video.owner_id, progress: 0, stage: null, status: video.status });
    if (job.payload.byUserId && job.attempts >= job.max_attempts) {
      await notify(job.payload.byUserId, { type: 'video_failed', title: 'Сборка монтажа не выполнена', body: `${video.title}: ${e.message}`.slice(0, 300), link: `/studio/videos/${video.id}`, data: { videoId: video.id } });
    }
    throw e;
  }
}
