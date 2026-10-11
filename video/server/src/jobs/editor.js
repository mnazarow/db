// Редактор видео (как в YouTube Studio, Descript, Twitch): обрезка/вырезание фрагментов (новая версия файла),
// удаление пауз по silencedetect, клип как отдельное видео (в т. ч. вертикальный 9:16 для ленты коротких).
import path from 'node:path';
import fsp from 'node:fs/promises';
import { spawn } from 'node:child_process';
import { one, query, publish } from '../db.js';
import { storage, ensureDir, removeFile } from '../lib/storage.js';
import { probe, runFfmpeg } from '../lib/ffmpeg.js';
import { loadSettings } from '../lib/settings.js';
import { notify } from '../lib/notify.js';
import { finalizeFile } from '../routes/uploads.js';
import { videoSource } from '../lib/ocr.js';
import { shortId } from '../lib/crypto.js';
import { config } from '../config.js';
import { applyFreshDefault } from '../lib/lifecycle.js';

const fmt = (sec) => { const s = Math.max(0, Math.round(sec)); const m = Math.floor(s / 60), x = s % 60; return `${m}:${String(x).padStart(2, '0')}`; };

/** Нормализовать список сохраняемых отрезков: сортировка, отсечение, склейка пересечений. */
export function normalizeSegments(segments, duration) {
  const segs = (segments || []).map((x) => ({ start: Math.max(0, Number(x.start ?? x[0]) || 0), end: Math.min(duration || Infinity, Number(x.end ?? x[1]) || 0) }))
    .filter((x) => x.end - x.start >= 0.2).sort((a, b) => a.start - b.start);
  const out = [];
  for (const x of segs) { const last = out[out.length - 1]; if (last && x.start <= last.end + 0.01) last.end = Math.max(last.end, x.end); else out.push({ ...x }); }
  return out;
}

/** Отрезки, которые остаются после вырезания cuts из [0, duration]. */
export function invertCuts(cuts, duration) {
  const c = normalizeSegments(cuts, duration);
  const keep = []; let pos = 0;
  for (const x of c) { if (x.start - pos >= 0.2) keep.push({ start: pos, end: x.start }); pos = x.end; }
  if (duration - pos >= 0.2) keep.push({ start: pos, end: duration });
  return keep;
}

/** Точка в новой шкале: { at, cut } — cut означает, что исходное время попало в вырезанный кусок
 *  и было привязано к началу следующего сохранённого отрезка. null — точка исчезла совсем. */
function mapPoint(t, keep) {
  let acc = 0;
  for (const k of keep) {
    if (t <= k.start) return { at: acc, cut: t < k.start };
    if (t < k.end) return { at: acc + (t - k.start), cut: false };
    acc += k.end - k.start;
  }
  return null;
}

/** Новое время точки после монтажа (null — точка вырезана вместе с концом ролика). */
export function remapTime(t, keep) {
  const p = mapPoint(t, keep);
  return p ? p.at : null;
}

/** Пересчёт глав после монтажа: сдвиг, удаление вырезанных, склейка только реально совпавших. */
export function remapChapters(chapters, keep) {
  const src = (Array.isArray(chapters) ? chapters : []).map((c) => ({ ...c, start: Number(c.start) || 0 })).sort((a, b) => a.start - b.start);
  const out = [];
  for (const c of src) {
    const p = mapPoint(c.start, keep);
    if (!p) continue;
    const at = Math.round(p.at * 10) / 10;
    const last = out[out.length - 1];
    // Две главы сливаются, только если хотя бы одна из них попала в вырез и они оказались в одной точке:
    // главы, которых монтаж не коснулся, сохраняются даже если идут вплотную.
    if (last && Math.abs(last.start - at) < 0.05 && (p.cut || last._cut)) out[out.length - 1] = { ...c, start: last.start, _cut: p.cut };
    else out.push({ ...c, start: at, _cut: p.cut });
  }
  if (out.length && out[0].start > 0 && out[0].start < 1) out[0] = { ...out[0], start: 0 };
  return out.map(({ _cut, ...c }) => c);
}

