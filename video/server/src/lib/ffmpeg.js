// Работа с ffmpeg/ffprobe: анализ, транскодирование в HLS-лестницу, миниатюры, раскадровка, аудио, картинки.
import { spawn, execFile } from 'node:child_process';
import { promisify } from 'node:util';
import fsp from 'node:fs/promises';
import path from 'node:path';
import { config } from '../config.js';
import { ensureDir } from './storage.js';

const execFileP = promisify(execFile);

export const QUALITY_PRESETS = {
  2160: { bitrate: 12000, maxrate: 14000, audio: 192, level: '5.1' },
  1440: { bitrate: 8000, maxrate: 9500, audio: 192, level: '5.0' },
  1080: { bitrate: 5000, maxrate: 6000, audio: 160, level: '4.0' },
  720: { bitrate: 2800, maxrate: 3400, audio: 128, level: '3.1' },
  480: { bitrate: 1400, maxrate: 1700, audio: 96, level: '3.0' },
  360: { bitrate: 800, maxrate: 1000, audio: 96, level: '3.0' },
  240: { bitrate: 400, maxrate: 500, audio: 64, level: '2.1' },
};

/** Анализ файла. */
export async function probe(file) {
  const { stdout } = await execFileP(config.ffprobePath, [
    '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', file,
  ], { maxBuffer: 16 * 1024 * 1024 });
  const info = JSON.parse(stdout);
  const v = (info.streams || []).find((s) => s.codec_type === 'video' && s.disposition?.attached_pic !== 1);
  const a = (info.streams || []).find((s) => s.codec_type === 'audio');
  if (!v) throw new Error('В файле нет видеопотока');
  let fps = 0;
  if (v.avg_frame_rate && v.avg_frame_rate !== '0/0') {
    const [n, d] = v.avg_frame_rate.split('/').map(Number);
    if (d) fps = n / d;
  }
  if (!fps && v.r_frame_rate) {
    const [n, d] = v.r_frame_rate.split('/').map(Number);
    if (d) fps = n / d;
  }
  let width = v.width, height = v.height;
  const rotation = getRotation(v);
  if (rotation === 90 || rotation === 270) [width, height] = [height, width];
  const duration = parseFloat(info.format?.duration || v.duration || 0) || 0;
  return {
    duration,
    width, height, fps: Math.round(fps * 1000) / 1000,
    bitrate: parseInt(info.format?.bit_rate || 0, 10) || 0,
    codec: v.codec_name,
    audioCodec: a?.codec_name || null,
    hasAudio: !!a,
    size: parseInt(info.format?.size || 0, 10) || 0,
    formatName: info.format?.format_name,
    rotation,
  };
}

function getRotation(stream) {
  const t = stream.tags?.rotate;
  if (t) return ((parseInt(t, 10) % 360) + 360) % 360;
  for (const sd of stream.side_data_list || []) {
    if (sd.rotation !== undefined) return ((Math.round(-sd.rotation) % 360) + 360) % 360;
  }
  return 0;
}

/** Выбор лестницы качеств для исходника. */
export function chooseLadder(meta, enabledQualities) {
  const shortSide = Math.min(meta.width, meta.height);
  const enabled = [...new Set((enabledQualities || []).map(Number).filter((q) => QUALITY_PRESETS[q]))].sort((a, b) => b - a);
  let ladder = enabled.filter((q) => q <= shortSide + 8); // небольшой допуск (напр. 1088)
  if (!ladder.length) ladder = [enabled[enabled.length - 1] || 360];
  return ladder;
}

function parseProgressLine(line, state) {
  const eq = line.indexOf('=');
  if (eq < 0) return;
  const k = line.slice(0, eq).trim(), v = line.slice(eq + 1).trim();
  if (k === 'out_time_us' || k === 'out_time_ms') state.outTime = parseInt(v, 10) / 1e6;
  else if (k === 'speed') state.speed = v;
}

