// Автоглавы по сменам кадра: находим моменты, где картинка заметно меняется (смена слайда,
// переход к другому экрану), и предлагаем их как главы — так работают «умные главы» Panopto и Mediasite.
import fsp from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { one, query } from '../db.js';
import { runFfmpeg } from '../lib/ffmpeg.js';
import { videoSource } from '../lib/ocr.js';
import { videoTranscript } from './ai.js';
import { notify } from '../lib/notify.js';

const MIN_GAP = 20;      // главы ближе 20 секунд друг к другу сливаем
const MAX_CHAPTERS = 30;

/** Секунды, где сцена меняется сильнее порога. */
export async function detectScenes(file, { threshold = 0.3, signal } = {}) {
  // Фильтр metadata=print пишет кадры-кандидаты в файл: stderr тут не годится,
  // потому что ffmpeg запускается с -loglevel error и showinfo в него не попадает
  const out = path.join(os.tmpdir(), `cv-scenes-${Date.now()}-${Math.random().toString(36).slice(2, 8)}.txt`);
  try {
    await runFfmpeg([
      '-i', file, '-vf', `select='gt(scene,${threshold})',metadata=print:file=${out}`, '-an', '-f', 'null', '-',
    ], { signal, label: 'scenes' });
    return await fsp.readFile(out, 'utf8');
  } catch {
    return await fsp.readFile(out, 'utf8').catch(() => '');
  } finally {
    await fsp.unlink(out).catch(() => {});
  }
}

/** Разобрать вывод metadata=print и получить секунды смен сцены. */
export function parseScenes(text) {
  const out = [];
  for (const m of String(text).matchAll(/pts_time:([0-9.]+)/g)) {
    const t = Number(m[1]);
    if (Number.isFinite(t)) out.push(t);
  }
  return out;
}

/** Смены сцены → главы: прореживание и подписи из расшифровки. */
export function scenesToChapters(scenes, { duration = 0, segments = [], minGap = MIN_GAP, max = MAX_CHAPTERS } = {}) {
  const points = [0, ...scenes.filter((t) => t > minGap / 2 && (!duration || t < duration - 5))];
  const kept = [];
  for (const t of points.sort((a, b) => a - b)) {
    if (!kept.length || t - kept[kept.length - 1] >= minGap) kept.push(t);
  }
  const trimmed = kept.slice(0, max);
  const used = new Set();
  return trimmed.map((start, i) => {
    // Название — начало ближайшей реплики этой главы: она должна звучать после смены кадра,
    // но до следующей главы, иначе одна и та же фраза попадёт в несколько глав
    const next = trimmed[i + 1] ?? (duration || start + 60);
    const seg = segments.find((sgm) => sgm.start >= start - 1 && sgm.start < next && sgm.text && sgm.text.trim().length > 4);
    const words = seg ? seg.text.trim().replace(/\s+/g, ' ').split(' ').slice(0, 6).join(' ') : '';
    let title = words ? words.replace(/[,.;:!?]+$/, '').slice(0, 60) : `Часть ${i + 1}`;
    if (used.has(title.toLowerCase())) title = `Часть ${i + 1}`;
    used.add(title.toLowerCase());
    return { start: Math.round(start), title };
  });
}

/** Задание воркера: расставить главы по сменам кадра. */
export async function runAutoChapters(job, ctx) {
  const { heartbeat, signal } = ctx;
  const videoId = job.video_id || job.payload.videoId;
  const video = await one('SELECT * FROM videos WHERE id = $1 AND deleted_at IS NULL', [videoId]);
  if (!video) throw Object.assign(new Error('Видео не найдено'), { noRetry: true });
  if (video.status !== 'ready') throw Object.assign(new Error('Видео ещё обрабатывается'), { noRetry: true });
  const src = await videoSource(video);
  await heartbeat(20, 'scenes');
  const raw = await detectScenes(src, { threshold: Number(job.payload.threshold) || 0.3, signal });
  const scenes = parseScenes(raw);
  const tr = await videoTranscript(video).catch(() => null);
  const chapters = scenesToChapters(scenes, { duration: Number(video.duration) || 0, segments: tr?.segments || [] });
  if (chapters.length < 2) {
    await notify(video.owner_id, {
      type: 'chapters_ready', title: 'Главы по слайдам не найдены',
      body: `${video.title}: картинка меняется слишком редко — расставьте главы вручную`,
      link: `/studio/videos/${video.id}/chapters`, data: { videoId: video.id },
    });
    return { chapters: 0, scenes: scenes.length };
  }
  await query('UPDATE videos SET chapters = $2::jsonb WHERE id = $1', [video.id, JSON.stringify(chapters)]);
  await notify(video.owner_id, {
    type: 'chapters_ready', title: 'Главы расставлены по слайдам',
    body: `${video.title}: ${chapters.length}`, link: `/studio/videos/${video.id}/chapters`, data: { videoId: video.id },
  });
  return { chapters: chapters.length, scenes: scenes.length };
}
