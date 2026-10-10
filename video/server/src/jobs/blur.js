// Размытие лиц и областей в кадре (как инструмент размытия YouTube Studio):
// рамки задаются в долях кадра и отрезках времени, видео пересобирается — ссылка и статистика сохраняются.
import path from 'node:path';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { one, query, publish } from '../db.js';
import { storage, ensureDir, removeFile } from '../lib/storage.js';
import { probe, runFfmpeg } from '../lib/ffmpeg.js';
import { notify } from '../lib/notify.js';
import { finalizeFile } from '../routes/uploads.js';
import { videoSource } from '../lib/ocr.js';
import { config } from '../config.js';

const SCRIPT = path.resolve(fileURLToPath(new URL('../../../scripts/facedetect.py', import.meta.url)));
const clamp01 = (v) => Math.min(1, Math.max(0, Number(v) || 0));
const even = (n) => Math.max(8, Math.round(n / 2) * 2);

/** Проверка и нормализация рамок: доли кадра, отрезок времени, сила размытия. */
export function normalizeRegions(regions, duration = 0) {
  const out = [];
  for (const r of Array.isArray(regions) ? regions : []) {
    const x = clamp01(r.x), y = clamp01(r.y);
    const w = Math.min(1 - x, clamp01(r.w)), h = Math.min(1 - y, clamp01(r.h));
    if (w < 0.01 || h < 0.01) continue;
    const start = Math.max(0, Number(r.start) || 0);
    const endRaw = r.end == null || r.end === '' ? null : Number(r.end);
    const end = endRaw == null || !Number.isFinite(endRaw) || endRaw <= start ? null : (duration ? Math.min(duration, endRaw) : endRaw);
    out.push({ x, y, w, h, start: end == null && !start ? 0 : start, end, strength: Math.min(60, Math.max(4, Math.round(Number(r.strength) || 22))) });
    if (out.length >= 20) break;
  }
  return out;
}

/** filter_complex для ffmpeg: каждая рамка вырезается, размывается и накладывается обратно на своём отрезке. */
export function blurFilter(regions, width, height) {
  const parts = [];
  const splits = regions.map((_, i) => `[c${i}]`).join('');
  parts.push(`[0:v]split=${regions.length + 1}[base]${splits}`);
  regions.forEach((r, i) => {
    const w = even(r.w * width), h = even(r.h * height);
    const x = Math.min(width - w, even(r.x * width)), y = Math.min(height - h, even(r.y * height));
    const radius = Math.max(2, Math.min(r.strength, Math.floor(Math.min(w, h) / 2) - 1));
    parts.push(`[c${i}]crop=${w}:${h}:${Math.max(0, x)}:${Math.max(0, y)},boxblur=luma_radius=${radius}:luma_power=2:chroma_radius=${Math.max(2, Math.round(radius / 2))}:chroma_power=2[b${i}]`);
    const prev = i === 0 ? '[base]' : `[t${i - 1}]`;
    const label = i === regions.length - 1 ? '[v]' : `[t${i}]`;
    const enable = r.end != null ? `:enable='between(t,${r.start},${r.end})'` : (r.start > 0 ? `:enable='gte(t,${r.start})'` : '');
    parts.push(`${prev}[b${i}]overlay=${Math.max(0, x)}:${Math.max(0, y)}${enable}${label}`);
  });
  return parts.join(';');
}