/** Запуск ffmpeg с отслеживанием прогресса. */
export function runFfmpeg(args, { duration = 0, onProgress, signal, cwd, label = 'ffmpeg', timeoutMs = 0 } = {}) {
  return new Promise((resolve, reject) => {
    // Сигнал мог сработать ещё до запуска (задание отменили, пока оно ждало): тогда событие abort
    // уже не придёт, и ffmpeg проработал бы до конца. Не запускаем вовсе.
    if (signal?.aborted) { reject(new Error(`${label}: отменено`)); return; }
    const fullArgs = ['-hide_banner', '-nostdin', '-y', '-loglevel', 'error', '-progress', 'pipe:1', '-nostats', ...args];
    const child = spawn(config.ffmpegPath, fullArgs, { cwd, stdio: ['ignore', 'pipe', 'pipe'] });
    let stderr = '';
    let buf = '';
    const state = { outTime: 0, speed: '' };
    let lastPct = -1;
    child.stdout.on('data', (d) => {
      buf += d.toString();
      let idx;
      while ((idx = buf.indexOf('\n')) >= 0) {
        const line = buf.slice(0, idx); buf = buf.slice(idx + 1);
        parseProgressLine(line, state);
        if (line.startsWith('progress=') && duration > 0 && onProgress) {
          const pct = Math.min(100, Math.max(0, Math.round((state.outTime / duration) * 100)));
          if (pct !== lastPct) { lastPct = pct; try { onProgress(pct, state); } catch { /* ignore */ } }
        }
      }
    });
    child.stderr.on('data', (d) => { stderr += d.toString(); if (stderr.length > 200000) stderr = stderr.slice(-100000); });
    const onAbort = () => { try { child.kill('SIGKILL'); } catch { /* ignore */ } };
    if (signal) signal.addEventListener('abort', onAbort, { once: true });
    // Предел времени для вызовов из HTTP-запросов: зависший ffmpeg не должен жить вечно
    let timedOut = false;
    const timer = timeoutMs > 0 ? setTimeout(() => { timedOut = true; onAbort(); }, timeoutMs) : null;
    child.on('error', (e) => { if (timer) clearTimeout(timer); reject(new Error(`${label}: не удалось запустить ffmpeg: ${e.message}`)); });
    child.on('close', (code) => {
      if (timer) clearTimeout(timer);
      if (signal) signal.removeEventListener('abort', onAbort);
      if (timedOut) { reject(new Error(`${label}: ffmpeg не уложился в ${Math.round(timeoutMs / 1000)} с`)); return; }
      if (code === 0) resolve({ stderr });
      else reject(new Error(`${label}: ffmpeg завершился с кодом ${code}: ${stderr.trim().split('\n').slice(-5).join(' | ')}`));
    });
  });
}

/** Параметры кодера для видеопотока с индексом i (спецификаторы :v:i). */
function encoderArgs(hwaccel, preset, crf, q, threads, i) {
  const p = QUALITY_PRESETS[q];
  const s = `:v:${i}`;
  const common = [`-g${s}`, '120', `-keyint_min${s}`, '48', `-sc_threshold${s}`, '0', `-pix_fmt${s}`, 'yuv420p'];
  switch (hwaccel) {
    case 'nvenc':
      return [`-c${s}`, 'h264_nvenc', `-preset${s}`, 'p4', `-rc${s}`, 'vbr', `-cq${s}`, String(crf), `-b${s}`, `${p.bitrate}k`, `-maxrate${s}`, `${p.maxrate}k`, `-bufsize${s}`, `${p.maxrate * 2}k`, `-profile${s}`, 'high', ...common];
    case 'qsv':
      return [`-c${s}`, 'h264_qsv', `-preset${s}`, 'medium', `-global_quality${s}`, String(crf), `-b${s}`, `${p.bitrate}k`, `-maxrate${s}`, `${p.maxrate}k`, `-profile${s}`, 'high', ...common];
    case 'vaapi':
      return [`-c${s}`, 'h264_vaapi', `-qp${s}`, String(crf), `-b${s}`, `${p.bitrate}k`, `-maxrate${s}`, `${p.maxrate}k`, `-profile${s}`, 'high', `-g${s}`, '120', `-sc_threshold${s}`, '0'];
    default:
      return [`-c${s}`, 'libx264', `-preset${s}`, preset, `-crf${s}`, String(crf), `-maxrate${s}`, `${p.maxrate}k`, `-bufsize${s}`, `${p.maxrate * 2}k`, `-profile${s}`, 'high', `-level${s}`, p.level, ...(threads ? [`-threads${s}`, String(threads)] : []), ...common];
  }
}

