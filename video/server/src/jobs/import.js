// Задание: импорт видео по ссылке. Прямая ссылка на файл скачивается через fetch;
// страницы видеосервисов — через yt-dlp, если он установлен на сервере.
import path from 'node:path';
import fs from 'node:fs';
import fsp from 'node:fs/promises';
import { spawn } from 'node:child_process';
import { pipeline } from 'node:stream/promises';
import { Readable, Transform } from 'node:stream';
import { one, query, publish } from '../db.js';
import { storage, ensureDir, removeFile, removeDir } from '../lib/storage.js';
import { loadSettings } from '../lib/settings.js';
import { notify } from '../lib/notify.js';
import { finalizeFile } from '../routes/uploads.js';
import { extOf, safeFilename } from '../lib/util.js';
import { config } from '../config.js';
import { assertOutboundUrl } from '../lib/net.js';

const VIDEO_MIME = /^(video\/|application\/(octet-stream|x-matroska|mp4|vnd\.apple\.mpegurl))/i;

function which(cmd) {
  return new Promise((resolve) => {
    const p = spawn('sh', ['-c', `command -v ${cmd}`], { stdio: ['ignore', 'pipe', 'ignore'] });
    let out = '';
    p.stdout.on('data', (d) => { out += d; });
    p.on('close', (code) => resolve(code === 0 ? out.trim() : null));
    p.on('error', () => resolve(null));
  });
}

async function fail(video, message) {
  await query(`UPDATE videos SET status = 'failed', processing_error = $2, processing_stage = NULL WHERE id = $1`, [video.id, String(message).slice(0, 2000)]);
  await publish({ type: 'video.failed', videoId: video.id, shortId: video.short_id, ownerId: video.owner_id, status: 'failed', error: message });
  await notify(video.owner_id, { type: 'video_failed', title: 'Не удалось импортировать видео', body: `${video.title}: ${String(message).slice(0, 200)}`, link: `/studio/videos/${video.id}`, data: { videoId: video.id } });
}

/** Скачивание прямой ссылки в файл с ограничением размера и отчётом о прогрессе. */
async function downloadDirect(url, dest, { maxBytes, signal, onProgress, checkHost = null }) {
  // Переходы отслеживаем вручную: с redirect:'follow' проверка адреса обходилась
  // ответом 302 на внутренний адрес (loopback, 169.254.169.254 и прочее).
  let current = url;
  let res = null;
  for (let hop = 0; hop < 5; hop++) {
    if (checkHost) await checkHost(current);
    res = await fetch(current, { signal, redirect: 'manual', headers: { 'user-agent': 'CorpVideo/1.1 (+import)' } });
    if (res.status >= 300 && res.status < 400 && res.headers.get('location')) {
      await res.body?.cancel().catch(() => {});
      current = new URL(res.headers.get('location'), current).toString();
      continue;
    }
    break;
  }
  if (!res.ok) throw new Error(`Сервер ответил ${res.status}`);
  const type = String(res.headers.get('content-type') || '').split(';')[0].trim();
  const len = Number(res.headers.get('content-length') || 0);
  if (maxBytes && len > maxBytes) { await res.body?.cancel().catch(() => {}); throw new Error(`Файл больше допустимого размера (${Math.round(len / 1048576)} МБ)`); }
  if (type && !VIDEO_MIME.test(type)) { await res.body?.cancel().catch(() => {}); return { type, isPage: /text\/html/i.test(type) }; }
  let got = 0;
  const counter = new Transform({
    transform(chunk, enc, cb) {
      got += chunk.length;
      if (maxBytes && got > maxBytes) return cb(new Error('Файл больше допустимого размера'));
      if (onProgress && len) onProgress(got / len);
      cb(null, chunk);
    },
  });
  await pipeline(Readable.fromWeb(res.body), counter, fs.createWriteStream(dest));
  return { type, bytes: got, filenameHeader: (res.headers.get('content-disposition') || '').match(/filename\*?=(?:UTF-8'')?"?([^";]+)/i)?.[1] };
}

/** Скачивание через yt-dlp (страницы видеосервисов, m3u8 и т.п.). */
function downloadWithYtDlp(bin, url, outTemplate, { signal, onProgress, log }) {
  return new Promise((resolve, reject) => {
    const args = ['--no-playlist', '--no-warnings', '--newline', '-f', 'bv*[height<=1080]+ba/b[height<=1080]/b', '--merge-output-format', 'mp4', '-o', outTemplate, url];
    const p = spawn(bin, args, { stdio: ['ignore', 'pipe', 'pipe'] });
    let err = '';
    p.stdout.on('data', (d) => {
      const m = String(d).match(/\[download\]\s+([\d.]+)%/);
      if (m && onProgress) onProgress(Number(m[1]) / 100);
    });
    p.stderr.on('data', (d) => { err += d; if (err.length > 4000) err = err.slice(-4000); });
    p.on('error', reject);
    p.on('close', (code) => (code === 0 ? resolve() : reject(new Error('yt-dlp: ' + (err.trim().split('\n').pop() || `код ${code}`)))));
    if (signal) signal.addEventListener('abort', () => p.kill('SIGKILL'), { once: true });
  });
}