/** Аргументы ffmpeg для монтажа сохраняемых отрезков (точная перекодировка). */
function editArgs(src, keep, meta, out, { vertical = false, crf = 18, preset = 'veryfast' } = {}) {
  const hasAudio = !!meta.hasAudio;
  const vf = vertical ? (meta.width > meta.height ? `crop=ih*9/16:ih,scale=-2:'min(1920,ih)'` : `scale=-2:'min(1920,ih)'`) : null;
  const enc = ['-c:v', 'libx264', '-preset', preset, '-crf', String(crf), '-pix_fmt', 'yuv420p', '-movflags', '+faststart'];
  const audioEnc = hasAudio ? ['-c:a', 'aac', '-b:a', '160k'] : ['-an'];
  if (keep.length === 1) {
    const k = keep[0];
    return ['-ss', String(k.start), '-i', src, '-t', String(k.end - k.start), ...(vf ? ['-vf', vf] : []), ...enc, ...audioEnc, out];
  }
  const parts = []; const labels = [];
  keep.forEach((k, i) => {
    parts.push(`[0:v]trim=start=${k.start}:end=${k.end},setpts=PTS-STARTPTS${vf ? ',' + vf : ''}[v${i}]`);
    labels.push(`[v${i}]`);
    if (hasAudio) { parts.push(`[0:a]atrim=start=${k.start}:end=${k.end},asetpts=PTS-STARTPTS[a${i}]`); labels.push(`[a${i}]`); }
  });
  parts.push(`${labels.join('')}concat=n=${keep.length}:v=1:a=${hasAudio ? 1 : 0}[v]${hasAudio ? '[a]' : ''}`);
  return ['-i', src, '-filter_complex', parts.join(';'), '-map', '[v]', ...(hasAudio ? ['-map', '[a]'] : []), ...enc, ...audioEnc, out];
}

async function pushHistory(videoId, entry) {
  await query(`UPDATE videos SET edit_history = (COALESCE(edit_history, '[]'::jsonb) || $2::jsonb) WHERE id = $1`, [videoId, JSON.stringify([{ at: new Date().toISOString(), ...entry }])]);
}

/** Задание: обрезка / вырезание фрагментов → новая версия того же видео. payload: {videoId, keep:[{start,end}] | cuts:[{start,end}], byUserId, op} */
export async function runVideoEdit(job, ctx) {
  const { signal, heartbeat, log } = ctx;
  const video = await one('SELECT * FROM videos WHERE id = $1 AND deleted_at IS NULL', [job.video_id || job.payload.videoId]);
  if (!video) throw Object.assign(new Error('Видео не найдено'), { noRetry: true });
  const src = await videoSource(video);
  const meta = await probe(src);
  const duration = meta.duration || Number(video.duration) || 0;
  const keep = job.payload.keep ? normalizeSegments(job.payload.keep, duration) : invertCuts(job.payload.cuts || [], duration);
  if (!keep.length) throw Object.assign(new Error('После монтажа не остаётся ни одного фрагмента'), { noRetry: true });
  const total = keep.reduce((n, k) => n + (k.end - k.start), 0);
  if (Math.abs(total - duration) < 0.2 && keep.length === 1 && keep[0].start < 0.05) throw Object.assign(new Error('Нечего изменять: выбран весь ролик'), { noRetry: true });
  await query(`UPDATE videos SET processing_stage = 'edit', processing_progress = 0 WHERE id = $1`, [video.id]);
  await publish({ type: 'video.progress', videoId: video.id, shortId: video.short_id, ownerId: video.owner_id, progress: 0, stage: 'edit', status: video.status });
  const tmp = storage.uploadTmp(`${video.id}-edit-${Date.now()}`) + '.mp4';
  await ensureDir(path.dirname(tmp));
  try {
    await runFfmpeg(editArgs(src, keep, meta, tmp), { duration: total, signal, label: 'edit', onProgress: (p) => { heartbeat(p, 'edit').catch(() => {}); publish({ type: 'video.progress', videoId: video.id, shortId: video.short_id, ownerId: video.owner_id, progress: p, stage: 'edit', status: 'processing' }).catch(() => {}); } });
    const filename = `${path.basename(video.original_filename || 'video', path.extname(video.original_filename || '')) || 'video'}-edit.mp4`;
    await pushHistory(video.id, { op: job.payload.op || 'edit', keep, removedSec: Math.round((duration - total) * 10) / 10, by: job.payload.byUserId || null });
    const v = await finalizeFile({ video, srcPath: tmp, filename, replace: true, priority: 1 });
    // Пересчёт глав под новый таймлайн (для любого числа сохранённых отрезков, а не только для обрезки начала)
    if (Array.isArray(video.chapters) && video.chapters.length) {
      const shifted = remapChapters(video.chapters, keep).filter((c) => c.start < total - 1);
      await query('UPDATE videos SET chapters = $2::jsonb WHERE id = $1', [video.id, JSON.stringify(shifted)]);
    }
    if (job.payload.byUserId) await notify(job.payload.byUserId, { type: 'video_ready', title: 'Монтаж выполнен, видео обрабатывается', body: `${video.title}: удалено ${fmt(duration - total)} (${keep.length} ${keep.length === 1 ? 'фрагмент' : 'фрагментов'})`, link: `/studio/videos/${video.id}`, data: { videoId: video.id } });
    return { keep, removedSec: Math.round((duration - total) * 10) / 10, version: v.version };
  } catch (e) {
    await removeFile(tmp).catch(() => {});
    await query(`UPDATE videos SET processing_stage = NULL WHERE id = $1`, [video.id]);
    await publish({ type: 'video.progress', videoId: video.id, shortId: video.short_id, ownerId: video.owner_id, progress: 0, stage: null, status: video.status });
    if (job.payload.byUserId && job.attempts >= job.max_attempts) await notify(job.payload.byUserId, { type: 'video_failed', title: 'Монтаж не выполнен', body: `${video.title}: ${e.message}`.slice(0, 300), link: `/studio/videos/${video.id}`, data: { videoId: video.id } });
    throw e;
  }
}