/**
 * Транскодирование в HLS-лестницу одной командой (декодирование один раз).
 * Возвращает список вариантов качества.
 */
export async function transcodeHls(input, outDir, meta, opts = {}) {
  const {
    qualities = [1080, 720, 480, 360], preset = 'veryfast', crf = 23, segmentSeconds = 6,
    hwaccel = 'none', audioBitrate = 128, onProgress, signal,
  } = opts;
  await ensureDir(outDir);
  const ladder = chooseLadder(meta, qualities);
  const vertical = meta.height > meta.width;
  const filters = [];
  const splitLabels = ladder.map((_, i) => `[v${i}]`).join('');
  filters.push(`[0:v]split=${ladder.length}${splitLabels}`);
  ladder.forEach((q, i) => {
    const scale = vertical ? `scale=${q}:-2` : `scale=-2:${q}`;
    const hw = hwaccel === 'vaapi' ? ',format=nv12,hwupload' : '';
    filters.push(`[v${i}]${scale}:flags=lanczos${hw}[v${i}o]`);
  });
  const varStream = ladder.map((q, i) => (meta.hasAudio ? `v:${i},a:${i},name:${q}p` : `v:${i},name:${q}p`));
  // Параметры кодеров задаём по индексу выходного потока (:v:i / :a:i)
  const finalArgs = [];
  if (hwaccel === 'vaapi') finalArgs.push('-vaapi_device', '/dev/dri/renderD128');
  finalArgs.push('-i', input, '-filter_complex', filters.join(';'));
  ladder.forEach((q, i) => {
    finalArgs.push('-map', `[v${i}o]`);
    if (meta.hasAudio) finalArgs.push('-map', '0:a:0');
  });
  ladder.forEach((q, i) => {
    finalArgs.push(...encoderArgs(hwaccel, preset, crf, q, config.ffmpegThreads, i));
    if (meta.hasAudio) {
      const ab = Math.min(audioBitrate, QUALITY_PRESETS[q].audio);
      finalArgs.push(`-c:a:${i}`, 'aac', `-b:a:${i}`, `${ab}k`, `-ac:a:${i}`, '2', `-ar:a:${i}`, '48000');
    }
  });
  finalArgs.push(
    '-force_key_frames', `expr:gte(t,n_forced*${segmentSeconds})`,
    '-f', 'hls',
    '-hls_time', String(segmentSeconds),
    '-hls_playlist_type', 'vod',
    '-hls_flags', 'independent_segments',
    '-hls_segment_type', 'mpegts',
    '-hls_list_size', '0',
    '-master_pl_name', 'master.m3u8',
    '-hls_segment_filename', path.join(outDir, '%v', 'seg_%05d.ts'),
    '-var_stream_map', varStream.join(' '),
    path.join(outDir, '%v', 'index.m3u8'),
  );
  for (const q of ladder) await ensureDir(path.join(outDir, `${q}p`));
  await runFfmpeg(finalArgs, { duration: meta.duration, onProgress, signal, label: 'HLS' });

  // Собираем сведения о вариантах
  const renditions = [];
  for (const q of ladder) {
    const dir = path.join(outDir, `${q}p`);
    let bytes = 0;
    for (const f of await fsp.readdir(dir)) { try { bytes += (await fsp.stat(path.join(dir, f))).size; } catch { /* ignore */ } }
    const ratio = meta.width / meta.height;
    const height = vertical ? Math.round(q / ratio / 2) * 2 : q;
    const width = vertical ? q : Math.round(q * ratio / 2) * 2;
    renditions.push({
      label: `${q}p`, width, height,
      bandwidth: (QUALITY_PRESETS[q].maxrate + QUALITY_PRESETS[q].audio) * 1000,
      path: `${q}p/index.m3u8`, bytes,
    });
  }
  return { renditions, ladder };
}