export async function runImportUrl(job, ctx) {
  const { log, signal, heartbeat } = ctx;
  const { url, priority = 0 } = job.payload;
  const video = await one('SELECT * FROM videos WHERE id = $1 AND deleted_at IS NULL', [job.video_id || job.payload.videoId]);
  if (!video) throw Object.assign(new Error('Видео не найдено'), { noRetry: true });
  const s = await loadSettings(true);
  const maxBytes = (Number(s['upload.max_size_mb']) || 0) * 1024 * 1024;
  const tmpDir = path.join(storage.videoDir(video.id), 'import');
  await ensureDir(tmpDir);
  await query(`UPDATE videos SET processing_stage = 'import', processing_progress = 0 WHERE id = $1`, [video.id]);
  let lastBeat = 0;
  const progress = (frac) => {
    if (Date.now() - lastBeat < 1500) return;
    lastBeat = Date.now();
    const p = Math.round(frac * 100);
    query('UPDATE videos SET processing_progress = $2 WHERE id = $1', [video.id, p]).catch(() => {});
    publish({ type: 'video.progress', videoId: video.id, shortId: video.short_id, ownerId: video.owner_id, progress: p, stage: 'import', status: 'uploading' }).catch(() => {});
    heartbeat(p, 'import').catch(() => {});
  };
  try {
    let file = null;
    let filename = null;
    const guess = safeFilename(decodeURIComponent((new URL(url).pathname.split('/').pop() || '')) || 'import.mp4');
    const direct = path.join(tmpDir, `direct.${extOf(guess) || 'mp4'}`);
    let r = null;
    try {
      r = await downloadDirect(url, direct, {
        maxBytes, signal, onProgress: progress,
        // На каждом переходе заново: редирект на внутренний адрес — обход проверки при постановке задания
        checkHost: config.importAllowLocal ? null : (u) => assertOutboundUrl(u),
      });
    } catch (e) {
      log.warn({ err: e.message }, 'прямое скачивание не удалось');
      await removeFile(direct);
      if (/больше допустимого/.test(e.message)) throw Object.assign(e, { noRetry: true });
    }
    if (r && r.bytes) {
      file = direct;
      filename = r.filenameHeader ? safeFilename(r.filenameHeader) : guess;
      if (!extOf(filename)) filename += '.mp4';
    } else {
      await removeFile(direct);
      const bin = await which('yt-dlp');
      if (!bin) throw Object.assign(new Error(r?.isPage ? 'По ссылке страница, а не видеофайл. Для импорта со страниц видеосервисов установите yt-dlp на сервер.' : 'Не удалось скачать файл по ссылке'), { noRetry: true });
      log.info('скачивание через yt-dlp');
      await downloadWithYtDlp(bin, url, path.join(tmpDir, 'ytdlp.%(ext)s'), { signal, onProgress: progress, log });
      const files = (await fsp.readdir(tmpDir)).filter((f) => f.startsWith('ytdlp.') && !f.endsWith('.part'));
      if (!files.length) throw Object.assign(new Error('yt-dlp не вернул файл'), { noRetry: true });
      file = path.join(tmpDir, files[0]);
      const st = await fsp.stat(file);
      if (maxBytes && st.size > maxBytes) throw Object.assign(new Error('Файл больше допустимого размера'), { noRetry: true });
      filename = `import.${extOf(files[0]) || 'mp4'}`;
    }
    const allowed = (s['upload.allowed_extensions'] || []).map((e) => String(e).toLowerCase());
    if (!allowed.includes(extOf(filename))) filename = filename.replace(/\.[^.]*$/, '') + '.mp4';
    const st = await fsp.stat(file);
    await query('UPDATE videos SET original_filename = $2, original_size = $3 WHERE id = $1', [video.id, filename, st.size]);
    await finalizeFile({ video: { ...video, original_path: null }, srcPath: file, filename, priority });
    await removeDir(tmpDir).catch(() => {});
    return { bytes: st.size, filename };
  } catch (e) {
    await removeDir(tmpDir).catch(() => {});
    if (e.noRetry || job.attempts >= (job.max_attempts || 1)) await fail(video, e.message);
    throw e;
  }
}
