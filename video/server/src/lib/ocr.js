// Распознавание текста на экране (слайды, демонстрации программ) через tesseract — «умный поиск» как у Panopto/Mediasite.
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';
import fsp from 'node:fs/promises';
import { one, many, query } from '../db.js';
import { storage, ensureDir, removeDir, exists } from './storage.js';
import { runFfmpeg } from './ffmpeg.js';
import { loadSettings } from './settings.js';
import { config } from '../config.js';

const execFileP = promisify(execFile);
const TESSERACT = process.env.TESSERACT_PATH || 'tesseract';

export async function ocrAvailable() {
  try {
    const { stdout, stderr } = await execFileP(TESSERACT, ['--version']);
    const version = (stdout + stderr).split('\n')[0].replace(/^tesseract\s+/i, '').trim();
    let languages = [];
    try { const r = await execFileP(TESSERACT, ['--list-langs']); languages = (r.stdout + r.stderr).split('\n').map((l) => l.trim()).filter((l) => /^[a-z_]{3,}$/.test(l) && l !== 'osd'); } catch { /* ignore */ }
    return { available: true, version, languages };
  } catch { return { available: false, version: null, languages: [] }; }
}

/** Языки для tesseract с учётом установленных пакетов. */
function pickLanguages(requested, installed) {
  const want = String(requested || 'rus+eng').split('+').map((l) => l.trim()).filter(Boolean);
  const have = want.filter((l) => installed.includes(l));
  return (have.length ? have : (installed.includes('eng') ? ['eng'] : installed.slice(0, 1))).join('+');
}

/** Нормализация текста кадра: убрать мусор распознавания, схлопнуть пробелы. */
export function cleanOcrText(raw) {
  return String(raw || '')
    .split('\n')
    .map((l) => l.replace(/[^\p{L}\p{N}\p{P}\p{S}\s]/gu, ' ').replace(/\s+/g, ' ').trim())
    .filter((l) => l.length >= 3 && /[\p{L}\p{N}]{2,}/u.test(l) && (l.match(/[\p{L}\p{N}]/gu) || []).length / l.length > 0.5)
    .join('\n')
    .trim();
}

function wordSet(t) { return new Set(String(t).toLowerCase().split(/[^\p{L}\p{N}]+/u).filter((w) => w.length > 2)); }
export function textSimilarity(a, b) {
  const A = wordSet(a), B = wordSet(b);
  if (!A.size && !B.size) return 1;
  let inter = 0; for (const w of A) if (B.has(w)) inter++;
  return inter / (A.size + B.size - inter || 1);
}

/** Источник для извлечения кадров: оригинал → mp4 → HLS. */
export async function videoSource(video) {
  if (video.original_path && (await exists(storage.abs(video.original_path)))) return storage.abs(video.original_path);
  if (video.mp4_path && (await exists(storage.abs(video.mp4_path)))) return storage.abs(video.mp4_path);
  if (video.hls_path && (await exists(storage.abs(video.hls_path)))) return storage.abs(video.hls_path);
  throw Object.assign(new Error('Нет файла видео для обработки'), { noRetry: true });
}