/** Прогрессивный MP4 (фолбэк/скачивание). */
export async function makeMp4(input, output, meta, { maxHeight = 720, preset = 'veryfast', crf = 23, onProgress, signal } = {}) {
  const vertical = meta.height > meta.width;
  const shortSide = Math.min(meta.width, meta.height);
  const target = Math.min(maxHeight, shortSide);
  const scale = vertical ? `scale=${target}:-2` : `scale=-2:${target}`;
  const args = ['-i', input, '-vf', scale, '-c:v', 'libx264', '-preset', preset, '-crf', String(crf), '-pix_fmt', 'yuv420p', '-profile:v', 'high', '-movflags', '+faststart'];
  if (meta.hasAudio) args.push('-c:a', 'aac', '-b:a', '128k', '-ac', '2'); else args.push('-an');
  args.push(output);
  await runFfmpeg(args, { duration: meta.duration, onProgress, signal, label: 'MP4' });
  return (await fsp.stat(output)).size;
}

/** Кадр в момент t → jpg (масштаб под 16:9 с полями). */
export async function frameAt(input, t, output, { width = 1280, height = 720 } = {}) {
  await ensureDir(path.dirname(output));
  const vf = `scale=${width}:${height}:force_original_aspect_ratio=decrease,pad=${width}:${height}:(ow-iw)/2:(oh-ih)/2:black`;
  await runFfmpeg(['-ss', String(Math.max(0, t)), '-i', input, '-frames:v', '1', '-q:v', '3', '-vf', vf, output], { label: 'frame', timeoutMs: 120000 });
  return output;
}

/** Автоматические кандидаты миниатюр. */
export async function makeThumbnails(input, duration, outDir) {
  await ensureDir(outDir);
  const points = duration > 3 ? [0.15, 0.45, 0.75].map((p) => p * duration) : [0, 0, 0];
  const files = [];
  for (let i = 0; i < points.length; i++) {
    const out = path.join(outDir, `auto_${i + 1}.jpg`);
    try {
      await frameAt(input, points[i], out);
      files.push(out);
    } catch { /* пропускаем */ }
  }
  return files;
}

/** Раскадровка: спрайты 160×90 в сетке 10×10 + VTT-карта. */
export async function makeStoryboard(input, duration, outDir, { cols = 10, rows = 10, tw = 240, th = 135 } = {}) {
  await ensureDir(outDir);
  const interval = Math.max(1, Math.ceil(duration / 200)); // ≈200 кадров максимум
  const vf = `fps=1/${interval},scale=${tw}:${th}:force_original_aspect_ratio=decrease,pad=${tw}:${th}:(ow-iw)/2:(oh-ih)/2:black,tile=${cols}x${rows}`;
  await runFfmpeg(['-i', input, '-vf', vf, '-q:v', '5', path.join(outDir, 'sb_%d.jpg')], { duration, label: 'storyboard' });
  const frames = Math.ceil(duration / interval);
  const perSheet = cols * rows;
  let vtt = 'WEBVTT\n\n';
  const ts = (s) => {
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${sec.toFixed(3).padStart(6, '0')}`;
  };
  for (let i = 0; i < frames; i++) {
    const sheet = Math.floor(i / perSheet) + 1;
    const idx = i % perSheet;
    const x = (idx % cols) * tw, y = Math.floor(idx / cols) * th;
    const start = i * interval, end = Math.min(duration, (i + 1) * interval);
    vtt += `${ts(start)} --> ${ts(end)}\nsb_${sheet}.jpg#xywh=${x},${y},${tw},${th}\n\n`;
  }
  await fsp.writeFile(path.join(outDir, 'storyboard.vtt'), vtt);
  return { interval, frames, sheets: Math.ceil(frames / perSheet), tw, th, cols, rows };
}

/** Аудио для распознавания речи: mp3 16 кГц моно. */
export async function extractAudio(input, output, { signal } = {}) {
  await ensureDir(path.dirname(output));
  await runFfmpeg(['-i', input, '-vn', '-ac', '1', '-ar', '16000', '-b:a', '48k', output], { signal, label: 'audio' });
  return output;
}