/** Поиск пауз: ffmpeg silencedetect. Возвращает [{start,end}]. */
export async function detectSilence(src, { noiseDb = -35, minSec = 1.5, signal } = {}) {
  return new Promise((resolve, reject) => {
    const args = ['-hide_banner', '-nostdin', '-i', src, '-vn', '-af', `silencedetect=noise=${noiseDb}dB:d=${minSec}`, '-f', 'null', '-'];
    const child = spawn(config.ffmpegPath, args, { stdio: ['ignore', 'ignore', 'pipe'] });
    let err = ''; const out = []; let cur = null;
    child.stderr.on('data', (d) => {
      err += d.toString(); if (err.length > 400000) err = err.slice(-200000);
      for (const m of d.toString().matchAll(/silence_(start|end): ([0-9.]+)/g)) {
        if (m[1] === 'start') cur = Number(m[2]);
        else if (cur != null) { out.push({ start: cur, end: Number(m[2]) }); cur = null; }
      }
    });
    const onAbort = () => child.kill('SIGKILL');
    signal?.addEventListener('abort', onAbort, { once: true });
    child.on('error', (e) => reject(new Error('silencedetect: ' + e.message)));
    child.on('close', (code) => {
      signal?.removeEventListener('abort', onAbort);
      if (code !== 0) return reject(new Error('silencedetect: ffmpeg завершился с кодом ' + code));
      const durM = err.match(/Duration: (\d+):(\d+):([\d.]+)/);
      const duration = durM ? Number(durM[1]) * 3600 + Number(durM[2]) * 60 + Number(durM[3]) : 0;
      if (cur != null && duration) out.push({ start: cur, end: duration });
      resolve(out);
    });
  });
}

/** Задание: найти паузы (dryRun) или удалить их. payload: {videoId, noiseDb, minSec, keepSec, apply, byUserId} */
export async function runRemoveSilence(job, ctx) {
  const { signal } = ctx;
  const s = await loadSettings(true);
  const video = await one('SELECT * FROM videos WHERE id = $1 AND deleted_at IS NULL', [job.video_id || job.payload.videoId]);
  if (!video) throw Object.assign(new Error('Видео не найдено'), { noRetry: true });
  const src = await videoSource(video);
  const meta = await probe(src);
  if (!meta.hasAudio) throw Object.assign(new Error('В видео нет звуковой дорожки'), { noRetry: true });
  const noiseDb = Number(job.payload.noiseDb ?? s['editor.silence_db']) || -35;
  const minSec = Math.max(0.3, Number(job.payload.minSec ?? s['editor.silence_min_sec']) || 1.5);
  const keepSec = Math.max(0, Number(job.payload.keepSec ?? s['editor.silence_keep_sec']) || 0.3);
  const silences = await detectSilence(src, { noiseDb, minSec, signal });
  // Оставляем keepSec тишины по краям, чтобы речь не звучала обрубленной
  const cuts = silences.map((x) => ({ start: x.start + keepSec, end: x.end - keepSec })).filter((x) => x.end - x.start >= 0.2);
  const removed = Math.round(cuts.reduce((n, c) => n + (c.end - c.start), 0) * 10) / 10;
  const result = { silences: silences.length, cuts, removedSec: removed, duration: meta.duration, noiseDb, minSec, keepSec };
  if (!job.payload.apply) return result;
  if (!cuts.length) return { ...result, applied: false, reason: 'пауз не найдено' };
  const edit = await runVideoEdit({ ...job, payload: { videoId: video.id, cuts, byUserId: job.payload.byUserId, op: 'remove_silence' } }, ctx);
  return { ...result, applied: true, ...edit };
}