/** Задание воркера: кадры каждые N секунд → tesseract → video_screen_text + videos.screen_text. */
export async function runOcr(job, ctx = {}) {
  const { log, signal, heartbeat } = ctx;
  const s = await loadSettings(true);
  const video = await one('SELECT * FROM videos WHERE id = $1 AND deleted_at IS NULL', [job.video_id || job.payload.videoId]);
  if (!video) throw Object.assign(new Error('Видео не найдено'), { noRetry: true });
  const avail = await ocrAvailable();
  if (!avail.available) { await query(`UPDATE videos SET ocr_status = 'failed' WHERE id = $1`, [video.id]); throw Object.assign(new Error('tesseract не установлен на сервере'), { noRetry: true }); }
  const duration = Number(video.duration) || 0;
  const maxMin = Number(s['ocr.max_duration_min']) || 240;
  if (duration > maxMin * 60) { await query(`UPDATE videos SET ocr_status = 'failed' WHERE id = $1`, [video.id]); throw Object.assign(new Error(`Видео длиннее лимита OCR (${maxMin} мин)`), { noRetry: true }); }
  await query(`UPDATE videos SET ocr_status = 'processing' WHERE id = $1`, [video.id]);
  const interval = Math.max(2, Math.min(120, Number(job.payload.intervalSec) || Number(s['ocr.interval_sec']) || 10));
  const langs = pickLanguages(job.payload.languages || s['ocr.languages'], avail.languages);
  const src = await videoSource(video);
  const dir = path.join(path.dirname(storage.uploadTmp('x')), `ocr-${video.id}`);
  await removeDir(dir).catch(() => {});
  await ensureDir(dir);
  try {
    await runFfmpeg(['-i', src, '-vf', `fps=1/${interval},scale='min(1600,iw)':-2`, '-q:v', '3', path.join(dir, 'f%06d.jpg')], { duration, signal, label: 'ocr-frames', onProgress: (p) => heartbeat?.(Math.round(p * 0.3), 'frames').catch(() => {}) });
    const files = (await fsp.readdir(dir)).filter((f) => f.endsWith('.jpg')).sort();
    const rows = [];
    let prev = ''; let failed = 0;
    for (let i = 0; i < files.length; i++) {
      if (signal?.aborted) throw new Error('Задание отменено');
      const t = i * interval;
      let text = '';
      try {
        const { stdout } = await execFileP(TESSERACT, [path.join(dir, files[i]), 'stdout', '-l', langs, '--psm', '3'], { maxBuffer: 4 * 1024 * 1024, env: { ...process.env, OMP_THREAD_LIMIT: '2' } });
        text = cleanOcrText(stdout);
      } catch (e) { failed++; log?.warn({ err: e.message, frame: files[i] }, 'ocr frame failed'); }
      if (text && textSimilarity(text, prev) < 0.85) { rows.push({ t, text }); prev = text; }
      if (i % 5 === 0) await heartbeat?.(30 + Math.round(((i + 1) / files.length) * 65), 'ocr').catch(() => {});
    }
    // Все кадры упали (нет языкового пакета, нет прав) — это ошибка задания, а не «пустой» результат
    if (files.length && failed === files.length) throw Object.assign(new Error(`Не удалось распознать ни один кадр (${failed} шт.) — проверьте установку tesseract и языковые пакеты`), { noRetry: true });
    await query('DELETE FROM video_screen_text WHERE video_id = $1', [video.id]);
    for (const r of rows) await query('INSERT INTO video_screen_text(video_id, t, text) VALUES ($1,$2,$3)', [video.id, r.t, r.text]);
    // Сводный текст для поиска: уникальные строки
    const seen = new Set(); const lines = [];
    for (const r of rows) for (const l of r.text.split('\n')) { const k = l.toLowerCase(); if (!seen.has(k)) { seen.add(k); lines.push(l); } }
    const summary = lines.join('\n').slice(0, 100000);
    await query(`UPDATE videos SET screen_text = $2, ocr_status = 'done', ocr_at = now() WHERE id = $1`, [video.id, summary || null]);
    return { frames: files.length, texts: rows.length, languages: langs };
  } catch (e) {
    await query(`UPDATE videos SET ocr_status = 'failed' WHERE id = $1`, [video.id]);
    throw e;
  } finally {
    await removeDir(dir).catch(() => {});
  }
}

/** Текст на экране для страницы просмотра (с поиском). */
export async function screenTextOf(videoId, q = '') {
  const rows = await many('SELECT t, text FROM video_screen_text WHERE video_id = $1 ORDER BY t', [videoId]);
  const needle = String(q || '').trim().toLowerCase();
  const out = rows.map((r) => ({ t: Number(r.t), text: r.text }));
  return needle ? out.filter((r) => r.text.toLowerCase().includes(needle)) : out;
}

export const ocrBinary = TESSERACT;
export const ocrConfig = { baseUrl: config.baseUrl };