/** Масштабирование картинки (аватары, обложки, миниатюры). mode: cover|contain */
export async function resizeImage(input, output, { width, height, mode = 'cover', quality = 3 } = {}) {
  await ensureDir(path.dirname(output));
  const vf = mode === 'cover'
    ? `scale=${width}:${height}:force_original_aspect_ratio=increase,crop=${width}:${height}`
    : `scale=${width}:${height}:force_original_aspect_ratio=decrease,pad=${width}:${height}:(ow-iw)/2:(oh-ih)/2:black`;
  await runFfmpeg(['-i', input, '-frames:v', '1', '-vf', vf, '-q:v', String(quality), output], { label: 'image', timeoutMs: 60000 });
  return output;
}

/** Тестовое видео (для проверок и тестов). */
export async function createTestVideo(output, { seconds = 6, width = 640, height = 360, audio = true } = {}) {
  await ensureDir(path.dirname(output));
  const args = ['-f', 'lavfi', '-i', `testsrc2=size=${width}x${height}:rate=25:duration=${seconds}`];
  if (audio) args.push('-f', 'lavfi', '-i', `sine=frequency=440:duration=${seconds}`);
  args.push('-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p');
  if (audio) args.push('-c:a', 'aac', '-shortest');
  args.push(output);
  await runFfmpeg(args, { label: 'testvideo' });
  return output;
}

export async function ffmpegVersion() {
  try {
    const { stdout } = await execFileP(config.ffmpegPath, ['-version']);
    return stdout.split('\n')[0].replace(/^ffmpeg version\s+/, '').split(' ')[0];
  } catch { return null; }
}

export async function listEncoders() {
  try {
    const { stdout } = await execFileP(config.ffmpegPath, ['-hide_banner', '-encoders']);
    return { nvenc: /h264_nvenc/.test(stdout), vaapi: /h264_vaapi/.test(stdout), qsv: /h264_qsv/.test(stdout), x264: /libx264/.test(stdout) };
  } catch { return { nvenc: false, vaapi: false, qsv: false, x264: false }; }
}

// --- Кадр в память для HTTP-ответа (предпросмотр в редакторах) -----------------------------------
// Выполняется прямо в процессе API, поэтому ограничен и по времени, и по числу одновременных запусков:
// быстрая перемотка по таймлайну не должна поднимать десятки ffmpeg.
const slots = { busy: 0, max: Math.max(1, Number(process.env.PREVIEW_CONCURRENCY) || 3), waiters: [] };

export async function withPreviewSlot(fn) {
  if (slots.busy >= slots.max) {
    if (slots.waiters.length >= 24) throw Object.assign(new Error('Сервер занят подготовкой кадров — повторите через несколько секунд'), { statusCode: 429 });
    await new Promise((resolve) => slots.waiters.push(resolve));   // слот передаётся нам освободившимся
  } else slots.busy++;
  try { return await fn(); } finally {
    const next = slots.waiters.shift();
    if (next) next(); else slots.busy--;
  }
}

/** Запустить ffmpeg с выводом в pipe:1 и вернуть результат буфером. */
export function ffmpegToBuffer(args, { timeoutMs = 20000, maxBytes = 25 * 1024 * 1024, label = 'кадр' } = {}) {
  return new Promise((resolve, reject) => {
    const full = args[0] === '-hide_banner' ? args : ['-hide_banner', '-nostdin', '-loglevel', 'error', ...args];
    const child = spawn(config.ffmpegPath, full, { stdio: ['ignore', 'pipe', 'pipe'] });
    const chunks = []; let size = 0; let err = ''; let why = '';
    const kill = (reason) => { why = why || reason; try { child.kill('SIGKILL'); } catch { /* уже завершён */ } };
    const timer = setTimeout(() => kill(`не уложился в ${Math.round(timeoutMs / 1000)} с`), timeoutMs);
    child.stdout.on('data', (d) => { size += d.length; if (size > maxBytes) kill('слишком большой результат'); else chunks.push(d); });
    child.stderr.on('data', (d) => { if (err.length < 4000) err += d.toString(); });
    child.on('error', (e) => { clearTimeout(timer); reject(new Error(`${label}: ${e.message}`)); });
    child.on('close', () => {
      clearTimeout(timer);
      if (why) { reject(new Error(`${label}: ${why}`)); return; }
      const b = Buffer.concat(chunks);
      if (b.length) resolve(b); else reject(new Error(err.trim().split('\n').pop() || `${label} не получен`));
    });
  });
}