/** Задание: клип как отдельное видео. payload: {videoId, start, end, title, vertical, visibility, byUserId} */
export async function runClipCreate(job, ctx) {
  const { signal, heartbeat } = ctx;
  const s = await loadSettings(true);
  const source = await one('SELECT * FROM videos WHERE id = $1 AND deleted_at IS NULL', [job.video_id || job.payload.videoId]);
  if (!source) throw Object.assign(new Error('Исходное видео не найдено'), { noRetry: true });
  const src = await videoSource(source);
  const meta = await probe(src);
  const start = Math.max(0, Number(job.payload.start) || 0);
  const end = Math.min(meta.duration || Infinity, Number(job.payload.end) || 0);
  if (end - start < 1) throw Object.assign(new Error('Фрагмент короче 1 секунды'), { noRetry: true });
  if (end - start > 3600) throw Object.assign(new Error('Клип длиннее часа — используйте обрезку'), { noRetry: true });
  const vertical = !!job.payload.vertical;
  const ownerId = job.payload.byUserId || source.owner_id;
  const title = String(job.payload.title || '').trim().slice(0, 150) || `${source.title} — фрагмент ${fmt(start)}–${fmt(end)}`;
  const visibility = ['public', 'internal', 'unlisted', 'private'].includes(job.payload.visibility) ? job.payload.visibility : (source.visibility === 'public' ? 'public' : 'private');
  const videoId = crypto.randomUUID();
  const dir = storage.videoDir(videoId);
  await ensureDir(dir);
  const tmp = path.join(dir, 'clip-source.mp4');
  await runFfmpeg(editArgs(src, [{ start, end }], meta, tmp, { vertical, crf: 20 }), { duration: end - start, signal, label: 'clip', onProgress: (p) => heartbeat(p, 'clip').catch(() => {}) });
  const st = await fsp.stat(tmp);
  const premod = s['upload.premoderation'] && job.payload.byRole === 'user';
  const video = await one(
    `INSERT INTO videos(id, short_id, owner_id, title, description, category_id, tags, visibility, status, moderation_status, original_filename, original_size, comments_mode, clip_of, clip_range, language)
     VALUES ($1,$2,$3,$4,$5,$6,$7,$8,'uploading',$9,$10,$11,$12,$13,$14::jsonb,$15) RETURNING *`,
    [videoId, shortId(), ownerId, title, job.payload.description != null ? String(job.payload.description).slice(0, 10000) : `Фрагмент видео «${source.title}» (${fmt(start)}–${fmt(end)}). Источник: ${config.baseUrl}/watch/${source.short_id}?t=${Math.floor(start)}`,
      source.category_id, source.tags || [], visibility, premod ? 'pending' : 'approved', `clip-${source.short_id}.mp4`, st.size, s['comments.default_mode'], source.id, JSON.stringify({ start, end, vertical }), source.language || null],
  );
  await applyFreshDefault(videoId, s);
  const v = await finalizeFile({ video, srcPath: tmp, filename: `clip-${source.short_id}.mp4`, priority: 1 });
  await notify(ownerId, { type: 'video_ready', title: vertical ? 'Короткое видео создано, идёт обработка' : 'Клип создан, идёт обработка', body: title, link: `/studio/videos/${videoId}`, data: { videoId } });
  return { videoId, shortId: video.short_id, start, end, vertical, status: v.status };
}