/** Задание: применить размытие. payload: {videoId, regions:[{x,y,w,h,start,end,strength}], byUserId} */
export async function runVideoBlur(job, ctx) {
  const { signal, heartbeat } = ctx;
  const video = await one('SELECT * FROM videos WHERE id = $1 AND deleted_at IS NULL', [job.video_id || job.payload.videoId]);
  if (!video) throw Object.assign(new Error('Видео не найдено'), { noRetry: true });
  const src = await videoSource(video);
  const meta = await probe(src);
  const duration = meta.duration || Number(video.duration) || 0;
  const regions = normalizeRegions(job.payload.regions, duration);
  if (!regions.length) throw Object.assign(new Error('Не задано ни одной области размытия'), { noRetry: true });
  const width = meta.width || video.width || 1280;
  const height = meta.height || video.height || 720;
  await query(`UPDATE videos SET processing_stage = 'blur', processing_progress = 0 WHERE id = $1`, [video.id]);
  await publish({ type: 'video.progress', videoId: video.id, shortId: video.short_id, ownerId: video.owner_id, progress: 0, stage: 'blur', status: video.status });
  const tmp = storage.uploadTmp(`${video.id}-blur-${Date.now()}`) + '.mp4';
  await ensureDir(path.dirname(tmp));
  const args = ['-i', src, '-filter_complex', blurFilter(regions, width, height), '-map', '[v]',
    ...(meta.hasAudio ? ['-map', '0:a', '-c:a', 'copy'] : ['-an']),
    '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '18', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', tmp];
  try {
    await runFfmpeg(args, { duration, signal, label: 'blur', onProgress: (p) => { heartbeat(p, 'blur').catch(() => {}); publish({ type: 'video.progress', videoId: video.id, shortId: video.short_id, ownerId: video.owner_id, progress: p, stage: 'blur', status: 'processing' }).catch(() => {}); } });
    await query(`UPDATE videos SET blur_regions = $2::jsonb, edit_history = (COALESCE(edit_history, '[]'::jsonb) || $3::jsonb) WHERE id = $1`,
      [video.id, JSON.stringify(regions), JSON.stringify([{ at: new Date().toISOString(), op: 'blur', regions: regions.length, by: job.payload.byUserId || null }])]);
    const base = path.basename(video.original_filename || 'video', path.extname(video.original_filename || '')) || 'video';
    const v = await finalizeFile({ video, srcPath: tmp, filename: `${base}-blur.mp4`, replace: true, priority: 1 });
    if (job.payload.byUserId) await notify(job.payload.byUserId, { type: 'video_ready', title: 'Размытие применено, видео обрабатывается', body: `${video.title}: областей — ${regions.length}`, link: `/studio/videos/${video.id}`, data: { videoId: video.id } });
    return { regions: regions.length, version: v.version };
  } catch (e) {
    await removeFile(tmp).catch(() => {});
    await query(`UPDATE videos SET processing_stage = NULL WHERE id = $1`, [video.id]);
    await publish({ type: 'video.progress', videoId: video.id, shortId: video.short_id, ownerId: video.owner_id, progress: 0, stage: null, status: video.status });
    throw e;
  }
}

/** Запуск python-детектора лиц. Возвращает {ok, regions|error}. */
export function detectFaces(src, { step = 2, max = 40, signal } = {}) {
  return new Promise((resolve) => {
    const child = spawn(config.pythonPath, [SCRIPT, src, String(step), String(max)], { stdio: ['ignore', 'pipe', 'pipe'] });
    let out = '', err = '';
    child.stdout.on('data', (d) => { out += d.toString(); if (out.length > 400000) out = out.slice(0, 400000); });
    child.stderr.on('data', (d) => { err += d.toString().slice(0, 4000); });
    const onAbort = () => child.kill('SIGKILL');
    signal?.addEventListener('abort', onAbort, { once: true });
    child.on('error', () => resolve({ ok: false, error: 'Не удалось запустить python3 — автопоиск лиц недоступен' }));
    child.on('close', () => {
      signal?.removeEventListener('abort', onAbort);
      try { resolve(JSON.parse(out.trim().split('\n').pop() || '{}')); }
      catch { resolve({ ok: false, error: (err.trim().split('\n').pop() || 'детектор лиц не ответил').slice(0, 300) }); }
    });
  });
}

/** Задание: найти лица и предложить рамки. payload: {videoId, step} */
export async function runFaceDetect(job, ctx) {
  const video = await one('SELECT * FROM videos WHERE id = $1 AND deleted_at IS NULL', [job.video_id || job.payload.videoId]);
  if (!video) throw Object.assign(new Error('Видео не найдено'), { noRetry: true });
  const src = await videoSource(video);
  const r = await detectFaces(src, { step: Math.max(0.5, Number(job.payload.step) || 2), signal: ctx.signal });
  if (!r.ok) throw Object.assign(new Error(r.error || 'Не удалось найти лица'), { noRetry: true });
  return { regions: (r.regions || []).map(({ hits, ...x }) => ({ ...x, strength: 22 })), found: (r.regions || []).length, frames: r.frames || 0 };
}
